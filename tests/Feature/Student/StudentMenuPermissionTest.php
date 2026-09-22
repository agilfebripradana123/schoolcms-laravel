<?php

namespace Tests\Feature\Student;

use App\Models\System\Permission;
use App\Models\System\Role;
use App\Models\System\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentMenuPermissionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The 13 Portal Siswa sidebar submenu permissions (Akademik, Keuangan,
     * Aktivitas). Must stay in sync with `User::SISWA_DEFAULT_PERMISSIONS`
     * and `src/config/navigation.ts` (studentNavigation).
     */
    private const REQUIRED_STUDENT_PERMISSIONS = [
        'view-grades',
        'view-schedules',
        'view-attendance',
        'view-assignments',
        'view-exams',
        'view-finance',
        'view-billings',
        'view-payments',
        'view-transactions',
        'view-scholarships',
        'view-achievements',
        'view-violations',
        'view-extracurricular',
    ];

    public function test_permission_catalog_should_contain_all_required_student_permissions(): void
    {
        $this->seed([PermissionSeeder::class]);

        $names = Permission::pluck('name')->all();

        foreach (self::REQUIRED_STUDENT_PERMISSIONS as $name) {
            $this->assertContains($name, $names, "Permission catalog missing '{$name}'.");
        }
    }

    public function test_siswa_role_should_be_attached_to_exactly_the_student_default_permissions(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $role = Role::where('name', 'Siswa')->first();

        $this->assertNotNull($role);
        $this->assertEqualsCanonicalizing(
            self::REQUIRED_STUDENT_PERMISSIONS,
            $role->permissions->pluck('name')->all()
        );
    }

    public function test_siswa_user_effective_permissions_should_include_defaults_even_without_role_pivot(): void
    {
        $role = Role::firstOrCreate(['name' => 'Siswa']);

        $user = User::factory()->create(['role_id' => $role->id]);

        $effective = $user->effectivePermissions();

        foreach (self::REQUIRED_STUDENT_PERMISSIONS as $name) {
            $this->assertContains($name, $effective, "Effective permissions missing '{$name}'.");
        }
    }

    public function test_siswa_user_has_student_permission_after_seeding(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $role = Role::where('name', 'Siswa')->first();
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->assertTrue($user->hasPermission('view-finance'));
        $this->assertTrue($user->hasPermission('view-extracurricular'));
    }

    public function test_siswa_default_permissions_should_match_role_seeder_pivot_list(): void
    {
        $this->assertEqualsCanonicalizing(
            self::REQUIRED_STUDENT_PERMISSIONS,
            User::SISWA_DEFAULT_PERMISSIONS
        );
    }
}