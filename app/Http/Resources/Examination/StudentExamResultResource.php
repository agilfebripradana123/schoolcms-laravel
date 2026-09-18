<?php

namespace App\Http\Resources\Examination;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use App\Http\Resources\Academic\SubjectResource;

/**
 * Self-service result payload for the Student portal (Phase 2B security
 * boundary). The controller only ever passes the authenticated student's own
 * participant results.
 *
 * Deliberately EXCLUDES internal/operational participant data (ip_address,
 * current_session_id, last_activity_at, blocked_reason) and any nested
 * references to other participants. `show_result` policy enforcement lives at
 * the controller layer.
 */
class StudentExamResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'participant_id' => $this->participant_id,
            'exam_attempt_id' => $this->exam_attempt_id,
            'attempt_number' => $this->whenLoaded('attempt', fn () => $this->attempt?->attempt_number, null),
            'total_score' => $this->total_score,
            'correct_count' => $this->correct_count,
            'wrong_count' => $this->wrong_count,
            'unanswered_count' => $this->unanswered_count,
            'grade' => $this->grade,
            'status' => $this->status,
            'graded_at' => $this->graded_at ? $this->graded_at->toISOString() : null,
            'exam' => $this->whenLoaded('participant', function () {
                $exam = $this->participant?->exam;
                if ($exam === null) {
                    return null;
                }

                return [
                    'id' => $exam->id,
                    'title' => $exam->title,
                    'subject' => ($exam->relationLoaded('subject') && $exam->subject !== null)
                        ? new SubjectResource($exam->subject)
                        : null,
                ];
            }),
        ];
    }
}