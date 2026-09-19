<?php

namespace App\Http\Resources\Examination;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // B20-F2-F2: read-only, derived grade-sync signals. A result is stale
        // when it still feeds an academic grade but is no longer the effective
        // result (a newer submitted attempt, or a competing finalization, won).
        // The effective result itself reports grade_synced=false until it is
        // (re)synchronized. Nothing here mutates data.
        $scoring = app(\App\Services\Examination\ExamScoringService::class);
        $integration = app(\App\Services\Examination\ExamGradeIntegrationService::class);
        $isEffective = $scoring->isEffectiveResult($this->resource);
        $isSynced = $integration->isSynced($this->resource);

        return [
            'id' => $this->id,
            'participant_id' => $this->participant_id,
            'exam_attempt_id' => $this->exam_attempt_id,
            'attempt_number' => $this->whenLoaded('attempt', fn () => $this->attempt?->attempt_number, null),
            'participant' => new ExamParticipantResource($this->whenLoaded('participant')),
            'total_score' => $this->total_score,
            'correct_count' => $this->correct_count,
            'wrong_count' => $this->wrong_count,
            'unanswered_count' => $this->unanswered_count,
            'grade' => $this->grade,
            'status' => $this->status,
            'is_final' => (bool) $this->is_final,
            'finalized_at' => $this->finalized_at?->toISOString(),
            'is_effective' => $isEffective,
            'grade_synced' => $isSynced,
            'grade_stale' => $isSynced && ! $isEffective,
            'graded_at' => $this->graded_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
