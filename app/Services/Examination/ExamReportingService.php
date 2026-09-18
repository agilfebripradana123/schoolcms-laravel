<?php

namespace App\Services\Examination;

use App\Models\Examination\ExamAnswer;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamAttemptQuestion;
use App\Models\Examination\ExamParticipant;
use App\Models\Examination\ExamResult;

/**
 * Read-only Examination reporting foundation (Phase B15).
 *
 * Produces exam-level and question-level aggregates WITHOUT mutating any
 * examination data. Effective-result semantics are reused from the existing
 * contract (latest submitted attempt per participant+exam, deterministic
 * tie-break by attempt id). Never reads the mutable live QuestionBank for
 * historical correctness — question statistics come exclusively from the
 * frozen attempt snapshot.
 */
class ExamReportingService
{
    /**
     * Exam-level aggregate summary (effective-semantics).
     *
     * Effective attempts are selected in the database (NOT in PHP) with a
     * self-join anti-join: an attempt is effective when no other submitted
     * attempt of the same exam+participant has a later submitted_at, or an
     * equal submitted_at with a larger id.
     *
     * @return array{exam: array, summary: array}
     */
    public function examSummary(int $examId): array
    {
        $exam = \App\Models\Examination\Exam::with('subject')->find($examId);

        if (! $exam) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Exam not found.');
        }

        $participantCount = ExamParticipant::where('exam_id', $examId)
            ->distinct()
            ->count('student_id');

        // Attempt counts by persisted status.
        $statusCounts = ExamAttempt::where('exam_id', $examId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $attemptCount = array_sum($statusCounts);
        $submittedCount = (int) ($statusCounts['submitted'] ?? 0);
        $incompleteCount = (int) ($statusCounts['active'] ?? 0) + (int) ($statusCounts['expired'] ?? 0);

        // Effective attempt ids — latest submitted attempt per participant+exam.
        $effectiveIds = ExamAttempt::where('exam_id', $examId)
            ->where('status', 'submitted')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('exam_attempts as effective_other')
                    ->whereColumn('effective_other.exam_id', 'exam_attempts.exam_id')
                    ->whereColumn('effective_other.exam_participant_id', 'exam_attempts.exam_participant_id')
                    ->where('effective_other.status', 'submitted')
                    ->where(function ($tie) {
                        $tie->whereColumn('effective_other.submitted_at', '>', 'exam_attempts.submitted_at')
                            ->orWhere(function ($equalTs) {
                                $equalTs->whereColumn('effective_other.submitted_at', 'exam_attempts.submitted_at')
                                    ->whereColumn('effective_other.id', '>', 'exam_attempts.id');
                            });
                    });
            })
            ->pluck('id');

        $effectiveAttemptCount = $effectiveIds->count();

        // Effective percentages come ONLY from result rows of effective attempts;
        // submitted attempts without a result are not invented.
        $percentRow = ExamResult::whereIn('exam_attempt_id', $effectiveIds)
            ->selectRaw('AVG(percentage) as avg_p, MIN(percentage) as min_p, MAX(percentage) as max_p')
            ->first();

        return [
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'subject_id' => $exam->subject_id,
                'subject_name' => $exam->subject?->name,
                'status' => $exam->status,
            ],
            'summary' => [
                'participant_count' => $participantCount,
                'attempt_count' => $attemptCount,
                'submitted_attempt_count' => $submittedCount,
                'effective_attempt_count' => $effectiveAttemptCount,
                'incomplete_attempt_count' => $incompleteCount,
                'average_percentage' => $percentRow && $percentRow->avg_p !== null ? round((float) $percentRow->avg_p, 2) : null,
                'minimum_percentage' => $percentRow && $percentRow->min_p !== null ? round((float) $percentRow->min_p, 2) : null,
                'maximum_percentage' => $percentRow && $percentRow->max_p !== null ? round((float) $percentRow->max_p, 2) : null,
            ],
        ];
    }

    /**
     * Question-level aggregate from the frozen attempt snapshot.
     *
     * Strategy: three batched queries constrained to the exam (via attempt
     * relationship, no unbounded in-lists and no per-participant loops), then
     * in-memory grouping by snapshot source question.
     */
    public function questionSummary(int $examId): array
    {
        $attemptQuestions = ExamAttemptQuestion::with('options')
            ->whereHas('attempt', fn ($q) => $q->where('exam_id', $examId))
            ->orderBy('position')
            ->get();

        // Map snapshot option id -> source (live) option id so option
        // distribution aggregates the same logical option across attempts.
        $optionSource = [];
        foreach ($attemptQuestions as $aq) {
            foreach ($aq->options as $option) {
                $optionSource[$option->id] = $option->source_option_id ?? $option->id;
            }
        }

        $answers = ExamAnswer::whereHas('attempt', fn ($q) => $q->where('exam_id', $examId))
            ->get()
            ->keyBy('attempt_question_id');

        $questions = [];

        foreach ($attemptQuestions->groupBy('source_question_id') as $sourceId => $group) {
            /** @var ExamAttemptQuestion $rep */
            $rep = $group->first();
            $isEssay = $rep->question_type === 'essay';

            $attemptsTotal = $group->count();
            $answered = 0;
            $correct = 0;
            $incorrect = 0;
            $pendingManual = 0;
            $manuallyGraded = 0;
            $gradedScores = [];
            $optionCounts = [];

            foreach ($group as $aq) {
                $answer = $answers->get($aq->id);

                if ($answer === null) {
                    continue;
                }

                if ($isEssay) {
                    if ($answer->essay_answer !== null && $answer->essay_answer !== '') {
                        $answered++;
                    }
                    if ($answer->grade_status === 'pending_manual') {
                        $pendingManual++;
                    } elseif ($answer->grade_status === 'manually_graded') {
                        $manuallyGraded++;
                        if ($answer->score !== null) {
                            $gradedScores[] = (float) $answer->score;
                        }
                    }
                    continue;
                }

                if ($answer->selected_attempt_option_id !== null) {
                    $answered++;
                    $optionKey = $optionSource[$answer->selected_attempt_option_id] ?? $answer->selected_attempt_option_id;
                    $optionCounts[$optionKey] = ($optionCounts[$optionKey] ?? 0) + 1;
                }

                if ($answer->is_correct === true) {
                    $correct++;
                } elseif ($answer->selected_attempt_option_id !== null) {
                    $incorrect++;
                }
            }

            $options = $rep->options
                ->sortBy('position')
                ->values()
                ->map(function ($option) use ($optionCounts) {
                    $key = $option->source_option_id ?? $option->id;

                    return [
                        'option_id' => $option->id,
                        'option_text' => $option->option_text,
                        'position' => $option->position,
                        'selected_count' => $optionCounts[$key] ?? 0,
                    ];
                })
                ->values()
                ->all();

            $questions[] = [
                'question_id' => $rep->id,
                'source_question_id' => $sourceId,
                'position' => $rep->position,
                'question_text' => $rep->question_text,
                'type' => $rep->question_type,
                'points' => (int) $rep->points,
                'attempts_total' => $attemptsTotal,
                'answered' => $answered,
                'unanswered' => $attemptsTotal - $answered,
                'correct' => $isEssay ? null : $correct,
                'incorrect' => $isEssay ? null : $incorrect,
                'correctness_percentage' => $isEssay || $attemptsTotal === 0
                    ? null
                    : round(($correct / $attemptsTotal) * 100, 2),
                'option_distribution' => $isEssay ? [] : $options,
                'essay' => $isEssay ? [
                    'pending_manual' => $pendingManual,
                    'manually_graded' => $manuallyGraded,
                    'average_score' => count($gradedScores) > 0 ? round(array_sum($gradedScores) / count($gradedScores), 2) : null,
                ] : null,
            ];
        }

        // Stable ordering by snapshot position.
        usort($questions, fn ($a, $b) => $a['position'] <=> $b['position']);

        return [
            'exam_id' => $examId,
            'questions' => $questions,
        ];
    }
}