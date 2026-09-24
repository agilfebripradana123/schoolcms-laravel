<?php

namespace App\Http\Controllers\Api\Teachers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Teachers\StoreEssayGradeRequest;
use App\Models\Examination\ExamAnswer;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamAttemptQuestion;
use App\Services\Examination\ExamGradeIntegrationService;
use App\Services\Examination\ExamScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher self-service: manual essay grading (Phase 2H).
 *
 * Scope is derived server-side, exactly like the other teacher examination
 * controllers: authenticated user -> teacherProfile -> teacher.id ->
 * TeacherAssignment via Exam::scopeTeacherAccessible (classless = subject-only,
 * class-scoped = full TeacherAssignment triple). A teacher can only
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

        $attemptModel = ExamAttempt::with(['exam', 'participant.student', 'attemptQuestions'])
            ->where('id', $attempt)
            ->whereHas('exam', fn ($q) => $q->teacherAccessible($teacher->id))
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
                'exam_answer_id' => $answer->id ?? null,
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

        $answer = ExamAnswer::with(['attempt.exam', 'attemptQuestion'])
            ->where('id', $examAnswer)
            ->first();

        if (! $answer) {
            return $this->notFound('Exam answer not found.');
        }

        $attempt = $answer->attempt;
        $question = $answer->attemptQuestion;

        if (! $attempt || ! $attempt->exam->accessibleByTeacher($teacher->id)) {
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

        // A finalized result is immutable: reject the regrade before any answer
        // mutation, so neither the essay answer nor the result can change.
        $finalized = \App\Models\Examination\ExamResult::where('exam_attempt_id', $attempt->id)->first();
        if ($finalized && $finalized->is_final) {
            return $this->unprocessable('Result is finalized and cannot be modified.');
        }

        // Answer mutation + result recomputation (+ optional re-sync) + audit
        // share a single transaction so a failed operation leaves nothing behind.
        //
        // B21-02: the ExamResult row is locked FIRST inside the transaction and
        // finality is re-checked against the locked row before any ExamAnswer
        // mutation, so a concurrent finalize() can never produce a "finalized
        // result + mutated essay answer" state. Lock order is ExamResult ->
        // ExamAnswer; no other path locks ExamAnswer rows, so there is no cycle.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $answer, $attempt, $score) {
            $lockedResult = \App\Models\Examination\ExamResult::where('exam_attempt_id', $attempt->id)
                ->lockForUpdate()
                ->first();

            // Authoritative finality check under the result lock. The pre-lock
            // fast-path check above is only an optimization; this is the source
            // of truth.
            if ($lockedResult !== null && $lockedResult->is_final) {
                return $this->unprocessable('Result is finalized and cannot be modified.');
            }

            // Lock the answer row only AFTER the result row (ExamResult -> ExamAnswer).
            $answer = ExamAnswer::where('id', $answer->id)->lockForUpdate()->first();

            if (! $answer || (int) $answer->exam_attempt_id !== (int) $attempt->id) {
                return $this->notFound('Exam answer not found.');
            }

            // Re-validate attempt state and essay type against the locked row's
            // fresh relations, preserving the existing semantics.
            $attempt = $answer->attempt;
            $question = $answer->attemptQuestion;
            if (! $attempt || ! in_array($attempt->status, ['submitted', 'expired'], true)) {
                return $this->unprocessable('Active attempts cannot be graded yet.');
            }
            if (! $question || $question->question_type !== 'essay') {
                return $this->unprocessable('Only essay answers can be manually graded.');
            }

            $scoreBefore = $answer->score;

            $answer->score = $score;
            if ($request->filled('feedback')) {
                $answer->feedback = $request->input('feedback');
            }
            $answer->grade_status = 'manually_graded';
            $answer->graded_by = $request->user()->id;
            $answer->graded_at = now();
            $answer->save();

            // Recompute the participant result from the snapshot (idempotent);
            // its nested result lock reuses the row already locked above.
            $scoring = app(ExamScoringService::class);
            $result = $scoring->scoreAttempt($attempt);

            $auditContext = [
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ];

            // Regrade propagation: if this result was already synchronized into the
            // academic Grade, re-sync so the academic value never goes stale —
            // UNLESS the grade is finalized/locked (Grade.is_final stays the
            // authoritative lock; a locked academic value is never overwritten).
            //
            // B21-10: re-sync only when the locked result is still the participant's
            // EFFECTIVE result — a superseded result must never overwrite the
            // academic Grade.
            $resultRow = $lockedResult;
            $gradeIntegration = app(ExamGradeIntegrationService::class);
            if ($resultRow && $scoring->isEffectiveResult($resultRow) && $gradeIntegration->isSynced($resultRow)) {
                // Source tracing lives on GradeAssessment; resolve the matching
                // academic Grade via the assessment identity.
                $assessment = \App\Models\Academic\GradeAssessment::where('source_type', 'exam_result')
                    ->where('source_id', $resultRow->id)
                    ->first();
                $syncedGrade = $assessment ? \App\Models\Academic\Grade::where('student_id', $assessment->student_id)
                    ->where('subject_id', $assessment->subject_id)
                    ->where('class_id', $assessment->class_id)
                    ->where('type', $assessment->assessment_category)
                    ->where('semester_id', $assessment->semester_id)
                    ->where('academic_year_id', $assessment->academic_year_id)
                    ->first() : null;
                if ($syncedGrade && ! app(\App\Services\Academic\GradeMutationGuard::class)->isLocked($syncedGrade)) {
                    $gradeIntegration->sync($resultRow, $auditContext);
                }
            }

            \App\Models\System\AuditLog::create([
                'user_id' => $auditContext['user_id'],
                'action' => 'exam_essay_graded',
                'model' => ExamAnswer::class,
                'model_id' => $answer->id,
                'description' => json_encode([
                    'answer_id' => $answer->id,
                    'attempt_id' => $attempt->id,
                    'exam_id' => $attempt->exam_id,
                    'score_before' => $scoreBefore,
                    'score_after' => $score,
                    'feedback_present' => $request->filled('feedback'),
                ]),
                'ip_address' => $auditContext['ip_address'],
                'user_agent' => $auditContext['user_agent'],
            ]);

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
        });
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

        $resultRow = \App\Models\Examination\ExamResult::with(['participant'])
            ->where('id', $result)
            ->whereHas('participant.exam', fn ($q) => $q->teacherAccessible($teacher->id))
            ->first();

        if (! $resultRow) {
            return $this->notFound('Exam result not found.');
        }

        // Grade Sync operates on the participant's EFFECTIVE result only.
        // Legacy NULL-attempt rows and older non-effective attempts are not
        // syncable; authorization/eligibility errors stay opaque.
        if (! app(ExamScoringService::class)->isEffectiveResult($resultRow)) {
            return $this->unprocessable('Exam result is not eligible for synchronization.');
        }

        $outcome = app(ExamGradeIntegrationService::class)->sync($resultRow, [
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

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