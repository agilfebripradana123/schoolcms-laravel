<?php

namespace App\Http\Resources\Development;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use App\Http\Resources\Students\StudentResource;
use App\Http\Resources\Staff\TeacherResource;
class ViolationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'category' => $this->category,
            'description' => $this->description,
            'points' => (int) $this->points,
            'violated_at' => $this->violated_at?->toDateString(),
            'handled_by' => $this->handled_by,
            'student' => new StudentResource($this->whenLoaded('student')),
            'handled_by_teacher' => new TeacherResource($this->whenLoaded('handledBy')),
            // Frontend compatibility aliases
            'student_name' => $this->student?->name,
            'date' => $this->violated_at?->toDateString(),
            'violation' => $this->category ?? $this->description,
            'level' => $this->category,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
