<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\System\StoreRoleRequest;
use App\Http\Requests\Api\System\SyncRolePermissionsRequest;
use App\Http\Requests\Api\System\UpdateRoleRequest;
use App\Http\Resources\System\RoleResource;
use App\Models\System\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    private const SYSTEM_ROLES = ['Admin', 'Administrator', 'Super Admin'];

    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);
        $query = Role::query()->with('permissions');

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        $perPage = $validated['per_page'] ?? 10;
        $roles = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Roles retrieved successfully',
            'data' => RoleResource::collection($roles),
            'meta' => [
                'current_page' => $roles->currentPage(),
                'per_page' => $roles->perPage(),
                'total' => $roles->total(),
                'last_page' => $roles->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $role = Role::with('permissions')->find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Role retrieved successfully',
            'data' => new RoleResource($role),
        ]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $role = DB::connection('mysql')->transaction(function () use ($validated) {
            $permissionIds = $validated['permission_ids'] ?? null;
            unset($validated['permission_ids']);

            $role = Role::create($validated);

            if (!is_null($permissionIds)) {
                $role->permissions()->sync($permissionIds);
            }

            return $role;
        });

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'data' => new RoleResource($role->fresh(['permissions'])),
        ], 201);
    }

    public function update(UpdateRoleRequest $request, int $id): JsonResponse
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
                'data' => null,
            ], 404);
        }

        if (in_array($role->name, self::SYSTEM_ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'System role cannot be modified',
                'data' => null,
            ], 403);
        }

        $validated = $request->validated();

        DB::connection('mysql')->transaction(function () use ($role, $validated) {
            $permissionIds = $validated['permission_ids'] ?? null;
            unset($validated['permission_ids']);

            $role->update($validated);

            if (!is_null($permissionIds)) {
                $role->permissions()->sync($permissionIds);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'data' => new RoleResource($role->fresh(['permissions'])),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
                'data' => null,
            ], 404);
        }

        if (in_array($role->name, self::SYSTEM_ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'System role cannot be modified',
                'data' => null,
            ], 403);
        }

        if ($role->users()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete role: still assigned to users',
                'data' => null,
            ], 409);
        }

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully',
            'data' => null,
        ]);
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, int $id): JsonResponse
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
                'data' => null,
            ], 404);
        }

        if (in_array($role->name, self::SYSTEM_ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'System role cannot be modified',
                'data' => null,
            ], 403);
        }

        $validated = $request->validated();

        $role->permissions()->sync($validated['permission_ids']);

        return response()->json([
            'success' => true,
            'message' => 'Permissions synced successfully',
            'data' => new RoleResource($role->fresh(['permissions'])),
        ]);
    }
}
