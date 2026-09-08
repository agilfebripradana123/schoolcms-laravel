<?php

namespace Tests\Feature;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassStudent;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Subject;
use App\Models\Staff\Teacher;
use App\Models\Staff\TeacherAssignment;
use App\Models\Students\Attendance;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 6 — Kehadiran Siswa Guru (teacher self-service data scope).
 *
 * Scope: authenticated user -> teacherProfile -> TeacherAssignment -> class.
 * Guru hanya bisa membaca/menginput kehadiran siswa di kelas scope-nya.
 */
class TeacherAttendanceDataScopeTest extends TestCase
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
        Schema::create('teacher_assignments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('teacher_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->timestamps();
        });
        Schema::create('class_students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->string('status')->default('active');
            $t->timestamps();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->string('nisn')->unique();
            $t->string('nis')->unique();
            $t->string('name');
            $t->string('gender');
            $t->string('birth_place')->nullable();
            $t->date('birth_date')->nullable();
            $t->text('address')->nullable();
            $t->string('phone')->nullable();
            $t->string('photo')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('class_id');
            $t->date('date');
            $t->string('status');
            $t->string('note')->nullable();
            $t->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);
        $roleAdmin = Role::create(['name' => 'Admin']);

        $year = AcademicYear::create(['name' => '2025/2026', 'is_active' => true]);
        $subject = Subject::create(['code' => 'TIK', 'name' => 'TIK']);

        $guruA = User::create(['name' => 'Guru A', 'email' => 'guruA@school.test', 'username' => 'gurua', 'password' => bcrypt('x'), 'role_id' => $roleGuru->id]);
        $teacherA = Teacher::create(['user_id' => $guruA->id, 'full_name' => 'Guru A']);

        $guruB = User::create(['name' => 'Guru B', 'email' => 'guruB@school.test', 'username' => 'gurub', 'password' => bcrypt('x'), 'role_id' => $roleGuru->id]);
        $teacherB = Teacher::create(['user_id' => $guruB->id, 'full_name' => 'Guru B']);

        $classA = SchoolClass::create(['name' => 'X RPL 1']);
        $classB = SchoolClass::create(['name' => 'XI TKJ 1']);

        TeacherAssignment::create(['teacher_id' => $teacherA->id, 'class_id' => $classA->id, 'subject_id' => $subject->id, 'academic_year_id' => $year->id]);
        TeacherAssignment::create(['teacher_id' => $teacherB->id, 'class_id' => $classB->id, 'subject_id' => $subject->id, 'academic_year_id' => $year->id]);

        $studentA1 = Student::create(['nisn' => '111', 'nis' => '001', 'name' => 'Ahmad', 'gender' => 'L']);
        $studentA2 = Student::create(['nisn' => '222', 'nis' => '002', 'name' => 'Budi', 'gender' => 'L']);
        $studentB = Student::create(['nisn' => '333', 'nis' => '003', 'name' => 'Citra', 'gender' => 'P']);

        ClassStudent::create(['class_id' => $classA->id, 'student_id' => $studentA1->id, 'academic_year_id' => $year->id]);
        ClassStudent::create(['class_id' => $classA->id, 'student_id' => $studentA2->id, 'academic_year_id' => $year->id]);
        ClassStudent::create(['class_id' => $classB->id, 'student_id' => $studentB->id, 'academic_year_id' => $year->id]);

        // Kehadiran: Guru A punya attendance utk Ahmad (class A), Guru B utk Citra (class B).
        Attendance::create(['student_id' => $studentA1->id, 'class_id' => $classA->id, 'date' => '2026-09-01', 'status' => 'hadir']);
        Attendance::create(['student_id' => $studentB->id, 'class_id' => $classB->id, 'date' => '2026-09-01', 'status' => 'sakit']);

        $this->guruA = $guruA;
        $this->guruB = $guruB;
        $this->classAId = $classA->id;
        $this->classBId = $classB->id;
        $this->studentA1Id = $studentA1->id;
        $this->studentA2Id = $studentA2->id;
        $this->studentBId = $studentB->id;
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@school.test', 'username' => 'admin', 'password' => bcrypt('x'), 'role_id' => $roleAdmin->id]);
        $this->siswa = User::create(['name' => 'Siswa X', 'email' => 'siswaX@school.test', 'username' => 'siswax', 'password' => bcrypt('x'), 'role_id' => $roleSiswa->id]);
    }

    public function test_guru_a_sees_only_own_class_attendance(): void
    {
        $this->actingAs($this->guruA, 'sanctum');

        $response = $this->getJson("/api/teacher/attendance?date=2026-09-01&class_id={$this->classAId}");

        $response->assertOk();
        $names = collect($response->json('data.students'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Ahmad', 'Budi'], $names);
        // Ahmad sudah hadir di tanggal tsb.
        $ahmad = collect($response->json('data.students'))->firstWhere('name', 'Ahmad');
        $this->assertEquals('hadir', $ahmad['status']);
        // Tidak ada data Citra (kelas B).
        $this->assertNotContains('Citra', $names);
    }

    public function test_guru_a_cannot_access_class_b(): void
    {
        $this->actingAs($this->guruA, 'sanctum');

        $response = $this->getJson("/api/teacher/attendance?date=2026-09-01&class_id={$this->classBId}");

        $response->assertStatus(404);
    }

    public function test_guru_a_cannot_manage_student_of_class_b(): void
    {
        $this->actingAs($this->guruA, 'sanctum');

        // Guru A mencoba menulis kehadiran utk siswa B (bukan kelasnya).
        $response = $this->postJson('/api/teacher/attendance', [
            'class_id' => $this->classAId,
            'date' => '2026-09-02',
            'items' => [
                ['student_id' => $this->studentBId, 'status' => 'hadir'],
            ],
        ]);

        $response->assertStatus(422); // validation failure, data tidak berubah.
        $this->assertDatabaseMissing('attendances', [
            'class_id' => $this->classAId,
            'student_id' => $this->studentBId,
            'date' => '2026-09-02',
        ]);
    }

    public function test_guru_a_input_is_idempotent(): void
    {
        $this->actingAs($this->guruA, 'sanctum');

        $payload = [
            'class_id' => $this->classAId,
            'date' => '2026-09-03',
            'items' => [
                ['student_id' => $this->studentA1Id, 'status' => 'hadir'],
                ['student_id' => $this->studentA2Id, 'status' => 'izin'],
            ],
        ];

        $this->postJson('/api/teacher/attendance', $payload)->assertOk();
        $this->postJson('/api/teacher/attendance', $payload)->assertOk();

        // Dua siswa -> 2 baris (A1+A2) pada tanggal tsb, TANPA duplikat per siswa
        // (menegaskan updateOrCreate idempotent saat Save ditekan dua kali).
        $this->assertSame(2, Attendance::where('date', '2026-09-03')->count());
        $this->assertSame(1, Attendance::where('date', '2026-09-03')->where('student_id', $this->studentA1Id)->count());
        $this->assertSame(1, Attendance::where('date', '2026-09-03')->where('student_id', $this->studentA2Id)->count());
        $this->assertDatabaseHas('attendances', ['student_id' => $this->studentA1Id, 'date' => '2026-09-03', 'status' => 'hadir']);
    }

    public function test_user_without_teacher_profile_is_forbidden(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/teacher/attendance?date=2026-09-01&class_id=1')->assertStatus(403);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        // Siswa tidak punya view-attendance -> 403 middleware.
        $this->actingAs($this->siswa, 'sanctum');

        $this->getJson('/api/teacher/attendance?date=2026-09-01&class_id=1')->assertStatus(403);
    }

    public function test_admin_global_endpoint_not_broken(): void
    {
        // Admin tetap bisa memakai endpoint global /api/attendance.
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/attendance')->assertOk();
    }
}
