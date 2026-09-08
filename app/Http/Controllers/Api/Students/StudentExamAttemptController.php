<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Controller;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamAnswer;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamAttemptEvent;
use App\Models\Examination\ExamParticipant;
use App\Models\Examination\ExamResult;
use App\Models\Examination\QuestionBank;
use App\Models\Examination\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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

        // Include previously saved answers so an interrupted/reloaded session
        // can resume (question_id -> selected_option_id / essay_answer).
        $savedAnswers = ExamAnswer::where('exam_attempt_id', $attempt->id)
            ->get()
            ->mapWithKeys(fn ($a) => [
                (string) $a->question_id => [
                    'selected_option_id' => $a->selected_option_id,
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

        $order = $attempt->question_order ?? [];
        $questions = QuestionBank::whereIn('id', $order)->get()->keyBy('id');

        $data = array_map(function ($questionId) use ($questions, $attempt) {
            /** @var QuestionBank|null $q */
            $q = $questions->get($questionId);
            if (!$q) {
                return null;
            }
            return $this->questionPayload($q, $attempt);
        }, $order);

        $data = array_values(array_filter($data));

        return $this->ok('Questions retrieved.', [
            'attempt' => $this->attemptSummary($attempt, $now),
            'questions' => $data,
        ]);
    }

    /**
     * PUT /api/student/exam-attempts/{attempt}/answers/{question}
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

        // Question must belong to this attempt.
        $order = $attempt->question_order ?? [];
        if (!in_array($questionId, $order, true)) {
            return $this->unprocessable('Question does not belong to this attempt.');
        }

        $validated = $request->validate([
            'selected_option_id' => ['nullable', 'integer'],
            'essay_answer' => ['nullable', 'string'],
        ]);

        $question = QuestionBank::find($questionId);
        if (!$question) {
            return $this->unprocessable('Question not found.');
        }

        $selectedOption = null;
        if (!empty($validated['selected_option_id'])) {
            $selectedOption = QuestionOption::where('question_id', $questionId)
                ->where('id', (int) $validated['selected_option_id'])
                ->first();
            if (!$selectedOption) {
                return $this->unprocessable('Invalid option for this question.');
            }
        }

        $isCorrect = null;
        if ($selectedOption !== null && in_array($question->type, ['multiple_choice', 'true_false'], true)) {
            $isCorrect = (bool) $selectedOption->is_correct;
        }

        // Idempotent autosave: keyed by (attempt, question) via unique index.
        $answer = ExamAnswer::where('exam_attempt_id', $attempt->id)
            ->where('question_id', $questionId)
            ->first();

        if ($answer === null) {
            $answer = new ExamAnswer();
            $answer->exam_attempt_id = $attempt->id;
            $answer->participant_id = $attempt->exam_participant_id;
            $answer->question_id = $questionId;
        }

        $answer->selected_option_id = $selectedOption?->id;
        $answer->essay_answer = $validated['essay_answer'] ?? null;
        $answer->is_correct = $isCorrect;
        $answer->answered_at = $now;
        $answer->save();

        return $this->ok('Answer saved.', [
            'question_id' => $questionId,
            'selected_option_id' => $answer->selected_option_id,
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
        return DB::transaction(function () use ($attempt, $now) {
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

            $participant = ExamParticipant::lockForUpdate()->find($attempt->exam_participant_id);
            if ($participant) {
                $participant->status = 'completed';
                $participant->completed_at = $now;
                $participant->save();
            }

            $result = $this->computeAndStoreResult($attempt);

            return $this->ok('Attempt submitted.', [
                'attempt' => $this->attemptPayload($attempt, $now),
                'result' => $result,
            ]);
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

    private function buildQuestionOrder(Exam $exam): array
    {
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

    private function computeAndStoreResult(ExamAttempt $attempt): ?array
    {
        $order = $attempt->question_order ?? [];
        $questions = QuestionBank::whereIn('id', $order)->get()->keyBy('id');

        $answers = ExamAnswer::where('exam_attempt_id', $attempt->id)->get()->keyBy('question_id');

        $correctCount = 0;
        $wrongCount = 0;
        $score = 0;
        $maxPoints = 0;

        foreach ($order as $questionId) {
            $question = $questions->get($questionId);
            if (!$question) {
                continue;
            }
            $maxPoints += (int) $question->points;
            $answer = $answers->get($questionId);
            if ($answer) {
                if ($answer->is_correct === true) {
                    $correctCount++;
                    $score += (int) $question->points;
                } elseif ($answer->is_correct === false) {
                    $wrongCount++;
                }
                // essay (is_correct === null) not auto-graded -> counted as unanswered for auto-grade
            }
        }

        $unansweredCount = max(0, count($order) - $correctCount - $wrongCount);

        $percentage = $maxPoints > 0 ? round(($score / $maxPoints) * 100, 2) : 0.0;
        $grade = $this->letterGrade((float) $percentage);

        ExamResult::updateOrCreate(
            ['participant_id' => $attempt->exam_participant_id],
            [
                'total_score' => $score,
                'correct_count' => $correctCount,
                'wrong_count' => $wrongCount,
                'unanswered_count' => $unansweredCount,
                'grade' => $grade,
                'status' => 'graded',
                'graded_at' => now(),
            ]
        );

        return [
            'total_score' => $score,
            'correct_count' => $correctCount,
            'wrong_count' => $wrongCount,
            'unanswered_count' => $unansweredCount,
            'grade' => $grade,
            'status' => 'graded',
        ];
    }

    private function letterGrade(float $percentage): ?string
    {
        if ($percentage >= 90) return 'A';
        if ($percentage >= 80) return 'B';
        if ($percentage >= 70) return 'C';
        if ($percentage >= 60) return 'D';
        return 'E';
    }

    private function questionPayload(QuestionBank $question, ExamAttempt $attempt): array
    {
        $optionMap = $attempt->option_order ?? [];
        $orderedIds = $optionMap[(string) $question->id] ?? $question->options->pluck('id')->all();

        $optionsById = $question->options->keyBy('id');
        $options = [];
        foreach ($orderedIds as $oid) {
            $opt = $optionsById->get($oid);
            if (!$opt) {
                continue;
            }
            // Deliberately excludes is_correct / explanation.
            $options[] = [
                'id' => $opt->id,
                'option_text' => $opt->option_text,
                'option_image' => $opt->option_image,
            ];
        }

        return [
            'id' => $question->id,
            'question_text' => $question->question_text,
            'question_image' => $question->question_image,
            'type' => $question->type,
            'difficulty' => $question->difficulty,
            'points' => (int) $question->points,
            'options' => $options,
        ];
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
