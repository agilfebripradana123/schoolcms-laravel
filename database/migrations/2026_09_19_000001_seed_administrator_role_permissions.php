<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SUPER_ADMIN_ONLY = [
        'view-audit-logs',
        'manage-settings',
    ];

    public function up(): void
    {
        $role = DB::table('roles')->where('name', 'Administrator')->first();
        if (!$role) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereNotIn('name', self::SUPER_ADMIN_ONLY)
            ->whereNotExists(function ($query) use ($role) {
                $query->select(DB::raw(1))
                    ->from('permission_role')
                    ->whereColumn('permission_role.permission_id', 'permissions.id')
                    ->where('permission_role.role_id', $role->id);
            })
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('permission_role')->insert([
                'permission_id' => $permissionId,
                'role_id' => $role->id,
            ]);
        }
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('name', 'Administrator')->first();
        if (!$role) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereNotIn('name', self::SUPER_ADMIN_ONLY)
            ->pluck('id');

        DB::table('permission_role')
            ->where('role_id', $role->id)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }
};