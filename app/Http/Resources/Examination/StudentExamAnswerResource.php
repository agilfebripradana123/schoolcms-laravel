<?php

namespace App\Http\Resources\Examination;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Self-service answer payload for the Student portal (Phase 2B security
 * boundary). Ownership is enforced by the calling controller (the student may
 * only reach their own attempt answers).
 *
 * Deliberately EXCLUDES grading truth and audit metadata:
 *   is_correct, score, feedback, graded_by, graded_at, exam_attempt_id
 * A student must never learn correctness of objective questions through the
 * answer endpoint, and answer-key data stays server-side.
 */
class StudentExamAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'participant_id' => $this->participant_id,
            'question_id' => $this->question_id,
            'selected_option_id' => $this->selected_option_id,
            'essay_answer' => $this->essay_answer,
            'answered_at' => $this->answered_at ? $this->answered_at->toISOString() : null,
        ];
    }
}