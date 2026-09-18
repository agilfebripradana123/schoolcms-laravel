<?php

namespace App\Http\Resources\Students;

use App\Http\Resources\Academic\AcademicYearResource;
use App\Http\Resources\Academic\SchoolClassResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'class_id' => $this->class_id,
            'academic_year_id' => $this->academic_year_id,
            'status' => $this->status,
            'notes' => $this->notes,
            'is_final' => (bool) $this->is_final,
            'finalized_at' => $this->finalized_at?->toISOString(),
            'finalized_by' => $this->finalized_by,
            'student' => new StudentResource($this->whenLoaded('student')),
            'class' => new SchoolClassResource($this->whenLoaded('schoolClass')),
            'academic_year' => new AcademicYearResource($this->whenLoaded('academicYear')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
