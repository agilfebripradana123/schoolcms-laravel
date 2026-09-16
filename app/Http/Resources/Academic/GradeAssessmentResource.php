<?php

namespace App\Http\Resources\Academic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradeAssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'student_name' => $this->whenLoaded('student') ? $this->student->name : null,
            'subject_id' => $this->subject_id,
            'subject_name' => $this->whenLoaded('subject') ? $this->subject->name : null,
            'class_id' => $this->class_id,
            'class_name' => $this->whenLoaded('schoolClass') ? $this->schoolClass->name : null,
            'academic_year_id' => $this->academic_year_id,
            'academic_year' => $this->whenLoaded('academicYear') ? $this->academicYear->name : null,
            'semester_id' => $this->semester_id,
            'semester' => $this->whenLoaded('semester') ? $this->semester->name : null,
            'assessment_category' => $this->assessment_category,
            'assessment_sequence' => $this->assessment_sequence,
            'assessment_name' => $this->assessment_name,
            'score' => $this->score,
            'max_score' => $this->max_score,
            'weight' => $this->weight,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'assessed_date' => $this->assessed_date?->toISOString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}