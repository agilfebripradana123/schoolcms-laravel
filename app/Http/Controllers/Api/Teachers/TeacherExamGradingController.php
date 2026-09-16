<?php

namespace App\Http\Controllers\Api\Teachers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Teachers\StoreEssayGradeRequest;
use App\Models\Examination\ExamAnswer;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamAttemptQuestion;
use App\Models\Staff\TeacherAssignment;
use App\Services\Examination\ExamGradeIntegrationService;
use App\Services\Examination\ExamScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher self-service: manual essay grading (Phase 2H).
 *
 * Scope is derived server-side, exactly like the other teacher examination
 * controllers: authenticated user -> teacherProfile -> teacher.id ->
 * TeacherAssignment.subject_id -> attempt.exam.subject_id. A teacher can only
 * view/grade essay answers of attempts belonging to exams in their teaching
 * scope; anything else is 404 (no IDOR, no enumeration).
 *
 * Grading is the ONLY write a teacher performs on an attempt: per-answer
 * awarded score (0..snapshot max points) + optional feedback. Attempt
 * ownership, student identity, maximum points, correctness and totals are all
 * server-owned.
 */
class TeacherExamGradingController extends Controller
{
    public function show(Request $request, int $attempt): JsonResponse
    {
        $teacher = $this->teacher($request);
        if (! $teacher) {
            return $this->forbidden();
        }

        $subjectIds = $this->subjectIds($teacher->id);

        $attemptModel = ExamAttempt::with(['exam', 'participant.student', 'attemptQuestions'])
            ->where('id', $attempt)
            ->whereHas('exam', fn ($q) => $q->whereIn('subject_id', $subjectIds))
            ->first();

        if (! $attemptModel) {
            return $this->notFound('Attempt not found.');
        }

        $answers = ExamAnswer::where('exam_attempt_id', $attemptModel->id)
            ->get()
            ->keyBy('attempt_question_id');

        $essays = $attemptModel->attemptQuestions->filter(
            fn (ExamAttemptQuestion $question) => $question->question_type === 'essay'
        );

        $data = $essays->values()->map(function (ExamAttemptQuestion $question) use ($attemptModel, $answers) {
            $answer = $answers->get($question->id);

            return [
                'attempt_question_id' => $question->id,
                'question_text' => $question->question_text,
                'max_points' => (int) $question->points,
                'student' => [
                    'id' => $attemptModel->participant->student->id ?? null,
                    'name' => $attemptModel->participant->student->name ?? null,
                    'nis' => $attemptModel->participant->student->nis ?? null,
                ],
                'essay_answer' => $answer->essay_answer ?? null,
                'score' => $answer->score ?? null,
                'grade_status' => $answer->grade_status ?? 'pending_manual',
                'feedback' => $answer->feedback ?? null,
                'graded_at' => $answer->graded_at ? $answer->graded_at->toISOString() : null,
            ];
        })->all();

        return response()->json([
            'success' => true,
            'message' => 'Essay answers retrieved successfully',
            'data' => [
                'attempt_id' => $attemptModel->id,
                'attempt_status' => $attemptModel->status,
                'essays' => $data,
            ],
        ]);
    }

    public function grade(StoreEssayGradeRequest $request, int $examAnswer): JsonResponse
    {
        $teacher = $this->teacher($request);
        if (! $teacher) {
            return $this->forbidden();
        }

        $subjectIds = $this->subjectIds($teacher->id);

        $answer = ExamAnswer::with(['attempt.exam', 'attemptQuestion'])
            ->where('id', $examAnswer)
            ->first();

        if (! $answer) {
            return $this->notFound('Exam answer not found.');
        }

        $attempt = $answer->attempt;
        $question = $answer->attemptQuestion;

        if (! $attempt || ! in_array($attempt->exam->subject_id, $subjectIds, true)) {
            return $this->notFound('Exam answer not found.');
        }

        if (! in_array($attempt->status, ['submitted', 'expired'], true)) {
            return $this->unprocessable('Active attempts cannot be graded yet.');
        }

        if (! $question || $question->question_type !== 'essay') {
            return $this->unprocessable('Only essay answers can be manually graded.');
        }

        $maxPoints = (int) $question->points;
        $score = (int) $request->validated()['score'];

        if ($score < 0 || $score > $maxPoints) {
            return $this->unprocessable(sprintf('Score must be between 0 and %d.', $maxPoints));
        }

        $answer->score = $score;
        if ($request->filled('feedback')) {
            $answer->feedback = $request->input('feedback');
        }
        $answer->grade_status = 'manually_graded';
        $answer->graded_by = $request->user()->id;
        $answer->graded_at = now();
        $answer->save();

        // Recompute the participant result from the snapshot (idempotent).
        $result = app(ExamScoringService::class)->scoreAttempt($attempt);

        // Regrade propagation: if this result was already synchronized into the
        // academic Grade, re-sync so the academic value never goes stale —
        // UNLESS the grade is finalized/locked (Grade.is_final stays the
        // authoritative lock; a locked academic value is never overwritten).
        $resultRow = \App\Models\Examination\ExamResult::where('participant_id', $attempt->exam_participant_id)->first();
        $gradeIntegration = app(ExamGradeIntegrationService::class);
        if ($resultRow && $gradeIntegration->isSynced($resultRow)) {
            $syncedGrade = \App\Models\Academic\Grade::where('source_type', 'exam_result')
                ->where('source_id', $resultRow->id)
                ->first();
            if ($syncedGrade && ! app(\App\Services\Academic\GradeMutationGuard::class)->isLocked($syncedGrade)) {
                $gradeIntegration->sync($resultRow);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Essay answer graded successfully',
            'data' => [
                'attempt_question_id' => $question->id,
                'score' => $score,
                'feedback' => $answer->feedback,
                'grade_status' => $answer->grade_status,
                'graded_by' => $answer->graded_by,
                'graded_at' => $answer->graded_at?->toISOString(),
                'result' => $result,
            ],
        ]);
    }

    /**
     * POST /api/teacher/exam-grading/results/{result}/grade-sync
     * Teacher-scoped explicit synchronization of an eligible result into the
     * Academic Grade. Server-derived identity; no request body.
     */
    public function syncGrade(Request $request, int $result): JsonResponse
    {
        $teacher = $this->teacher($request);
        if (! $teacher) {
            return $this->forbidden();
        }

        $subjectIds = $this->subjectIds($teacher->id);

        $resultRow = \App\Models\Examination\ExamResult::with(['participant'])
            ->where('id', $result)
            ->whereHas('participant.exam', fn ($q) => $q->whereIn('subject_id', $subjectIds))
            ->first();

        if (! $resultRow) {
            return $this->notFound('Exam result not found.');
        }

        $outcome = app(ExamGradeIntegrationService::class)->sync($resultRow);

        if (! $outcome['ok']) {
            return $this->unprocessable($outcome['message']);
        }

        return response()->json([
            'success' => true,
            'message' => $outcome['message'],
            'data' => [
                'grade_id' => $outcome['grade']->id,
                'grade' => $outcome['grade'],
            ],
        ]);
    }

    private function teacher(Request $request)
    {
        return $request->user()?->teacherProfile;
    }

    private function subjectIds(int $teacherId): array
    {
        return TeacherAssignment::where('teacher_id', $teacherId)
            ->pluck('subject_id')
            ->unique()
            ->all();
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Forbidden',
            'data' => null,
        ], 403);
    }

    private function notFound(string $message = 'Not found'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
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