<?php

namespace App\Http\Resources\Development;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use App\Http\Resources\Students\StudentResource;
class AchievementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'title' => $this->title,
            'level' => $this->level,
            'organizer' => $this->organizer,
            'achievement_date' => $this->achievement_date?->toDateString(),
            'description' => $this->description,
            'student' => new StudentResource($this->whenLoaded('student')),
            // Frontend compatibility aliases for portal guru
            'student_name' => $this->student?->name,
            'achievement' => $this->title,
            'date' => $this->achievement_date?->toDateString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
