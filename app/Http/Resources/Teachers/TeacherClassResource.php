<?php

namespace App\Http\Resources\Teachers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'teacher_id' => $this->teacher_id,
            'level' => $this->level,
            'academic_year' => $this->academic_year,
            'students_count' => $this->students_count ?? 0,
            'wali_kelas' => $this->whenLoaded('teacher', function () {
                return $this->teacher ? [
                    'id' => $this->teacher->id,
                    'full_name' => $this->teacher->full_name,
                ] : null;
            }),
        ];
    }
}
