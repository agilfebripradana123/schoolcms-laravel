<?php

namespace App\Http\Resources\System;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $roleData = null;
        if ($this->relationLoaded('role') && $this->role) {
            $roleData = new \App\Http\Resources\System\RoleResource($this->role);
            $roleArray = $roleData->toArray($request);

            // Inject default permissions into role.permissions so the frontend
            // "Hak akses dari Role" section is never empty when permission_role
            // has no rows. Guru gets GURU_DEFAULT_PERMISSIONS, Administrator
            // gets the full catalog.
            $roleName = strtolower($this->role->name);
            if ($roleName === 'guru') {
                $defaultNames = \App\Models\System\User::GURU_DEFAULT_PERMISSIONS;
                $defaultPerms = \App\Models\System\Permission::whereIn('name', $defaultNames)->get();
                $roleArray['permissions'] = \App\Http\Resources\System\PermissionResource::collection($defaultPerms);
            } elseif ($roleName === 'administrator') {
                $allPerms = \App\Models\System\Permission::whereNotIn('name', ['view-audit-logs', 'manage-settings'])->orderBy('name')->get();
                $roleArray['permissions'] = \App\Http\Resources\System\PermissionResource::collection($allPerms);
            }

            $roleData = $roleArray;
        }

        return [
            'id' => $this->id,
            'role_id' => $this->role_id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'photo' => $this->photo,
            'is_active' => $this->is_active,
            'role' => $roleData,
            'permissions' => \App\Http\Resources\System\PermissionResource::collection($this->whenLoaded('permissions')),
        ];
    }
}
