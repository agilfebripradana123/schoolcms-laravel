<?php

namespace App\Http\Resources\Academic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use App\Http\Resources\Students\StudentResource;
class GradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'subject_id' => $this->subject_id,
            'class_id' => $this->class_id,
            'type' => $this->type,
            'score' => $this->score,
            'semester' => $this->semester,
            'academic_year' => $this->academic_year,
            'semester_id' => $this->semester_id,
            'academic_year_id' => $this->academic_year_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'student' => new StudentResource($this->whenLoaded('student')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'class' => new SchoolClassResource($this->whenLoaded('schoolClass')),
        ];
    }
}
