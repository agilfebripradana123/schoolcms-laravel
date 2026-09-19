<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Controller;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamAnswer;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamAttemptEvent;
use App\Models\Examination\ExamAttemptQuestion;
use App\Models\Examination\ExamAttemptQuestionOption;
use App\Models\Examination\ExamParticipant;
use App\Models\Examination\ExamQuestion;
use App\Models\Examination\ExamResult;
use App\Models\Examination\ExamSchedule;
use App\Models\Examination\QuestionBank;
use App\Models\Examination\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Secure Web Exam — student attempt session (Phase 10).
 *
 * Server-authoritative session for a single student attempt:
 *   - start (attempt limit + one-active-session + server timer)
 *   - reconnect / state
 *   - question delivery (persistent order, no answer key)
 *   - autosave answer (idempotent, rejects expired)
 *   - submit (transaction-safe, idempotent, computes result)
 *   - event / violation logging (audit trail only)
 *
 * Ownership is ALWAYS resolved server-side: authenticated Siswa ->
 * studentProfile -> ExamParticipant -> ExamAttempt. The client can never
 * target another student's attempt, even with a known attempt ID (404).
 * All timing comes from the server (now()/expires_at); client-sent
 * timestamps are never trusted.
 */
class StudentExamAttemptController extends Controller
{
    private const ALLOWED_EVENT_TYPES = [
        ExamAttemptEvent::TYPE_VISIBILITY_CHANGE,
        ExamAttemptEvent::TYPE_TAB_SWITCH,
        ExamAttemptEvent::TYPE_FULLSCREEN_EXIT,
        ExamAttemptEvent::TYPE_RECONNECT,
        ExamAttemptEvent::TYPE_LATE_REQUEST,
        ExamAttemptEvent::TYPE_MULTIPLE_SESSION_ATTEMPT,
    ];

    /**
     * POST /api/student/exam-attempts/start  { exam_id }
     */
    public function start(Request $request): JsonResponse
    {
        $student = $request->attributes->get('student_profile');

        $validated = $request->validate([
            'exam_id' => ['required', 'integer'],
        ]);

        $now = now();

        return DB::transaction(function () use ($student, $validated, $now) {
            $examId = (int) $validated['exam_id'];

            $participant = ExamParticipant::where('student_id', $student->id)
                ->where('exam_id', $examId)
                ->lockForUpdate()
                ->first();

            if (!$participant) {
                return $this->notFound('Exam participant not found.');
            }

            if ($participant->is_blocked || !$participant->login_allowed) {
                return $this->forbidden('Your exam access is blocked.');
            }

            $exam = Exam::withTrashed()->lockForUpdate()->find($examId);
            if (!$exam || !in_array($exam->status, ['published', 'ongoing'], true)) {
                return $this->unprocessable('Exam is not currently workable.');
            }

            // One active session per participant+exam.
            $active = ExamAttempt::where('exam_participant_id', $participant->id)
                ->where('status', ExamAttempt::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($active) {
                $this->lazyExpire($active, $now);
                if ($active->status === ExamAttempt::STATUS_ACTIVE) {
                    return $this->ok('Active attempt resumed.', $this->attemptPayload($active, $now));
                }
            }

            // Phase 2E execution window: server-side gate for NEW attempts only.
            // Resuming an in-flight attempt above is intentionally unaffected.
            $window = $this->executionWindowState($participant);
            if ($window !== null) {
                if ($window['status'] === 'before') {
                    return $this->unprocessable('Exam has not started yet.');
                }
                if ($window['status'] === 'after') {
                    return $this->unprocessable('Exam window has ended; no new attempts can be started.');
                }
                if ($window['status'] === 'gap') {
                    return $this->unprocessable('Exam is not currently in session.');
                }
            }

            // Attempt limit (server-computed).
            $attemptCount = ExamAttempt::where('exam_participant_id', $participant->id)->count();
            if ($attemptCount >= (int) $exam->max_attempts) {
                return $this->unprocessable('Maximum number of attempts reached.');
            }

            $attemptNumber = $attemptCount + 1;
            $startedAt = $now;
            $expiresAt = (clone $now)->addMinutes((int) $exam->duration_minutes);

            // Persistent question order (always determined at start).
            $questionIds = $this->buildQuestionOrder($exam);
            $optionOrder = $this->buildOptionOrder($exam, $questionIds);

            $attempt = ExamAttempt::create([
                'exam_participant_id' => $participant->id,
                'exam_id' => $exam->id,
                'attempt_number' => $attemptNumber,
                'status' => ExamAttempt::STATUS_ACTIVE,
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
                'question_order' => $questionIds,
                'option_order' => $optionOrder,
            ]);
            $attempt->generateToken();
            $attempt->save();

            // Phase 2G: freeze the exact questions/options/points/order the
            // attempt will use. Runs inside the same transaction as attempt
            // creation, so a snapshot failure rolls the attempt back too.
            $this->buildAttemptSnapshot(
                $attempt,
                $questionIds,
                $optionOrder,
                $this->compositionWeightMap($exam)
            );

            // Reflect attempt start on the participant.
            $participant->status = 'started';
            if ($participant->started_at === null) {
                $participant->started_at = $startedAt;
            }
            $participant->save();

            return $this->ok('Attempt started.', $this->attemptPayload($attempt->fresh(), $now));
        });
    }

    /**
     * GET /api/student/exam-attempts/{attempt}
     */
    public function show(Request $request, int $attemptId): JsonResponse
    {
        $student = $request->attributes->get('student_profile');
        $now = now();

        $attempt = $this->resolveOwnedAttempt($student, $attemptId);
        if ($attempt === null) {
            return $this->notFound('Attempt not found.');
        }

        $this->lazyExpire($attempt, $now);

        // Resume/saved-answers payload keyed by snapshot question identity so
        // it lines up 1:1 with the delivery ids from `/questions`.
        $savedAnswers = ExamAnswer::where('exam_attempt_id', $attempt->id)
            ->get()
            ->mapWithKeys(fn ($a) => [
                (string) ($a->attempt_question_id ?? $a->question_id) => [
                    'selected_option_id' => $a->selected_attempt_option_id ?? $a->selected_option_id,
                    'essay_answer' => $a->essay_answer,
                ],
            ])
            ->all();

        return $this->ok('Attempt retrieved.', [
            'attempt' => $this->attemptPayload($attempt, $now),
            'answers' => $savedAnswers,
        ]);
    }

    /**
     * GET /api/student/exam-attempts/{attempt}/questions
     */
    public function questions(Request $request, int $attemptId): JsonResponse
    {
        $student = $request->attributes->get('student_profile');
        $now = now();

        $attempt = $this->resolveOwnedAttempt($student, $attemptId);
        if ($attempt === null) {
            return $this->notFound('Attempt not found.');
        }

        $this->lazyExpire($attempt, $now);

        if ($attempt->status !== ExamAttempt::STATUS_ACTIVE) {
            return $this->unprocessable('Attempt is not active.', $this->attemptPayload($attempt, $now));
        }

        // Phase 2G: delivery comes from the immutable attempt snapshot, never
        // from the mutable live QuestionBank.
        $attemptQuestions = ExamAttemptQuestion::with('options')
            ->where('exam_attempt_id', $attempt->id)
            ->orderBy('position')
            ->get();

        $data = $attemptQuestions->map(
            fn (ExamAttemptQuestion $aq) => $this->snapshotQuestionPayload($aq)
        )->all();

        return $this->ok('Questions retrieved.', [
            'attempt' => $this->attemptSummary($attempt, $now),
            'questions' => $data,
        ]);
    }

    /**
     * PUT /api/student/exam-attempts/{attempt}/answers/{question}
     * `{question}` is the SNAPSHOT attempt-question id delivered by
     * `/questions` (never the live question_banks id).
     */
    public function answer(Request $request, int $attemptId, int $questionId): JsonResponse
    {
        $student = $request->attributes->get('student_profile');
        $now = now();

        $attempt = $this->resolveOwnedAttempt($student, $attemptId);
        if ($attempt === null) {
            return $this->notFound('Attempt not found.');
        }

        $this->lazyExpire($attempt, $now);

        if ($attempt->status !== ExamAttempt::STATUS_ACTIVE) {
            return $this->unprocessable('Attempt is not active.', $this->attemptPayload($attempt, $now));
        }

        // Question must belong to this attempt's snapshot.
        $attemptQuestion = ExamAttemptQuestion::where('exam_attempt_id', $attempt->id)
            ->where('id', $questionId)
            ->first();

        if (!$attemptQuestion) {
            return $this->unprocessable('Question does not belong to this attempt.');
        }

        $validated = $request->validate([
            'selected_option_id' => ['nullable', 'integer'],
            'essay_answer' => ['nullable', 'string'],
        ]);

        $selectedAttemptOption = null;
        if (!empty($validated['selected_option_id'])) {
            // The option id is the SNAPSHOT attempt-option id.
            $selectedAttemptOption = ExamAttemptQuestionOption::where('attempt_question_id', $attemptQuestion->id)
                ->where('id', (int) $validated['selected_option_id'])
                ->first();
            if (!$selectedAttemptOption) {
                return $this->unprocessable('Invalid option for this question.');
            }
        }

        // Correctness is resolved server-side from the snapshot (never client).
        $isCorrect = null;
        if ($selectedAttemptOption !== null && in_array($attemptQuestion->question_type, ['multiple_choice', 'true_false'], true)) {
            $isCorrect = (bool) $selectedAttemptOption->is_correct;
        }

        // Idempotent autosave keyed by (attempt, snapshot question).
        $answer = ExamAnswer::where('exam_attempt_id', $attempt->id)
            ->where('attempt_question_id', $attemptQuestion->id)
            ->first();

        if ($answer === null) {
            $answer = new ExamAnswer();
            $answer->exam_attempt_id = $attempt->id;
            $answer->participant_id = $attempt->exam_participant_id;
            $answer->attempt_question_id = $attemptQuestion->id;
            $answer->question_id = $attemptQuestion->source_question_id;
        }

        $answer->selected_attempt_option_id = $selectedAttemptOption?->id;
        $answer->selected_option_id = null; // snapshot identity is authoritative
        $answer->essay_answer = $validated['essay_answer'] ?? null;
        $answer->is_correct = $isCorrect;
        $answer->answered_at = $now;
        $answer->save();

        return $this->ok('Answer saved.', [
            'question_id' => $questionId,
            'selected_option_id' => $answer->selected_attempt_option_id,
            'essay_answer' => $answer->essay_answer,
            'answered_at' => $answer->answered_at?->toISOString(),
        ]);
    }

    /**
     * POST /api/student/exam-attempts/{attempt}/submit
     */
    public function submit(Request $request, int $attemptId): JsonResponse
    {
        $student = $request->attributes->get('student_profile');
        $now = now();

        $attempt = $this->resolveOwnedAttempt($student, $attemptId);
        if ($attempt === null) {
            return $this->notFound('Attempt not found.');
        }

        $this->lazyExpire($attempt, $now);

        // Idempotent: already submitted -> return existing summary.
        if ($attempt->status === ExamAttempt::STATUS_SUBMITTED) {
            return $this->ok('Attempt already submitted.', $this->attemptPayload($attempt, $now));
        }

        // active or expired both finalize here.
        return DB::transaction(function () use ($request, $attempt, $now) {
            // B20-F6 lock-order: lock the PARTICIPANT before the ATTEMPT, the
            // same order start() uses, so start() and submit() can never deadlock.
            $participant = ExamParticipant::where('id', $attempt->exam_participant_id)->lockForUpdate()->first();
            $attempt = ExamAttempt::where('id', $attempt->id)->lockForUpdate()->first();

            if (!$attempt) {
                return $this->notFound('Attempt not found.');
            }

            if ($attempt->status === ExamAttempt::STATUS_SUBMITTED) {
                return $this->ok('Attempt already submitted.', $this->attemptPayload($attempt, $now));
            }

            $attempt->status = ExamAttempt::STATUS_SUBMITTED;
            $attempt->submitted_at = $now;
            $attempt->save();

            if ($participant) {
                $participant->status = 'completed';
                $participant->completed_at = $now;
                $participant->save();
            }

            $result = app(\App\Services\Examination\ExamScoringService::class)->scoreAttempt($attempt);

            // B20-F8-M1: a result produced through the student submit path emits
            // the same bounded domain event as the admin result-generation path,
            // inside the same transaction (rollback removes it together).
            $resultRow = ExamResult::where('exam_attempt_id', $attempt->id)->first();
            if ($resultRow !== null) {
                \App\Models\System\AuditLog::create([
                    'user_id' => $request?->user()?->id,
                    'action' => 'exam_result_generated',
                    'model' => ExamResult::class,
                    'model_id' => $resultRow->id,
                    'description' => json_encode([
                        'result_id' => $resultRow->id,
                        'exam_attempt_id' => $resultRow->exam_attempt_id,
                        'participant_id' => $resultRow->participant_id,
                        'total_score' => $resultRow->total_score,
                        'percentage' => $resultRow->percentage,
                        'grade' => $resultRow->grade,
                        'status' => $resultRow->status,
                    ]),
                    'ip_address' => $request?->ip(),
                    'user_agent' => $request?->userAgent(),
                ]);
            }

            // Result visibility is authoritative from the exam record: the
            // result is always calculated and persisted, but the aggregate is
            // only exposed to the student when `show_result` is true.
            $response = [
                'attempt' => $this->attemptPayload($attempt, $now),
            ];
            if ((bool) ($attempt->exam?->show_result ?? false)) {
                $response['result'] = $result;
            }

            return $this->ok('Attempt submitted.', $response);
        });
    }

    /**
     * POST /api/student/exam-attempts/{attempt}/events  { event_type, metadata? }
     */
    public function event(Request $request, int $attemptId): JsonResponse
    {
        $student = $request->attributes->get('student_profile');
        $now = now();

        $attempt = $this->resolveOwnedAttempt($student, $attemptId);
        if ($attempt === null) {
            return $this->notFound('Attempt not found.');
        }

        $validated = $request->validate([
            'event_type' => ['required', 'string', 'max:60'],
            'metadata' => ['nullable', 'array'],
        ]);

        if (!in_array($validated['event_type'], self::ALLOWED_EVENT_TYPES, true)) {
            return $this->unprocessable('Invalid event type.');
        }

        ExamAttemptEvent::create([
            'exam_attempt_id' => $attempt->id,
            'event_type' => $validated['event_type'],
            'metadata' => $validated['metadata'] ?? null,
            'occurred_at' => $now,
        ]);

        return $this->ok('Event recorded.', [
            'event_type' => $validated['event_type'],
            'occurred_at' => $now->toISOString(),
        ]);
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function resolveOwnedAttempt($student, int $attemptId): ?ExamAttempt
    {
        $attempt = ExamAttempt::where('id', $attemptId)
            ->whereHas('participant', function ($q) use ($student) {
                $q->where('student_id', $student->id);
            })
            ->first();

        return $attempt;
    }

    private function lazyExpire(ExamAttempt $attempt, $now): void
    {
        if ($attempt->status === ExamAttempt::STATUS_ACTIVE
            && $attempt->expires_at !== null
            && $now >= $attempt->expires_at) {
            $attempt->status = ExamAttempt::STATUS_EXPIRED;
            $attempt->save();
        }
    }

    /**
     * Phase 2E server-side execution window gate.
     *
     * Returns null when the exam has no schedule (legacy exam -> status-only
     * rule preserved), otherwise one of:
     *   open     -> now is inside at least one window (attempt may start)
     *   before   -> now is earlier than every window
     *   after    -> now is past every window
     *   gap      -> now sits between windows
     *
     * A participant bound to a specific schedule (exam_participants.schedule_id)
     * is evaluated against that schedule only; otherwise every schedule of the
     * exam is considered. Server time only (now()).
     */
    private function executionWindowState(ExamParticipant $participant): ?array
    {
        $query = ExamSchedule::with('session')->where('exam_id', $participant->exam_id);
        if ($participant->schedule_id !== null) {
            $query->where('id', $participant->schedule_id);
        }
        $schedules = $query->get();

        if ($schedules->isEmpty()) {
            // Legacy exam (no schedule at all) -> status-only rule, no window gate.
            if ($participant->schedule_id === null) {
                return null;
            }

            // The participant is bound to a specific schedule that does not
            // belong to this exam (stale/invalid binding). Phase 2F: deny
            // rather than silently opening the window.
            return ['status' => 'before'];
        }

        $now = now();
        $starts = [];
        $ends = [];

        foreach ($schedules as $schedule) {
            [$start, $end] = $this->scheduleWindow($schedule);
            if ($now >= $start && $now < $end) {
                return ['status' => 'open'];
            }
            $starts[] = $start;
            $ends[] = $end;
        }

        if ($now < min($starts)) {
            return ['status' => 'before'];
        }

        if ($now >= max($ends)) {
            return ['status' => 'after'];
        }

        return ['status' => 'gap'];
    }

    /**
     * Resolve a schedule's execution window. Explicit start_datetime/end_datetime
     * are authoritative when present; legacy schedules fall back to
     * exam_date + session.start_time/end_time.
     */
    private function scheduleWindow(ExamSchedule $schedule): array
    {
        $date = $schedule->exam_date instanceof \DateTimeInterface
            ? Carbon::parse($schedule->exam_date->format('Y-m-d'))
            : Carbon::parse((string) $schedule->exam_date);

        if ($schedule->start_datetime !== null) {
            $start = Carbon::parse($schedule->start_datetime->format('Y-m-d H:i:s'));
        } else {
            $start = $date->copy()->setTimeFromTimeString((string) ($schedule->session?->start_time ?? '00:00:00'));
        }

        if ($schedule->end_datetime !== null) {
            $end = Carbon::parse($schedule->end_datetime->format('Y-m-d H:i:s'));
        } else {
            $end = $date->copy()->setTimeFromTimeString((string) ($schedule->session?->end_time ?? '23:59:59'));
        }

        return [$start, $end];
    }

    private function buildQuestionOrder(Exam $exam): array
    {
        // Phase 2D: explicit composition is the source of truth when present.
        $composed = ExamQuestion::where('exam_id', $exam->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('question_id')
            ->all();

        if (! empty($composed)) {
            if ($exam->shuffle_questions) {
                shuffle($composed);
            }

            return array_values($composed);
        }

        // Legacy fallback (exams created before Phase 2D have no ExamQuestion
        // rows): derive the set from the subject bank, sliced by total_questions.
        // Kept temporarily for backward compatibility; new exams must be composed
        // explicitly.
        $base = QuestionBank::where('subject_id', $exam->subject_id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($exam->shuffle_questions) {
            shuffle($base);
        }

        $limit = (int) $exam->total_questions;
        if ($limit > 0) {
            $base = array_slice($base, 0, $limit);
        }

        return array_values($base);
    }

    /**
     * Per-question composition weight (exam_questions.points) keyed by
     * question id. Empty for legacy exams -> callers fall back to the live
     * question bank points. Phase 2D wires this into delivery + scoring so the
     * exam's explicit per-question weight is the effective one for composed
     * exams (single scoring system, no second weight source).
     */
    private function compositionWeightMap(Exam $exam): array
    {
        return ExamQuestion::where('exam_id', $exam->id)
            ->pluck('points', 'question_id')
            ->map(fn ($points) => (int) $points)
            ->all();
    }

    private function buildOptionOrder(Exam $exam, array $questionIds): array
    {
        $map = [];
        if (empty($questionIds)) {
            return $map;
        }

        $questions = QuestionBank::whereIn('id', $questionIds)->get();
        foreach ($questions as $question) {
            $optionIds = QuestionOption::where('question_id', $question->id)
                ->orderBy('id')
                ->pluck('id')
                ->all();
            if ($exam->shuffle_options) {
                shuffle($optionIds);
            }
            $map[(string) $question->id] = array_values($optionIds);
        }

        return $map;
    }

    /**
     * Phase 2G: sanitized delivery payload from the immutable snapshot.
     * Never contains is_correct, explanation, or any grading metadata.
     */
    private function snapshotQuestionPayload(ExamAttemptQuestion $attemptQuestion): array
    {
        $options = $attemptQuestion->options
            ->sortBy('position')
            ->values()
            ->map(function ($option) {
                return [
                    'id' => $option->id,
                    'option_text' => $option->option_text,
                    'option_image' => $option->option_image,
                ];
            })
            ->all();

        return [
            'id' => $attemptQuestion->id,
            'source_question_id' => $attemptQuestion->source_question_id,
            'question_text' => $attemptQuestion->question_text,
            'question_type' => $attemptQuestion->question_type,
            'difficulty' => null,
            'points' => (int) $attemptQuestion->points,
            'options' => $options,
        ];
    }

    /**
     * Phase 2G: freezes the resolved question set into per-attempt snapshot
     * rows. Option rows are written in the attempt's persistent order (shuffle
     * already applied) and grading truth (is_correct) is copied from the bank
     * at this moment. MUST run inside the attempt creation transaction.
     */
    private function buildAttemptSnapshot(ExamAttempt $attempt, array $questionIds, array $optionOrder, array $weightMap): void
    {
        $questionsById = QuestionBank::with('options')->whereIn('id', $questionIds)->get()->keyBy('id');

        foreach (array_values($questionIds) as $position => $questionId) {
            $question = $questionsById->get($questionId);
            if (!$question) {
                continue; // defensive; guarded by composition/legacy resolution
            }

            $attemptQuestion = ExamAttemptQuestion::create([
                'exam_attempt_id' => $attempt->id,
                'source_question_id' => $question->id,
                'question_code' => $question->code,
                'question_text' => $question->question_text,
                'question_type' => $question->type,
                'points' => $weightMap[(string) $question->id] ?? (int) $question->points,
                'position' => $position + 1,
            ]);

            $orderedOptionIds = $optionOrder[(string) $question->id] ?? [];
            $optionsById = $question->options->keyBy('id');

            foreach ($orderedOptionIds as $optionPosition => $optionId) {
                $option = $optionsById->get($optionId);
                if (!$option) {
                    continue;
                }
                ExamAttemptQuestionOption::create([
                    'attempt_question_id' => $attemptQuestion->id,
                    'source_option_id' => $option->id,
                    'option_text' => $option->option_text,
                    'option_image' => $option->option_image,
                    'position' => $optionPosition + 1,
                    'is_correct' => (bool) $option->is_correct,
                ]);
            }
        }
    }

    private function attemptPayload(ExamAttempt $attempt, $now): array
    {
        return [
            'id' => $attempt->id,
            'attempt_number' => (int) $attempt->attempt_number,
            'status' => $attempt->status,
            'started_at' => $attempt->started_at?->toISOString(),
            'expires_at' => $attempt->expires_at?->toISOString(),
            'submitted_at' => $attempt->submitted_at?->toISOString(),
            'server_now' => $now->toISOString(),
            'exam_id' => $attempt->exam_id,
        ];
    }

    private function attemptSummary(ExamAttempt $attempt, $now): array
    {
        return $this->attemptPayload($attempt, $now);
    }

    private function ok(string $message, $data = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated.',
            'data' => null,
        ], 401);
    }

    private function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
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

    private function unprocessable(string $message, $data = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
        ], 422);
    }
}
