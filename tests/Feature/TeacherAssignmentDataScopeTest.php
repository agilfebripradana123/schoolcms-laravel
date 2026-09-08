<?php

namespace Tests\Feature;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Assignment;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Subject;
use App\Models\Staff\Teacher;
use App\Models\Staff\TeacherAssignment;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 8 — Tugas Guru (teacher self-service data scope).
 *
 * Scope: authenticated user -> teacherProfile -> teacher.id. Assignment yang
 * dikelola guru harus memiliki teacher_id = authenticated teacher, dan kombinasi
 * class/subject/academic_year harus merupakan TeacherAssignment milik guru.
 */
class TeacherAssignmentDataScopeTest extends TestCase
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
            $t->unique(['teacher_id', 'class_id', 'subject_id', 'academic_year_id'], 'ta_uniq');
        });
        Schema::create('assignments', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('description')->nullable();
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('teacher_id')->nullable();
            $t->date('due_date')->nullable();
            $t->unsignedBigInteger('academic_year_id');
            $t->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);
        $roleAdmin = Role::create(['name' => 'Admin']);

        $year = AcademicYear::create(['name' => '2025/2026', 'is_active' => true]);
        $year2 = AcademicYear::create(['name' => '2026/2027', 'is_active' => false]);
        $math = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        $indo = Subject::create(['code' => 'BIN', 'name' => 'Bahasa Indonesia']);

        $guruA = User::create(['name' => 'Guru A', 'email' => 'guruA@school.test', 'username' => 'gurua', 'password' => bcrypt('x'), 'role_id' => $roleGuru->id]);
        $teacherA = Teacher::create(['user_id' => $guruA->id, 'full_name' => 'Guru A']);
        $guruB = User::create(['name' => 'Guru B', 'email' => 'guruB@school.test', 'username' => 'gurub', 'password' => bcrypt('x'), 'role_id' => $roleGuru->id]);
        $teacherB = Teacher::create(['user_id' => $guruB->id, 'full_name' => 'Guru B']);

        $classA = SchoolClass::create(['name' => 'VII A']);
        $classB = SchoolClass::create(['name' => 'VIII B']);

        // Guru A mengajar Matematika di VII A (2025/2026); Guru B mengajar B.Indonesia di VIII B.
        TeacherAssignment::create(['teacher_id' => $teacherA->id, 'class_id' => $classA->id, 'subject_id' => $math->id, 'academic_year_id' => $year->id]);
        TeacherAssignment::create(['teacher_id' => $teacherB->id, 'class_id' => $classB->id, 'subject_id' => $indo->id, 'academic_year_id' => $year->id]);

        // Tugas Guru A dan Guru B.
        $assignmentA = Assignment::create(['title' => 'Tugas MTK A', 'subject_id' => $math->id, 'class_id' => $classA->id, 'teacher_id' => $teacherA->id, 'academic_year_id' => $year->id]);
        $assignmentB = Assignment::create(['title' => 'Tugas BIN B', 'subject_id' => $indo->id, 'class_id' => $classB->id, 'teacher_id' => $teacherB->id, 'academic_year_id' => $year->id]);

        $this->guruA = $guruA;
        $this->teacherA = $teacherA;
        $this->classAId = $classA->id;
        $this->classBId = $classB->id;
        $this->mathId = $math->id;
        $this->indoId = $indo->id;
        $this->yearId = $year->id;
        $this->year2Id = $year2->id;
        $this->assignmentAId = $assignmentA->id;
        $this->assignmentBId = $assignmentB->id;
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@school.test', 'username' => 'admin', 'password' => bcrypt('x'), 'role_id' => $roleAdmin->id]);
        $this->siswa = User::create(['name' => 'Siswa X', 'email' => 'siswaX@school.test', 'username' => 'siswax', 'password' => bcrypt('x'), 'role_id' => $roleSiswa->id]);
    }

    public function test_case_a_user_without_permission_is_forbidden(): void
    {
        $this->actingAs($this->siswa, 'sanctum');
        $this->getJson('/api/teacher/assignments')->assertStatus(403);
    }

    public function test_case_b_user_without_teacher_profile_is_forbidden(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->getJson('/api/teacher/assignments')->assertStatus(403);
    }

    public function test_case_c_guru_a_gets_only_own_assignments(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        $response = $this->getJson('/api/teacher/assignments');
        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Tugas MTK A'], $titles);
        $this->assertNotContains('Tugas BIN B', $titles);
    }

    public function test_case_d_guru_a_get_assignment_of_guru_b_is_not_found(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        $this->getJson("/api/teacher/assignments/{$this->assignmentBId}")->assertStatus(404);
    }

    public function test_case_e_guru_a_put_assignment_of_guru_b_is_not_found(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        $this->putJson("/api/teacher/assignments/{$this->assignmentBId}", ['title' => 'Hacked'])->assertStatus(404);
        $this->assertDatabaseHas('assignments', ['id' => $this->assignmentBId, 'title' => 'Tugas BIN B']);
    }

    public function test_case_f_guru_a_delete_assignment_of_guru_b_is_not_found(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        $this->deleteJson("/api/teacher/assignments/{$this->assignmentBId}")->assertStatus(404);
        $this->assertDatabaseHas('assignments', ['id' => $this->assignmentBId]);
    }

    public function test_case_g_guru_a_create_outside_teaching_scope_is_not_found(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        // class B + B.Indonesia (bukan TeacherAssignment Guru A).
        $response = $this->postJson('/api/teacher/assignments', [
            'title' => 'Tugas Luar Scope',
            'subject_id' => $this->indoId,
            'class_id' => $this->classBId,
            'academic_year_id' => $this->yearId,
        ]);
        $response->assertStatus(404);
        $this->assertDatabaseMissing('assignments', ['title' => 'Tugas Luar Scope']);
    }

    public function test_case_h_guru_a_create_in_teaching_scope_uses_auth_teacher(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        $response = $this->postJson('/api/teacher/assignments', [
            'title' => 'Tugas MTK Baru',
            'subject_id' => $this->mathId,
            'class_id' => $this->classAId,
            'academic_year_id' => $this->yearId,
        ]);
        $response->assertStatus(201);
        $this->assertSame($this->teacherA->id, (int) $response->json('data.teacher_id'));
        $this->assertDatabaseHas('assignments', ['title' => 'Tugas MTK Baru', 'teacher_id' => $this->teacherA->id]);
    }

    public function test_case_i_guru_a_update_own_assignment(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        $response = $this->putJson("/api/teacher/assignments/{$this->assignmentAId}", ['title' => 'Tugas MTK A (revisi)']);
        $response->assertOk();
        $this->assertDatabaseHas('assignments', ['id' => $this->assignmentAId, 'title' => 'Tugas MTK A (revisi)']);
    }

    public function test_case_j_guru_a_delete_own_assignment(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        $this->deleteJson("/api/teacher/assignments/{$this->assignmentAId}")->assertOk();
        $this->assertDatabaseMissing('assignments', ['id' => $this->assignmentAId]);
    }

    public function test_case_k_admin_global_assignment_endpoint_not_broken(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->getJson('/api/assignments')->assertOk();
    }
}
