<?php

namespace App\Http\Resources\Teachers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherClassStudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_id' => $this->class_id,
            'student_id' => $this->student_id,
            'status' => $this->status,
            'student' => $this->whenLoaded('student', function () {
                return $this->student ? [
                    'id' => $this->student->id,
                    'nisn' => $this->student->nisn,
                    'nis' => $this->student->nis,
                    'name' => $this->student->name,
                    'gender' => $this->student->gender,
                ] : null;
            }),
        ];
    }
}
