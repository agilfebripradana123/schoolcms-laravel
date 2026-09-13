<?php

namespace App\Http\Resources\System;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackupLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'status' => $this->status,
            'file_path' => $this->file_path,
            'file_size' => $this->file_size,
            'type' => $this->type,
            'description' => $this->description,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}