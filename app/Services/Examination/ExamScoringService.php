<?php

namespace App\Services\Examination;

use App\Models\Examination\ExamAnswer;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamAttemptQuestion;
use App\Models\Examination\ExamResult;

/**
 * Server-authoritative attempt scoring (Phase 2H).
 *
 * Scoring reads ONLY the immutable attempt snapshot
 * (ExamAttemptQuestion / ExamAttemptQuestionOption) plus the stored
 * ExamAnswer rows. It never consults the mutable live QuestionBank/QuestionOption
 * /ExamQuestion for points, text or correctness.
 *
 * Rules:
 *   - MC / TF: correct snapshot option -> snapshot points; else 0.
 *   - Essay: NEVER auto-scored. Answered essays stay `pending_manual` with a
 *     NULL score until a scoped teacher assigns one; unanswered essays
 *     contribute 0 and are not blocking.
 *   - percentage = earned / total * 100 (0 when total is 0).
 *   - result status: `graded` when nothing is pending; `pending` while any
 *     answered essay is ungraded.
 *
 * Idempotent by construction: every run recomputes from the snapshot and
 * answers and reconciles a single result row per participant.
 */
class ExamScoringService
{
    public function scoreAttempt(ExamAttempt $attempt): array
    {
        // A finalized result is immutable: never re-score it and never touch
        // its answer rows. Callers that must reject earlier already return 422;
        // this guard keeps every scoring path safe even without that check.
        $existing = ExamResult::where('exam_attempt_id', $attempt->id)->first();
        if ($existing !== null && $existing->is_final) {
            return [
                'total_score' => (float) $existing->total_score,
                'correct_count' => (int) $existing->correct_count,
                'wrong_count' => (int) $existing->wrong_count,
                'unanswered_count' => (int) $existing->unanswered_count,
                'percentage' => (float) $existing->percentage,
                'grade' => $existing->grade,
                'status' => $existing->status,
            ];
        }

        $attemptQuestions = ExamAttemptQuestion::where('exam_attempt_id', $attempt->id)
            ->orderBy('position')
            ->get();

        $answers = ExamAnswer::where('exam_attempt_id', $attempt->id)
            ->get()
            ->keyBy('attempt_question_id');

        $totalPoints = 0;
        $earned = 0;
        $correct = 0;
        $wrong = 0;
        $unanswered = 0;
        $pendingEssays = 0;

        foreach ($attemptQuestions as $question) {
            $points = (int) $question->points;
            $totalPoints += $points;

            $answer = $answers->get($question->id);
            $isEssay = $question->question_type === 'essay';

            if ($answer === null) {
                $unanswered++;

                continue;
            }

            if ($isEssay) {
                if (! in_array($answer->grade_status, ['manually_graded'], true)) {
                    // Answered essay waiting for a teacher.
                    $pendingEssays++;
                    $answer->grade_status = 'pending_manual';
                    $answer->score = null;
                    $answer->save();

                    continue;
                }

                // Manually graded essay: add the awarded score.
                $earned += max(0, (int) $answer->score);

                continue;
            }

            // Objective MC / TF — server-authoritative from the snapshot.
            $answer->grade_status = 'auto';
            if ($answer->is_correct === true) {
                $correct++;
                $earned += $points;
                $answer->score = $points;
            } elseif ($answer->is_correct === false) {
                $wrong++;
                $answer->score = 0;
            } else {
                $unanswered++;
                $answer->score = 0;
            }
            $answer->save();
        }

        $percentage = $totalPoints > 0 ? round(($earned / $totalPoints) * 100, 2) : 0.0;

        $resultPayload = [
            'total_score' => $earned,
            'correct_count' => $correct,
            'wrong_count' => $wrong,
            'unanswered_count' => $unanswered,
            'grade' => $this->letterGrade((float) $percentage),
            'percentage' => $percentage,
            'status' => $pendingEssays > 0 ? 'pending' : 'graded',
        ];

        ExamResult::updateOrCreate(
            ['exam_attempt_id' => $attempt->id],
            array_merge($resultPayload, [
                'participant_id' => $attempt->exam_participant_id,
                'exam_attempt_id' => $attempt->id,
                'graded_at' => now(),
            ])
        );

        return $resultPayload;
    }

    /**
     * Resolve the participant's effective result.
     *
     * Contract:
     *   finalized result if one exists, otherwise the latest submitted
     *   attempt's result. Deterministic, attempt-timestamp based; never
     *   score-based, never aggregates attempts.
     *
     * A finalized result wins even when a later submitted (non-finalized)
     * attempt exists. Non-finalized resolution keeps the historical semantics
     * (latest submitted_at, attempt id tie-break).
     */
    public function effectiveResult(int $participantId): ?ExamResult
    {
        return ExamResult::join('exam_attempts', 'exam_attempts.id', '=', 'exam_results.exam_attempt_id')
            ->where('exam_results.participant_id', $participantId)
            ->whereNotNull('exam_results.exam_attempt_id')
            ->orderByDesc('exam_results.is_final')
            ->orderByDesc('exam_attempts.submitted_at')
            ->orderByDesc('exam_attempts.id')
            ->select('exam_results.*')
            ->first();
    }

    /**
     * True when the given result is the participant's effective result and is
     * attempt-linked (legacy NULL-attempt rows are never effective).
     */
    public function isEffectiveResult(ExamResult $result): bool
    {
        if ($result->exam_attempt_id === null) {
            return false;
        }

        $effective = $this->effectiveResult((int) $result->participant_id);

        return $effective !== null && (int) $effective->id === (int) $result->id;
    }

    /**
     * Check whether an attempt's result may be considered fully graded
     * (no answered essay still awaiting manual grading).
     */
    public function isFullyGraded(ExamAttempt $attempt): bool
    {
        $essayIds = ExamAttemptQuestion::where('exam_attempt_id', $attempt->id)
            ->where('question_type', 'essay')
            ->pluck('id');

        if ($essayIds->isEmpty()) {
            return true;
        }

        return ExamAnswer::where('exam_attempt_id', $attempt->id)
            ->whereIn('attempt_question_id', $essayIds)
            ->where(fn ($q) => $q->whereNull('grade_status')->orWhere('grade_status', '!=', 'manually_graded'))
            ->doesntExist();
    }

    private function letterGrade(float $percentage): ?string
    {
        if ($percentage >= 90) return 'A';
        if ($percentage >= 80) return 'B';
        if ($percentage >= 70) return 'C';
        if ($percentage >= 60) return 'D';

        return 'E';
    }
}