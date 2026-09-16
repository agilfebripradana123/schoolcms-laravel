<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\System\Role;
use App\Models\System\Permission;

class RoleSeeder extends Seeder
{
    /**
     * Idempotent seeder — safe to run multiple times.
     * Creates default roles and attaches default permissions.
     */
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(
            ['name' => 'Administrator'],
            ['description' => 'Administrator portal sekolah.']
        );

        Role::firstOrCreate(
            ['name' => 'Guru'],
            ['description' => 'Guru pengajar.']
        );

        Role::firstOrCreate(
            ['name' => 'Siswa'],
            ['description' => 'Siswa portal sekolah.']
        );

        // Administrator: all permissions EXCEPT view-audit-logs & manage-settings
        $adminExcluded = ['view-audit-logs', 'manage-settings'];
        $adminPermIds = Permission::whereNotIn('name', $adminExcluded)->pluck('id')->all();
        $adminRole->permissions()->sync($adminPermIds);

        // Guru default permissions from User::GURU_DEFAULT_PERMISSIONS
        $guruDefaultNames = \App\Models\System\User::GURU_DEFAULT_PERMISSIONS;
        $guruPermissionIds = Permission::whereIn('name', $guruDefaultNames)->pluck('id')->all();
        $guruRole = Role::where('name', 'Guru')->first();
        if ($guruRole) {
            $guruRole->permissions()->sync($guruPermissionIds);
        }

        // Siswa read-only permissions
        $siswaDefaultNames = [
            'view-grades', 'view-schedules', 'view-attendance',
            'view-assignments', 'view-exams', 'view-finance',
            'view-billings', 'view-payments', 'view-transactions',
            'view-scholarships', 'view-achievements', 'view-violations',
            'view-extracurricular',
        ];
        $siswaPermissionIds = Permission::whereIn('name', $siswaDefaultNames)->pluck('id')->all();
        $siswaRole = Role::where('name', 'Siswa')->first();
        if ($siswaRole) {
            $siswaRole->permissions()->sync($siswaPermissionIds);
        }
    }
}
