<?php

namespace Tests\Feature;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Period;
use App\Models\Academic\Schedule;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Staff\Teacher;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 5 — Jadwal Mengajar Guru (teacher self-service data scope).
 *
 * Scope berasal dari authenticated user -> teacherProfile -> schedules.teacher_id.
 * Client tidak pernah mengirim teacher_id. Dijalankan pada sqlite :memory: dengan
 * skema dibangun manual (migration RBAC repo berbasis MySQL tidak bisa di sqlite).
 */
class TeacherScheduleDataScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->seedFixture();
    }

    private function buildSchema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->nullable()->index();
            $t->string('username')->nullable();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('photo')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
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

        Schema::create('teachers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('full_name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('classes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('teacher_id')->nullable();
            $t->string('level')->nullable();
            $t->string('academic_year')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('academic_years', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('is_active')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('semesters', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('academic_year_id');
            $t->string('name');
            $t->boolean('is_active')->default(false);
            $t->timestamps();
        });

        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->string('type')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('periods', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('start_time')->nullable();
            $t->string('end_time')->nullable();
            $t->timestamps();
        });

        Schema::create('schedules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('teacher_id')->nullable();
            $t->string('day');
            $t->unsignedBigInteger('period_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->unsignedBigInteger('semester_id')->nullable();
            $t->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);
        $roleAdmin = Role::create(['name' => 'Admin']);

        $year = AcademicYear::create(['name' => '2025/2026', 'is_active' => true]);
        $sem = Semester::create(['academic_year_id' => $year->id, 'name' => 'Ganjil', 'is_active' => true]);
        $subject = Subject::create(['code' => 'TIK', 'name' => 'TIK']);
        $p1 = Period::create(['name' => '1', 'start_time' => '07:00', 'end_time' => '07:45']);
        $p2 = Period::create(['name' => '2', 'start_time' => '08:00', 'end_time' => '08:45']);

        $guruAUser = User::create(['name' => 'Guru A', 'email' => 'guruA@school.test', 'username' => 'gurua', 'password' => bcrypt('x'), 'role_id' => $roleGuru->id]);
        $teacherA = Teacher::create(['user_id' => $guruAUser->id, 'full_name' => 'Guru A']);

        $guruBUser = User::create(['name' => 'Guru B', 'email' => 'guruB@school.test', 'username' => 'gurub', 'password' => bcrypt('x'), 'role_id' => $roleGuru->id]);
        $teacherB = Teacher::create(['user_id' => $guruBUser->id, 'full_name' => 'Guru B']);

        $classA = SchoolClass::create(['name' => 'X RPL 1', 'level' => 'X']);
        $classB = SchoolClass::create(['name' => 'XI TKJ 1', 'level' => 'XI']);

        // Jadwal milik Guru A.
        Schedule::create(['class_id' => $classA->id, 'subject_id' => $subject->id, 'teacher_id' => $teacherA->id, 'day' => 'senin', 'period_id' => $p1->id, 'academic_year_id' => $year->id, 'semester_id' => $sem->id]);
        Schedule::create(['class_id' => $classA->id, 'subject_id' => $subject->id, 'teacher_id' => $teacherA->id, 'day' => 'selasa', 'period_id' => $p2->id, 'academic_year_id' => $year->id, 'semester_id' => $sem->id]);
        // Jadwal milik Guru B.
        Schedule::create(['class_id' => $classB->id, 'subject_id' => $subject->id, 'teacher_id' => $teacherB->id, 'day' => 'rabu', 'period_id' => $p1->id, 'academic_year_id' => $year->id, 'semester_id' => $sem->id]);

        $this->guruA = $guruAUser;
        $this->teacherA = $teacherA;
        $this->teacherB = $teacherB;
        $this->yearId = $year->id;
        $this->semId = $sem->id;
        $this->classAId = $classA->id;
        $this->classBId = $classB->id;

        // Siswa (tanpa view-schedules) — untuk Test 4 (no permission).
        $this->siswa = User::create(['name' => 'Siswa X', 'email' => 'siswaX@school.test', 'username' => 'siswax', 'password' => bcrypt('x'), 'role_id' => $roleSiswa->id]);
        // Admin (superuser, tanpa teacher profile) — untuk Test 3 & 5.
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@school.test', 'username' => 'admin', 'password' => bcrypt('x'), 'role_id' => $roleAdmin->id]);
    }

    public function test_guru_gets_only_own_schedules(): void
    {
        $this->actingAs($this->guruA, 'sanctum');

        $response = $this->getJson('/api/teacher/schedules');

        $response->assertOk();
        $days = collect($response->json('data'))->pluck('day')->all();
        $this->assertEqualsCanonicalizing(['senin', 'selasa'], $days);
        // Tidak ada jadwal Guru B (rabu).
        $this->assertNotContains('rabu', $days);
    }

    public function test_guru_does_not_leak_another_teachers_class(): void
    {
        $this->actingAs($this->guruA, 'sanctum');

        // minta class_id milik Guru B -> tetap dalam scope Guru A -> kosong.
        $response = $this->getJson("/api/teacher/schedules?class_id={$this->classBId}");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_filter_stays_within_teacher_scope(): void
    {
        $this->actingAs($this->guruA, 'sanctum');

        $response = $this->getJson("/api/teacher/schedules?day=senin&academic_year_id={$this->yearId}&semester_id={$this->semId}");

        $response->assertOk();
        $days = collect($response->json('data'))->pluck('day')->all();
        $this->assertEquals(['senin'], $days);
    }

    public function test_user_without_teacher_profile_is_forbidden(): void
    {
        // Admin bypass permission middleware tetapi tidak punya teacherProfile -> 403 controller.
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/teacher/schedules')->assertStatus(403);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        // Siswa tidak punya view-schedules -> 403 permission middleware.
        $this->actingAs($this->siswa, 'sanctum');

        $this->getJson('/api/teacher/schedules')->assertStatus(403);
    }

    public function test_admin_superuser_not_broken_on_global_endpoint(): void
    {
        // Admin tetap bisa memakai endpoint global /api/schedules (admin regression).
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/schedules')->assertOk();
    }
}
