<?php

namespace App\Http\Resources\Examination;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use App\Http\Resources\Academic\SubjectResource;

/**
 * Self-service participant payload for the Student portal (Phase 2B security
 * boundary). The calling controller only ever passes the authenticated
 * student's own enrollment rows.
 *
 * Deliberately EXCLUDES operational/internal fields that are irrelevant (or
 * sensitive) on a self-service screen:
 *   ip_address, current_session_id, last_activity_at, blocked_reason
 * As well as any nesting of other participants.
 */
class StudentExamParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'student_id' => $this->student_id,
            'exam_card_number' => $this->exam_card_number,
            'status' => $this->status,
            'is_blocked' => (bool) $this->is_blocked,
            'login_allowed' => (bool) $this->login_allowed,
            'started_at' => $this->started_at ? $this->started_at->toISOString() : null,
            'completed_at' => $this->completed_at ? $this->completed_at->toISOString() : null,
            'exam' => $this->whenLoaded('exam', function () {
                if ($this->exam === null) {
                    return null;
                }

                return [
                    'id' => $this->exam->id,
                    'title' => $this->exam->title,
                    'subject' => ($this->exam->relationLoaded('subject') && $this->exam->subject !== null)
                        ? new SubjectResource($this->exam->subject)
                        : null,
                ];
            }),
        ];
    }
}