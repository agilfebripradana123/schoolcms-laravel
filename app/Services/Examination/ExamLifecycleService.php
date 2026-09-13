<?php

namespace App\Services\Examination;

use App\Models\Examination\Exam;
use App\Models\Examination\ExamQuestion;

/**
 * Exam lifecycle rules (Phase 2E).
 *
 * Forward-only lifecycle:
 *   draft → published → ongoing → completed → archived
 *
 * The repository stores `status` as a plain string field updated through
 * ExamController::update, so this service is the single backend authority that
 * validates every requested transition and the publish-readiness of the exam
 * (explicit composition required + composition consistency).
 */
class ExamLifecycleService
{
    private const ALLOWED_TRANSITIONS = [
        'draft' => ['published'],
        'published' => ['ongoing'],
        'ongoing' => ['completed'],
        'completed' => ['archived'],
        'archived' => [],
    ];

    public function isAllowedTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Publish-readiness (draft -> published) validation.
     *
     * Returns ['ok' => bool, 'errors' => array]. No mutation is performed:
     * composition must already be consistent before the exam is published.
     */
    public function validatePublishable(Exam $exam): array
    {
        $errors = [];

        $compositions = ExamQuestion::with('question')
            ->where('exam_id', $exam->id)
            ->orderBy('position')
            ->get();

        if ($compositions->isEmpty()) {
            $errors[] = 'An exam needs at least one question (composition) before it can be published.';

            return ['ok' => false, 'errors' => $errors];
        }

        $seenQuestions = [];

        foreach ($compositions as $composition) {
            $question = $composition->question;

            if ($question === null) {
                $errors[] = sprintf('Question #%d is missing or deleted.', $composition->question_id);
                continue;
            }

            $label = $question->code ?: ('#'.$question->id);

            if ((int) $question->subject_id !== (int) $exam->subject_id) {
                $errors[] = sprintf('Question %s subject does not match the exam subject.', $label);
            }

            if ($question->status !== 'approved') {
                $errors[] = sprintf('Question %s is not approved for composition.', $label);
            }

            if ((int) $composition->position < 1) {
                $errors[] = sprintf('Question %s has an invalid position (%d).', $label, (int) $composition->position);
            }

            if ((int) $composition->points < 1) {
                $errors[] = sprintf('Question %s has an invalid weight (%d).', $label, (int) $composition->points);
            }

            $seenQuestions[] = (int) $composition->question_id;
        }

        if (count($seenQuestions) !== count(array_unique($seenQuestions))) {
            $errors[] = 'The exam composition contains duplicate questions.';
        }

        return ['ok' => empty($errors), 'errors' => $errors];
    }
}