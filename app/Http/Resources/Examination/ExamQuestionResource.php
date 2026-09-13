<?php

namespace App\Http\Resources\Examination;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Management resource for an explicit exam composition row.
 *
 * Embedded question metadata is deliberately LEAN: composition screens need
 * the question stem/identity, never the answer key. Answer keys
 * (options.*.is_correct, explanation) are only exposed by
 * QuestionBankResource, which is already gated behind admin management
 * endpoints. This resource stays safe even if accidentally reused.
 */
class ExamQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'question_id' => $this->question_id,
            'blueprint_item_id' => $this->blueprint_item_id,
            'position' => $this->position,
            'points' => $this->points,
            'question' => $this->whenLoaded('question', function () {
                if ($this->question === null) {
                    return null;
                }

                return [
                    'id' => $this->question->id,
                    'code' => $this->question->code,
                    'question_text' => $this->question->question_text,
                    'type' => $this->question->type,
                    'difficulty' => $this->question->difficulty,
                    'points' => (int) $this->question->points,
                    'status' => $this->question->status,
                ];
            }),
            'created_at' => $this->created_at ? $this->created_at->toISOString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
        ];
    }
}