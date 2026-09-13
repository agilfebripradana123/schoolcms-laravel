<?php

namespace App\Http\Resources\Examination;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use App\Http\Resources\Academic\SubjectResource;
class ExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject_id' => $this->subject_id,
            'class_id' => $this->class_id,
            'academic_year_id' => $this->academic_year_id,
            'semester_id' => $this->semester_id,
            'teacher_id' => $this->teacher_id,
            'exam_type' => $this->exam_type,
            'title' => $this->title,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'total_questions' => $this->total_questions,
            'passing_score' => $this->passing_score,
            'max_attempts' => $this->max_attempts,
            'shuffle_questions' => (bool) $this->shuffle_questions,
            'shuffle_options' => (bool) $this->shuffle_options,
            'show_result' => (bool) $this->show_result,
            'status' => $this->status,
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
