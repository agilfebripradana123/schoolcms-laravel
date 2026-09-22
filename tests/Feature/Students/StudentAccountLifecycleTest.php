<?php

namespace Tests\Feature\Students;

use App\Models\Students\Student;
use App\Models\System\AuditLog;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Stage B — Student login account lifecycle (Admin → Data Siswa → Edit Siswa).
 *
 * Hermetic sqlite :memory: suite: never touches the live MySQL database.
 * Exercises the real /api/students/{student}/account endpoint + RoleMiddleware
 * exactly like the existing PPDB / grade test suites.
 */
class StudentAccountLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
    }

    private function buildSchema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
            $t->primary(['permission_id', 'role_id']);
        });
        Schema::create('permission_user', function (Blueprint $t) {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('user_id');
            $t->primary(['permission_id', 'user_id']);
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->nullable();
            $t->string('username')->nullable()->unique();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('photo')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->unique();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->string('nisn');
            $t->string('nis');
            $t->string('name');
            $t->string('gender', 1);
            $t->string('birth_place');
            $t->date('birth_date');
            $t->text('address');
            $t->string('phone')->nullable();
            $t->string('photo')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('user_id')->nullable();
            $t->string('action', 50);
            $t->string('model', 100)->nullable();
            $t->unsignedInteger('model_id')->nullable();
            $t->text('description');
            $t->string('ip_address', 45);
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    private function createRole(string $name): Role
    {
        return Role::create(['name' => $name]);
    }

    private function roleId(string $name): ?int
    {
        return Role::where('name', $name)->value('id');
    }

    private function actingAdmin(): User
    {
        $user = User::create([
            'role_id' => $this->createRole('Admin')->id,
            'name' => 'Admin Test',
            'email' => 'admin-'.uniqid().'@test.local',
            'password' => 'password',
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingGuru(): User
    {
        $user = User::create([
            'role_id' => $this->createRole('Guru')->id,
            'name' => 'Guru Test',
            'email' => 'guru-'.uniqid().'@test.local',
            'password' => 'password',
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingSiswa(): User
    {
        $user = User::create([
            'role_id' => $this->createRole('Siswa')->id,
            'name' => 'Siswa Test',
            'email' => 'siswa-'.uniqid().'@test.local',
            'password' => 'password',
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createStudent(array $overrides = []): Student
    {
        return Student::create(array_merge([
            'nisn' => '0012345678',
            'nis' => 'NIS-2026-0001',
            'name' => 'Siswa Tes',
            'gender' => 'L',
            'birth_place' => 'Jakarta',
            'birth_date' => '2010-05-01',
            'address' => 'Jl. Merdeka No. 1',
        ], $overrides));
    }

    private function linkedStudent(string $nisn = '0012345678', string $email = 'linked@test.local', string $password = 'secret123'): array
    {
        $student = $this->createStudent(['nisn' => $nisn]);
        $user = User::create([
            'role_id' => $this->roleId('Siswa') ?? $this->createRole('Siswa')->id,
            'username' => $nisn,
            'name' => $student->name,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);
        $student->user_id = $user->id;
        $student->save();

        return [$student, $user];
    }

    public function test_admin_can_provision_student_account(): void
    {
        $this->actingAdmin();
        $this->createRole('Siswa');
        $student = $this->createStudent(['nisn' => '0011223344']);

        $this->putJson("/api/students/{$student->id}/account", [
            'is_active' => true,
            'email' => 'siswa-provision@test.local',
            'password' => 'rahasia-baru',
        ])->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.user.username', '0011223344');

        $user = User::where('username', '0011223344')->first();
        $this->assertNotNull($user);
        $this->assertSame('Siswa', $user->role->name);
        $this->assertTrue((bool) $user->is_active);
        $this->assertSame($user->id, $student->fresh()->user_id);
        $this->assertTrue(Hash::check('rahasia-baru', $user->password));
        $this->assertNotSame('rahasia-baru', $user->password);
    }

    public function test_disable_sets_inactive_and_purges_tokens(): void
    {
        $this->actingAdmin();
        [$student, $user] = $this->linkedStudent();

        $user->createToken('schoolcms');
        $this->assertSame(1, $user->tokens()->count());

        $this->putJson("/api/students/{$student->id}/account", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse((bool) $user->fresh()->is_active);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_enable_existing_account_ignores_password_and_email(): void
    {
        $this->actingAdmin();
        [$student, $user] = $this->linkedStudent();

        $user->update(['is_active' => false]);

        $this->putJson("/api/students/{$student->id}/account", [
            'is_active' => true,
            'email' => 'changed@test.local',
            'password' => 'changed-password',
        ])->assertOk()->assertJsonPath('data.is_active', true);

        $fresh = $user->fresh();
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertSame('linked@test.local', $fresh->email);
        $this->assertTrue(Hash::check('secret123', $fresh->password));
        $this->assertSame(1, User::where('role_id', $fresh->role_id)->count());
    }

    public function test_enable_is_idempotent(): void
    {
        $this->actingAdmin();
        [$student, $user] = $this->linkedStudent();

        $this->putJson("/api/students/{$student->id}/account", ['is_active' => true])->assertOk();
        $this->putJson("/api/students/{$student->id}/account", ['is_active' => true])->assertOk();

        $this->assertSame(1, User::where('role_id', $user->role_id)->count());
        $this->assertSame($user->id, $student->fresh()->user_id);
        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_disable_is_idempotent(): void
    {
        $this->actingAdmin();
        [$student, $user] = $this->linkedStudent();

        $this->putJson("/api/students/{$student->id}/account", ['is_active' => false])->assertOk();
        $this->putJson("/api/students/{$student->id}/account", ['is_active' => false])->assertOk();

        $this->assertFalse((bool) $user->fresh()->is_active);
        $this->assertSame(1, User::where('role_id', $user->role_id)->count());
    }

    public function test_active_student_login_succeeds(): void
    {
        [$student, $user] = $this->linkedStudent('0022001122', 'login-active@test.local', 'kata-sandi');

        $this->postJson('/api/login', [
            'login' => '0022001122',
            'password' => 'kata-sandi',
            'expected_role' => 'siswa',
        ])->assertOk()
            ->assertJsonStructure(['token', 'user' => ['role', 'permissions']])
            ->assertJsonPath('user.username', '0022001122');
    }

    public function test_disabled_student_login_rejected(): void
    {
        $this->actingAdmin();
        [$student, $user] = $this->linkedStudent('0022001133', 'login-inactive@test.local', 'kata-sandi');

        $this->putJson("/api/students/{$student->id}/account", ['is_active' => false])->assertOk();

        $this->postJson('/api/login', [
            'login' => '0022001133',
            'password' => 'kata-sandi',
            'expected_role' => 'siswa',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Akun Anda tidak aktif.');
    }

    public function test_nisn_update_syncs_username(): void
    {
        $this->actingAdmin();
        [$student, $user] = $this->linkedStudent('0000000001');

        $this->putJson("/api/students/{$student->id}", [
            'nisn' => '0000000002',
            'nis' => $student->nis,
            'name' => $student->name,
            'gender' => $student->gender,
            'birth_place' => $student->birth_place,
            'birth_date' => $student->birth_date,
            'address' => $student->address,
        ])->assertOk();

        $this->assertSame('0000000002', $student->fresh()->nisn);
        $this->assertSame('0000000002', $user->fresh()->username);
    }

    public function test_nisn_collision_blocks_account_creation(): void
    {
        $this->actingAdmin();
        $this->createRole('Siswa');
        User::create([
            'role_id' => $this->roleId('Siswa'),
            'username' => '0099887766',
            'name' => 'Pemilik Username',
            'email' => 'owner@test.local',
            'password' => 'password',
        ]);
        $student = $this->createStudent(['nisn' => '0099887766']);

        $this->putJson("/api/students/{$student->id}/account", [
            'is_active' => true,
            'email' => 'new-owner@test.local',
            'password' => 'password123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('nisn');

        $this->assertNull($student->fresh()->user_id);
        $this->assertNull(User::where('email', 'new-owner@test.local')->first());
    }

    public function test_nisn_collision_blocks_nisn_update_with_rollback(): void
    {
        $this->actingAdmin();
        $this->createRole('Siswa');
        [$student, $user] = $this->linkedStudent('0000000011');
        User::create([
            'role_id' => $this->roleId('Siswa'),
            'username' => '0000000022',
            'name' => 'Pemilik Username Lain',
            'email' => 'owner-2@test.local',
            'password' => 'password',
        ]);

        $this->putJson("/api/students/{$student->id}", [
            'nisn' => '0000000022',
            'nis' => $student->nis,
            'name' => $student->name,
            'gender' => $student->gender,
            'birth_place' => $student->birth_place,
            'birth_date' => $student->birth_date,
            'address' => $student->address,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('nisn');

        $this->assertSame('0000000011', $student->fresh()->nisn);
        $this->assertSame('0000000011', $user->fresh()->username);
    }

    public function test_email_collision_rejected(): void
    {
        $this->actingAdmin();
        $this->createRole('Siswa');
        User::create([
            'role_id' => $this->roleId('Siswa'),
            'username' => 'username-a',
            'name' => 'Pemilik Email',
            'email' => 'dup-email@test.local',
            'password' => 'password',
        ]);
        $student = $this->createStudent(['nisn' => '0055778899']);

        $this->putJson("/api/students/{$student->id}/account", [
            'is_active' => true,
            'email' => 'dup-email@test.local',
            'password' => 'password123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertNull($student->fresh()->user_id);
        $this->assertSame(1, User::where('email', 'dup-email@test.local')->count());
        $this->assertSame('username-a', User::where('email', 'dup-email@test.local')->first()->username);
    }

    public function test_email_required_when_provisioning(): void
    {
        $this->actingAdmin();
        $student = $this->createStudent(['nisn' => '0044332211']);

        $this->putJson("/api/students/{$student->id}/account", [
            'is_active' => true,
            'password' => 'password123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertNull($student->fresh()->user_id);
    }

    public function test_password_required_when_provisioning(): void
    {
        $this->actingAdmin();
        $student = $this->createStudent(['nisn' => '0044332211']);

        $this->putJson("/api/students/{$student->id}/account", [
            'is_active' => true,
            'email' => 'no-pass@test.local',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertNull($student->fresh()->user_id);
        $this->assertNull(User::where('email', 'no-pass@test.local')->first());
    }

    public function test_guru_cannot_manage_student_account(): void
    {
        $this->actingGuru();
        $student = $this->createStudent();

        $this->putJson("/api/students/{$student->id}/account", ['is_active' => false])
            ->assertStatus(403);

        $this->assertNull($student->fresh()->user_id);
    }

    public function test_student_cannot_manage_student_account(): void
    {
        $this->actingSiswa();
        $student = $this->createStudent();

        $this->putJson("/api/students/{$student->id}/account", ['is_active' => false])
            ->assertStatus(403);
    }

    public function test_audit_log_records_create_disable_enable_without_secrets(): void
    {
        $this->actingAdmin();
        $this->createRole('Siswa');

        $student = $this->createStudent(['nisn' => '0000765432']);

        $this->putJson("/api/students/{$student->id}/account", [
            'is_active' => true,
            'email' => 'audit@test.local',
            'password' => 'password-baru',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student_account_created',
            'model' => 'Student',
            'model_id' => $student->id,
        ]);

        $created = AuditLog::where('action', 'student_account_created')->first();
        $this->assertNotNull($created);
        $this->assertSame('audit@test.local', json_decode($created->description, true)['email']);

        $this->putJson("/api/students/{$student->id}/account", ['is_active' => false])->assertOk();
        $this->putJson("/api/students/{$student->id}/account", ['is_active' => true])->assertOk();

        $enabled = AuditLog::where('action', 'student_account_enabled')->latest('id')->first();
        $disabled = AuditLog::where('action', 'student_account_disabled')->latest('id')->first();

        $this->assertNotNull($enabled);
        $this->assertNotNull($disabled);
        $this->assertSame(true, json_decode($enabled->description, true)['new_is_active']);
        $this->assertSame(false, json_decode($disabled->description, true)['new_is_active']);

        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString('password', strtolower($log->description));
            $this->assertStringNotContainsString('token', strtolower($log->description));
        }
    }
}