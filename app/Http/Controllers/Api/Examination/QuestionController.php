<?php

namespace App\Http\Controllers\Api\Examination;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Examination\StoreQuestionRequest;
use App\Http\Requests\Api\Examination\UpdateQuestionRequest;
use App\Http\Resources\Examination\QuestionBankResource;
use App\Models\Examination\ExamQuestion;
use App\Models\Examination\QuestionBank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuestionController extends Controller
{
    // B21-03: a question composed into an exam that has left the draft stage is
    // part of that exam's live definition — mutating or deleting it would
    // silently alter delivered/attempted content. draft/archived compositions
    // stay mutable (snapshots already freeze delivered attempts).
    private const OPERATIONAL_EXAM_STATUSES = ['published', 'ongoing', 'completed'];

    private function questionOperationallyComposed(int $questionId): bool
    {
        return ExamQuestion::where('question_id', $questionId)
            ->whereHas('exam', fn ($q) => $q->whereIn('status', self::OPERATIONAL_EXAM_STATUSES))
            ->exists();
    }
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'subject_id' => 'nullable|integer',
            'instruction_id' => 'nullable|integer',
            'type' => 'nullable|string|in:multiple_choice,true_false,essay',
            'difficulty' => 'nullable|string|in:easy,medium,hard',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = QuestionBank::with(['subject', 'options']);

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where('question_text', 'LIKE', "%{$search}%");
        }

        if (!empty($validated['subject_id'])) {
            $query->where('subject_id', $validated['subject_id']);
        }

        if (!empty($validated['instruction_id'])) {
            $query->where('instruction_id', $validated['instruction_id']);
        }

        if (!empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (!empty($validated['difficulty'])) {
            $query->where('difficulty', $validated['difficulty']);
        }

        $perPage = $validated['per_page'] ?? 10;
        $questions = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Questions retrieved successfully',
            'data' => QuestionBankResource::collection($questions),
            'meta' => [
                'current_page' => $questions->currentPage(),
                'per_page' => $questions->perPage(),
                'total' => $questions->total(),
                'last_page' => $questions->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $question = QuestionBank::with(['subject', 'options'])->find($id);

        if (!$question) {
            return response()->json([
                'success' => false,
                'message' => 'Question not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Question retrieved successfully',
            'data' => new QuestionBankResource($question),
        ]);
    }

    public function store(StoreQuestionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $options = $validated['options'] ?? [];
        unset($validated['options']);

        // Server-side defaults: status (foundation lifecycle) and owner
        // (authenticated manager). `code` is never client-input: it is derived
        // deterministically from the auto-increment id below and is immutable.
        $validated['status'] = $validated['status'] ?? 'draft';
        $validated['owner_id'] = $validated['owner_id'] ?? $request->user()?->id;

        $question = DB::transaction(function () use ($validated, $options) {
            $question = QuestionBank::create($validated);

            // Deterministic immutable code (matches Phase 2A backfill format).
            $question->code = 'Q-'.str_pad((string) $question->id, 6, '0', STR_PAD_LEFT);
            $question->save();

            foreach ($options as $index => $option) {
                $question->options()->create([
                    'option_text' => $option['option_text'],
                    'option_image' => $option['option_image'] ?? null,
                    'is_correct' => $option['is_correct'] ?? false,
                ]);
            }

            return $question->load(['subject', 'options']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Question created successfully',
            'data' => new QuestionBankResource($question),
        ], 201);
    }

    public function update(UpdateQuestionRequest $request, int $id): JsonResponse
    {
        $question = QuestionBank::find($id);

        if (!$question) {
            return response()->json([
                'success' => false,
                'message' => 'Question not found',
                'data' => null,
            ], 404);
        }

        // B21-03: a question composed into a published/ongoing/completed exam is
        // part of that exam's live definition and may not be mutated.
        if ($this->questionOperationallyComposed($question->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Question is composed into an operational exam and cannot be modified.',
                'data' => null,
            ], 422);
        }

        $validated = $request->validated();
        $options = $validated['options'] ?? null;
        unset($validated['options']);

        DB::transaction(function () use ($question, $validated, $options) {
            // `code` is intentionally absent from $validated: updates never
            // regenerate or replace a question code.
            $question->update($validated);

            if ($options !== null) {
                $question->options()->delete();

                foreach ($options as $index => $option) {
                    $question->options()->create([
                        'option_text' => $option['option_text'],
                        'option_image' => $option['option_image'] ?? null,
                        'is_correct' => $option['is_correct'] ?? false,
                    ]);
                }
            }
        });

        $question->load(['subject', 'options']);

        return response()->json([
            'success' => true,
            'message' => 'Question updated successfully',
            'data' => new QuestionBankResource($question),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $question = QuestionBank::find($id);

        if (!$question) {
            return response()->json([
                'success' => false,
                'message' => 'Question not found',
                'data' => null,
            ], 404);
        }

        // B21-03: same protection as update — a question composed into a
        // published/ongoing/completed exam cannot be deleted.
        if ($this->questionOperationallyComposed($question->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Question is composed into an operational exam and cannot be deleted.',
                'data' => null,
            ], 422);
        }

        $question->delete();

        return response()->json([
            'success' => true,
            'message' => 'Question deleted successfully',
            'data' => null,
        ]);
    }
}
