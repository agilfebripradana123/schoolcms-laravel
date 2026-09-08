<?php

namespace Tests\Feature;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassStudent;
use App\Models\Academic\Grade;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Staff\Teacher;
use App\Models\Staff\TeacherAssignment;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 7 — Nilai Guru (teacher self-service data scope).
 *
 * Scope: authenticated user -> teacherProfile -> TeacherAssignment
 * (teacher + class + subject + academic_year). Guru A mengjar Matematika di
 * VII A, Guru B mengajar Bahasa Indonesia di VII A (sama kelas, beda mapel)
 * -> Guru A hanya boleh akses Matematika.
 */
class TeacherGradeDataScopeTest extends TestCase
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
            $t->unsignedBigInteger('class_id')->nullable()->index();
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
        Schema::create('grades', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('class_id');
            $t->string('type');
            $t->decimal('score', 5, 2)->nullable();
            $t->string('semester');
            $t->string('academic_year');
            $t->unsignedBigInteger('academic_year_id');
            $t->unsignedBigInteger('semester_id');
            $t->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);
        $roleAdmin = Role::create(['name' => 'Admin']);

        $year = AcademicYear::create(['name' => '2025/2026', 'is_active' => true]);
        $semester = Semester::create(['academic_year_id' => $year->id, 'name' => '1']);
        $math = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        $indo = Subject::create(['code' => 'BIN', 'name' => 'Bahasa Indonesia']);

        $guruA = User::create(['name' => 'Guru A', 'email' => 'guruA@school.test', 'username' => 'gurua', 'password' => bcrypt('x'), 'role_id' => $roleGuru->id]);
        $teacherA = Teacher::create(['user_id' => $guruA->id, 'full_name' => 'Guru A']);
        $guruB = User::create(['name' => 'Guru B', 'email' => 'guruB@school.test', 'username' => 'gurub', 'password' => bcrypt('x'), 'role_id' => $roleGuru->id]);
        $teacherB = Teacher::create(['user_id' => $guruB->id, 'full_name' => 'Guru B']);

        $classA = SchoolClass::create(['name' => 'VII A']);
        $classB = SchoolClass::create(['name' => 'VIII B']);

        // Guru A mengajar Matematika di VII A; Guru B mengajar B.Indonesia di VII A.
        TeacherAssignment::create(['teacher_id' => $teacherA->id, 'class_id' => $classA->id, 'subject_id' => $math->id, 'academic_year_id' => $year->id]);
        TeacherAssignment::create(['teacher_id' => $teacherB->id, 'class_id' => $classA->id, 'subject_id' => $indo->id, 'academic_year_id' => $year->id]);
        // Guru B lain: Matematika di VIII B (kelas lain).
        TeacherAssignment::create(['teacher_id' => $teacherA->id, 'class_id' => $classB->id, 'subject_id' => $math->id, 'academic_year_id' => $year->id]);

        $studentA1 = Student::create(['nisn' => '111', 'nis' => '001', 'name' => 'Andi', 'gender' => 'L', 'class_id' => $classA->id]);
        $studentA2 = Student::create(['nisn' => '222', 'nis' => '002', 'name' => 'Budi', 'gender' => 'L', 'class_id' => $classA->id]);
        $studentB1 = Student::create(['nisn' => '333', 'nis' => '003', 'name' => 'Citra', 'gender' => 'P', 'class_id' => $classB->id]);

        ClassStudent::create(['class_id' => $classA->id, 'student_id' => $studentA1->id, 'academic_year_id' => $year->id]);
        ClassStudent::create(['class_id' => $classA->id, 'student_id' => $studentA2->id, 'academic_year_id' => $year->id]);
        ClassStudent::create(['class_id' => $classB->id, 'student_id' => $studentB1->id, 'academic_year_id' => $year->id]);

        // Grade: Andi Matematika 85 (milik Guru A).
        Grade::create(['student_id' => $studentA1->id, 'subject_id' => $math->id, 'class_id' => $classA->id, 'type' => 'uts', 'score' => 85, 'semester' => '1', 'academic_year' => '2025/2026', 'academic_year_id' => $year->id, 'semester_id' => $semester->id]);

        $this->guruA = $guruA;
        $this->guruB = $guruB;
        $this->classAId = $classA->id;
        $this->classBId = $classB->id;
        $this->mathId = $math->id;
        $this->indoId = $indo->id;
        $this->studentA1Id = $studentA1->id;
        $this->studentA2Id = $studentA2->id;
        $this->studentB1Id = $studentB1->id;
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@school.test', 'username' => 'admin', 'password' => bcrypt('x'), 'role_id' => $roleAdmin->id]);
        $this->siswa = User::create(['name' => 'Siswa X', 'email' => 'siswaX@school.test', 'username' => 'siswax', 'password' => bcrypt('x'), 'role_id' => $roleSiswa->id]);
    }

    private function rosterUrl(array $params): string
    {
        return '/api/teacher/grades?' . http_build_query($params + ['academic_year' => '2025/2026']);
    }

    public function test_guru_a_gets_math_grades_in_own_class(): void
    {
        $this->actingAs($this->guruA, 'sanctum');

        $response = $this->getJson($this->rosterUrl(['class_id' => $this->classAId, 'subject_id' => $this->mathId, 'type' => 'uts', 'semester' => '1']));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals('2025/2026', $data['academic_year']);
        $names = collect($data['students'])->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Andi', 'Budi'], $names);
        $andi = collect($data['students'])->firstWhere('name', 'Andi');
        $this->assertEquals(85, (float) $andi['score']);
    }

    public function test_guru_a_cannot_access_bahasa_indonesia_same_class(): void
    {
        // Guru A tidak mengajar B.Indonesia di VII A -> 404/subject blocked.
        $this->actingAs($this->guruA, 'sanctum');

        $response = $this->getJson($this->rosterUrl(['class_id' => $this->classAId, 'subject_id' => $this->indoId, 'type' => 'uts', 'semester' => '1']));

        $response->assertStatus(404);
    }

    public function test_guru_a_cannot_access_math_in_another_class_scope_ok(): void
    {
        // Guru A mengajar Matematika di VIII B VIA assignment lain -> allowed tetapi SCOPE kelas tsb.
        $this->actingAs($this->guruA, 'sanctum');

        $response = $this->getJson($this->rosterUrl(['class_id' => $this->classBId, 'subject_id' => $this->mathId, 'type' => 'uts', 'semester' => '1']));

        // Guru A juga mengajar Matematika di VIII B -> 200, hanya roster kelas B.
        $response->assertOk();
        $names = collect($response->json('data.students'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Citra'], $names);
    }

    public function test_guru_b_cannot_give_math_grade(): void
    {
        // Guru B tidak mengajar Matematika -> blocked.
        $this->actingAs($this->guruB, 'sanctum');

        $response = $this->getJson($this->rosterUrl(['class_id' => $this->classAId, 'subject_id' => $this->mathId, 'type' => 'uts', 'semester' => '1']));

        $response->assertStatus(404);
    }

    public function test_bulk_student_scope_blocked_and_atomic(): void
    {
        $this->actingAs($this->guruA, 'sanctum');

        // Siswa B1 (kelas VIII B) diirim ke bulk untuk kelas VII A -> 422, tidak ada perubahan.
        $response = $this->postJson('/api/teacher/grades/bulk', [
            'class_id' => $this->classAId,
            'subject_id' => $this->mathId,
            'type' => 'tugas',
            'semester' => '1',
            'academic_year' => '2025/2026',
            'items' => [
                ['student_id' => $this->studentB1Id, 'score' => 90],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('grades', ['student_id' => $this->studentB1Id, 'type' => 'tugas']);
        // Andi (materi valid) tidak berubah karena operasi atomic.
        $this->assertEquals(85, (float) Grade::where('student_id', $this->studentA1Id)->where('type', 'uts')->value('score'));
    }

    public function test_bulk_save_is_atomic_and_idempotent(): void
    {
        $this->actingAs($this->guruA, 'sanctum');

        $payload = [
            'class_id' => $this->classAId,
            'subject_id' => $this->mathId,
            'type' => 'tugas',
            'semester' => '1',
            'academic_year' => '2025/2026',
            'items' => [
                ['student_id' => $this->studentA1Id, 'score' => 80],
                ['student_id' => $this->studentA2Id, 'score' => 70],
            ],
        ];

        $this->postJson('/api/teacher/grades/bulk', $payload)->assertOk();
        $this->postJson('/api/teacher/grades/bulk', $payload)->assertOk();

        // Satu baris per student+subject+class+type+semester+year (tidak duplikat).
        $this->assertSame(1, Grade::where('student_id', $this->studentA1Id)->where('type', 'tugas')->where('semester', '1')->count());
        $this->assertSame(1, Grade::where('student_id', $this->studentA2Id)->where('type', 'tugas')->where('semester', '1')->count());
        $this->assertEquals(80, (float) Grade::where('student_id', $this->studentA1Id)->where('type', 'tugas')->where('semester', '1')->value('score'));
    }

    public function test_user_without_teacher_profile_is_forbidden(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson($this->rosterUrl(['class_id' => $this->classAId, 'subject_id' => $this->mathId, 'type' => 'uts', 'semester' => '1']))->assertStatus(403);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $this->actingAs($this->siswa, 'sanctum');

        $this->getJson($this->rosterUrl(['class_id' => $this->classAId, 'subject_id' => $this->mathId, 'type' => 'uts', 'semester' => '1']))->assertStatus(403);
    }

    public function test_admin_global_grade_endpoint_not_broken(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/grades')->assertOk();
    }
}
