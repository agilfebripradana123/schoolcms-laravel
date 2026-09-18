<?php

namespace App\Http\Resources\Examination;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lean admin-facing option payload for selecting an eligible ExamAttempt when
 * generating an ExamResult. Deliberately excludes answers, question order,
 * token, events and any score/result fields.
 */
class ExamAttemptOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $exam = $this->exam;
        $participant = $this->participant;

        return [
            'id' => $this->id,
            'attempt_number' => (int) $this->attempt_number,
            'status' => $this->status,
            'started_at' => $this->started_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'has_result' => (bool) ($this->has_result ?? ($this->relationLoaded('result') && $this->result !== null)),
            'exam' => $exam ? [
                'id' => $exam->id,
                'title' => $exam->title,
                'subject' => $exam->subject ? [
                    'id' => $exam->subject->id,
                    'name' => $exam->subject->name,
                ] : null,
            ] : null,
            'participant' => $participant ? [
                'id' => $participant->id,
                'exam_card_number' => $participant->exam_card_number,
                'student' => $participant->student ? [
                    'id' => $participant->student->id,
                    'name' => $participant->student->name,
                    'nis' => $participant->student->nis,
                ] : null,
            ] : null,
        ];
    }
}