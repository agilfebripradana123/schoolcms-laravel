<?php

namespace App\Http\Resources\Examination;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamAttemptMonitoringResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $now = now();
        $remainingSeconds = null;
        if ($this->expires_at && $this->status === 'active') {
            $remainingSeconds = max(0, $now->diffInSeconds($this->expires_at, false));
            if ($remainingSeconds < 0) {
                $remainingSeconds = 0;
            }
        }

        $totalQuestions = is_array($this->question_order) ? count($this->question_order) : 0;
        $answeredCount = $this->whenLoaded('answers', fn() => $this->answers->count(), 0);
        $unansweredCount = max(0, $totalQuestions - $answeredCount);
        $progress = $totalQuestions > 0 ? round(($answeredCount / $totalQuestions) * 100, 2) : 0;

        $eventCount = $this->whenLoaded('events', fn() => $this->events->count(), 0);

        return [
            'id' => $this->id,
            'attempt_number' => (int) $this->attempt_number,
            'status' => $this->status,
            'started_at' => $this->started_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'remaining_seconds' => $remainingSeconds,
            'exam' => [
                'id' => $this->exam->id ?? null,
                'title' => $this->exam->title ?? null,
                'subject' => [
                    'id' => $this->exam->subject->id ?? null,
                    'name' => $this->exam->subject->name ?? null,
                ],
            ],
            'student' => [
                'id' => $this->participant->student->id ?? null,
                'name' => $this->participant->student->name ?? null,
                'nis' => $this->participant->student->nis ?? null,
            ],
            'participant' => [
                'id' => $this->participant->id ?? null,
                'exam_card_number' => $this->participant->exam_card_number ?? null,
                'is_blocked' => $this->participant->is_blocked ?? false,
            ],
            'progress' => [
                'total_questions' => $totalQuestions,
                'answered' => $answeredCount,
                'unanswered' => $unansweredCount,
                'percentage' => $progress,
            ],
            'event_count' => $eventCount,
        ];
    }
}
