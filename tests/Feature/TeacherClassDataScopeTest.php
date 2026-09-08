<?php

namespace Tests\Feature;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassStudent;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Subject;
use App\Models\Staff\Teacher;
use App\Models\Staff\TeacherAssignment;
use App\Models\Students\Student;
use App\Models\System\Permission;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 4 — Kelas & Siswa Guru (teacher self-service data scope).
 *
 * Validates that teacher-scoped endpoints resolve the teacher from the
 * authenticated user (never a client teacher_id) and only expose the teacher's
 * own classes/students. Runs on sqlite :memory: with a manually-built RBAC +
 * academic schema (the repo's MySQL-only RBAC migrations cannot run on sqlite).
 */
class TeacherClassDataScopeTest extends TestCase
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
            $t->string('description')->nullable();
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
            $t->string('description')->nullable();
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
        });

        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->string('type')->nullable();
            $t->timestamps();
        });

        Schema::create('teacher_assignments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('teacher_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->timestamps();
            $t->unique(['teacher_id', 'class_id', 'subject_id', 'academic_year_id'], 'ta_uniq');
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
    }

    private function seedFixture(): void
    {
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);

        $academicYear = AcademicYear::create(['name' => '2025/2026', 'is_active' => true]);
        $subject = Subject::create(['code' => 'TIK', 'name' => 'TIK']);

        // Guru A -> mengajar RPL 1 & RPL 2.
        $guruAUser = User::create([
            'name' => 'Guru A', 'email' => 'guruA@school.test',
            'username' => 'gurua', 'password' => bcrypt('x'), 'role_id' => $roleGuru->id,
        ]);
        $teacherA = Teacher::create(['user_id' => $guruAUser->id, 'full_name' => 'Guru A']);

        // Guru B -> mengajar TKJ 1.
        $guruBUser = User::create([
            'name' => 'Guru B', 'email' => 'guruB@school.test',
            'username' => 'gurub', 'password' => bcrypt('x'), 'role_id' => $roleGuru->id,
        ]);
        $teacherB = Teacher::create(['user_id' => $guruBUser->id, 'full_name' => 'Guru B']);

        $rpl1 = SchoolClass::create(['name' => 'X RPL 1', 'level' => 'X']);
        $rpl2 = SchoolClass::create(['name' => 'X RPL 2', 'level' => 'X']);
        $tkj1 = SchoolClass::create(['name' => 'XI TKJ 1', 'level' => 'XI']);

        TeacherAssignment::create(['teacher_id' => $teacherA->id, 'class_id' => $rpl1->id, 'subject_id' => $subject->id, 'academic_year_id' => $academicYear->id]);
        TeacherAssignment::create(['teacher_id' => $teacherA->id, 'class_id' => $rpl2->id, 'subject_id' => $subject->id, 'academic_year_id' => $academicYear->id]);
        TeacherAssignment::create(['teacher_id' => $teacherB->id, 'class_id' => $tkj1->id, 'subject_id' => $subject->id, 'academic_year_id' => $academicYear->id]);

        // Siswa di RPL 1 (milik Guru A).
        $s1 = Student::create(['nisn' => '111', 'nis' => '001', 'name' => 'Ahmad', 'gender' => 'L']);
        $s2 = Student::create(['nisn' => '222', 'nis' => '002', 'name' => 'Budi', 'gender' => 'L']);
        // Siswa di TKJ 1 (milik Guru B).
        $s3 = Student::create(['nisn' => '333', 'nis' => '003', 'name' => 'Citra', 'gender' => 'P']);

        ClassStudent::create(['class_id' => $rpl1->id, 'student_id' => $s1->id, 'academic_year_id' => $academicYear->id]);
        ClassStudent::create(['class_id' => $rpl1->id, 'student_id' => $s2->id, 'academic_year_id' => $academicYear->id]);
        ClassStudent::create(['class_id' => $tkj1->id, 'student_id' => $s3->id, 'academic_year_id' => $academicYear->id]);

        $this->guruAUser = $guruAUser;
        $this->teacherA = $teacherA;
        $this->rpl1Id = $rpl1->id;
        $this->tkj1Id = $tkj1->id;

        // Siswa (tanpa permission view-classes, tanpa link teacher).
        $this->siswaUser = User::create([
            'name' => 'Siswa X', 'email' => 'siswaX@school.test',
            'username' => 'siswax', 'password' => bcrypt('x'), 'role_id' => $roleSiswa->id,
        ]);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        // Role Siswa tidak punya view-classes -> PermissionMiddleware 403.
        $this->actingAs($this->siswaUser, 'sanctum');

        $this->getJson('/api/teacher/classes')->assertStatus(403);
        $this->getJson("/api/teacher/classes/{$this->rpl1Id}/students")->assertStatus(403);
    }

    public function test_teacher_resolves_own_classes_only(): void
    {
        $this->actingAs($this->guruAUser, 'sanctum');

        $response = $this->getJson('/api/teacher/classes');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['X RPL 1', 'X RPL 2'], $names);
        $this->assertNotContains('XI TKJ 1', $names);
    }

    public function test_teacher_own_class_students(): void
    {
        $this->actingAs($this->guruAUser, 'sanctum');

        $response = $this->getJson("/api/teacher/classes/{$this->rpl1Id}/students");

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('student.name')->all();
        $this->assertEqualsCanonicalizing(['Ahmad', 'Budi'], $names);
    }

    public function test_teacher_cannot_access_another_teachers_class(): void
    {
        $this->actingAs($this->guruAUser, 'sanctum');

        $response = $this->getJson("/api/teacher/classes/{$this->tkj1Id}/students");

        $response->assertStatus(404); // kelas milik Guru B tidak ter-autorisasi
    }

    public function test_class_students_list_has_own_class_count_and_no_leak(): void
    {
        $this->actingAs($this->guruAUser, 'sanctum');

        $response = $this->getJson('/api/teacher/classes');

        $rpl1 = collect($response->json('data'))->firstWhere('name', 'X RPL 1');
        $this->assertEquals(2, $rpl1['students_count']);
    }
}
