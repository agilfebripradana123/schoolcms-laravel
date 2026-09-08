<?php

namespace App\Http\Resources\Teachers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'day' => $this->day,
            'period' => $this->whenLoaded('period', function () {
                return $this->period ? [
                    'id' => $this->period->id,
                    'name' => $this->period->name,
                    'start_time' => $this->period->start_time,
                    'end_time' => $this->period->end_time,
                ] : null;
            }),
            'subject' => $this->whenLoaded('subject', function () {
                return $this->subject ? [
                    'id' => $this->subject->id,
                    'name' => $this->subject->name,
                ] : null;
            }),
            'class' => $this->whenLoaded('schoolClass', function () {
                return $this->schoolClass ? [
                    'id' => $this->schoolClass->id,
                    'name' => $this->schoolClass->name,
                    'level' => $this->schoolClass->level,
                ] : null;
            }),
            'academic_year' => $this->whenLoaded('academicYear', function () {
                return $this->academicYear ? [
                    'id' => $this->academicYear->id,
                    'name' => $this->academicYear->name,
                ] : null;
            }),
            'semester' => $this->whenLoaded('semester', function () {
                return $this->semester ? [
                    'id' => $this->semester->id,
                    'name' => $this->semester->name,
                ] : null;
            }),
        ];
    }
}
