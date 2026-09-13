<?php

namespace App\Http\Controllers\Api\Examination;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Examination\ReorderExamQuestionsRequest;
use App\Http\Requests\Api\Examination\StoreExamQuestionRequest;
use App\Http\Requests\Api\Examination\UpdateExamQuestionRequest;
use App\Http\Resources\Examination\ExamQuestionResource;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamQuestion;
use App\Models\Examination\QuestionBank;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Exam composition management (Phase 2D).
 *
 * `exam_questions` is the explicit composition of an exam. Composition rows
 * are protected by the exam lifecycle: they are only mutable while the exam is
 * in `draft`. Once an exam is published/ongoing/completed/archived the
 * composition is frozen (attempts may already reference its questions).
 *
 * Ownership context always comes from the route: every mutation resolves the
 * ExamQuestion within the `{exam}` from the URL (404 on mismatch -> no IDOR).
 */
class ExamQuestionController extends Controller
{
    private const MUTABLE_STATUSES = ['draft'];

    public function index(Request $request, int $exam): JsonResponse
    {
        $examModel = Exam::find($exam);

        if (! $examModel) {
            return $this->notFound();
        }

        $compositions = ExamQuestion::with('question')
            ->where('exam_id', $exam)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Exam questions retrieved successfully',
            'data' => ExamQuestionResource::collection($compositions),
        ]);
    }

    public function store(StoreExamQuestionRequest $request, int $exam): JsonResponse
    {
        [$examModel, $guard] = $this->resolveExamForMutation($exam);
        if ($guard !== null) {
            return $guard;
        }

        $questionId = (int) $request->validated()['question_id'];

        $question = QuestionBank::find($questionId);
        if (! $question) {
            return $this->unprocessable('Question not found or not available for composition.');
        }

        if ((int) $question->subject_id !== (int) $examModel->subject_id) {
            return $this->unprocessable('Question subject does not match the exam subject.');
        }

        $alreadyComposed = ExamQuestion::where('exam_id', $exam)
            ->where('question_id', $questionId)
            ->exists();
        if ($alreadyComposed) {
            return $this->unprocessable('This question is already part of the exam.');
        }

        $position = $request->input('position');
        if ($position === null) {
            $position = (int) ExamQuestion::where('exam_id', $exam)->max('position') + 1;
        }
        $position = max(1, (int) $position);

        if ($this->positionTaken($exam, $position)) {
            return $this->unprocessable("Position {$position} is already used by another question.");
        }

        try {
            $composition = DB::transaction(fn () => ExamQuestion::create([
                'exam_id' => $exam,
                'question_id' => $questionId,
                'position' => $position,
                'points' => $request->input('points') ?? (int) $question->points,
            ]));
        } catch (QueryException $e) {
            // Final safety net: unique (exam_id, question_id) at the DB level.
            if ($e->getCode() === '23000') {
                return $this->unprocessable('This question is already part of the exam.');
            }
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Question added to exam successfully',
            'data' => new ExamQuestionResource($composition->load('question')),
        ], 201);
    }

    public function update(UpdateExamQuestionRequest $request, int $exam, int $examQuestion): JsonResponse
    {
        [$examModel, $guard] = $this->resolveExamForMutation($exam);
        if ($guard !== null) {
            return $guard;
        }

        $composition = ExamQuestion::where('id', $examQuestion)->where('exam_id', $exam)->first();
        if (! $composition) {
            return $this->notFound();
        }

        $validated = $request->validated();

        if (array_key_exists('position', $validated)
            && (int) $validated['position'] !== $composition->position
            && $this->positionTaken($exam, (int) $validated['position'], $composition->id)) {
            return $this->unprocessable('Position is already used by another question.');
        }

        $composition->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Exam question updated successfully',
            'data' => new ExamQuestionResource($composition->load('question')),
        ]);
    }

    public function reorder(ReorderExamQuestionsRequest $request, int $exam): JsonResponse
    {
        [$examModel, $guard] = $this->resolveExamForMutation($exam);
        if ($guard !== null) {
            return $guard;
        }

        $ids = array_unique(array_map('intval', $request->validated()['ordered']));

        $compositions = ExamQuestion::where('exam_id', $exam)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($compositions->count() !== count($ids)) {
            return $this->unprocessable('One or more exam questions do not belong to this exam.');
        }

        $ordered = [];
        DB::transaction(function () use ($ids, $compositions, &$ordered) {
            foreach (array_values($ids) as $index => $id) {
                $compositions[$id]->update(['position' => $index + 1]);
            }
            $ordered = ExamQuestion::where('exam_id', $compositions->first()->exam_id)
                ->with('question')
                ->orderBy('position')
                ->get();
        });

        return response()->json([
            'success' => true,
            'message' => 'Exam questions reordered successfully',
            'data' => ExamQuestionResource::collection($ordered),
        ]);
    }

    public function destroy(Request $request, int $exam, int $examQuestion): JsonResponse
    {
        [$examModel, $guard] = $this->resolveExamForMutation($exam);
        if ($guard !== null) {
            return $guard;
        }

        $composition = ExamQuestion::where('id', $examQuestion)->where('exam_id', $exam)->first();
        if (! $composition) {
            return $this->notFound();
        }

        $composition->delete();

        return response()->json([
            'success' => true,
            'message' => 'Question removed from exam successfully',
            'data' => null,
        ]);
    }

    // -----------------------------------------------------------------

    private function resolveExamForMutation(int $exam): array
    {
        $examModel = Exam::find($exam);

        if (! $examModel) {
            return [null, $this->notFound()];
        }

        if (! in_array($examModel->status, self::MUTABLE_STATUSES, true)) {
            return [$examModel, $this->unprocessable('Exam composition is locked for the current exam status.')];
        }

        return [$examModel, null];
    }

    private function positionTaken(int $exam, int $position, ?int $ignoreId = null): bool
    {
        return ExamQuestion::where('exam_id', $exam)
            ->where('position', $position)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Exam question not found',
            'data' => null,
        ], 404);
    }

    private function unprocessable(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
        ], 422);
    }
}