<?php

namespace App\Http\Resources\Students;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use App\Http\Resources\Academic\SchoolClassResource;
use App\Http\Resources\Students\StudentResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'class_id' => $this->class_id,
            'academic_year_id' => $this->academic_year_id,
            'date' => $this->date,
            'status' => $this->status,
            'note' => $this->note,
            'student' => new StudentResource($this->whenLoaded('student')),
            'class' => new SchoolClassResource($this->whenLoaded('schoolClass')),
        ];
    }
}