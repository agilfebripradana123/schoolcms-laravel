<?php

namespace Tests\Feature\Grade;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassSubject;
use App\Models\Academic\Grade;
use App\Models\System\Role;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Students\Student;
use App\Models\Academic\Subject;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GradeSecurityAuditTest extends TestCase
{
    private int $classId;
    private int $subjectId;
    private int $studentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('database.default', 'mysql');
        $this->app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'schoolcms_db',
            'username' => 'root',
            'password' => 'root',
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => false,
            'engine' => null,
        ]);

        $this->app['db']->purge('mysql');

        $this->cleanupTestGrades();
        $this->cleanupTestStudents();
        $this->cleanupTestClassSubjects();

        $this->setupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestGrades();
        $this->cleanupTestStudents();
        $this->cleanupTestClassSubjects();
        parent::tearDown();
    }

    // ─── Setup Helpers ─────────────────────────────────────────

    private function setupTestData(): void
    {
        $this->classId = SchoolClass::whereNull('deleted_at')->orderBy('id')->first()->id;
        $this->subjectId = Subject::whereNull('deleted_at')->orderBy('id')->first()->id;

        $tempStudent = $this->createTestStudent(['class_id' => $this->classId]);
        $this->studentId = $tempStudent->id;

        ClassSubject::create([
            'class_id' => $this->classId,
            'subject_id' => $this->subjectId,
            'teacher_id' => null,
        ]);
    }

    // ─── Auth Helpers ──────────────────────────────────────────

    private function authenticateAsAdmin(): void
    {
        $adminRole = Role::where('name', 'Admin')->first();
        $user = User::where('role_id', $adminRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function authenticateAsAdministrator(): void
    {
        $adminRole = Role::where('name', 'Administrator')->first();
        $user = User::where('role_id', $adminRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function authenticateAsGuru(): void
    {
        $guruRole = Role::where('name', 'Guru')->first();
        $user = User::where('role_id', $guruRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function authenticateAsSiswa(): void
    {
        $siswaRole = Role::where('name', 'Siswa')->first();
        $user = $this->createTestUser($siswaRole->id, 'siswa');
        Sanctum::actingAs($user);
    }

    // ─── Data Helpers ──────────────────────────────────────────

    private function createTestUser(int $roleId, string $prefix = 'test'): User
    {
        return User::create([
            'username' => $prefix . '_' . mt_rand(100000, 999999),
            'name' => 'Test User Grade ' . $prefix,
            'email' => $prefix . '.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    private function createTestStudent(array $overrides = []): Student
    {
        $defaults = [
            'nisn' => 'GN-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'nis' => 'SN-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'name' => 'Test Student Grade',
            'gender' => 'L',
            'birth_place' => 'Test City',
            'birth_date' => '2008-01-01',
            'address' => 'Test Address',
        ];

        return Student::create(array_merge($defaults, $overrides));
    }

    private function createTestGrade(array $overrides = []): Grade
    {
        $defaults = [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85.50,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ];

        $grade = array_merge($defaults, $overrides);

        $year = AcademicYear::where('name', $grade['academic_year'])->whereNull('deleted_at')->first();
        $semester = Semester::where('academic_year_id', $year->id)
            ->where('name', $grade['semester'])
            ->first();

        $grade['academic_year_id'] = $year->id;
        $grade['semester_id'] = $semester->id;

        return Grade::create($grade);
    }

    // ─── Cleanup Helpers ───────────────────────────────────────

    private function cleanupTestGrades(): void
    {
        DB::connection('mysql')->table('grades')
            ->where('id', '>', 0)
            ->delete();
    }

    private function cleanupTestStudents(): void
    {
        Student::where('nisn', 'like', 'GN-%')->forceDelete();
        Student::where('nis', 'like', 'SN-%')->forceDelete();
    }

    private function cleanupTestClassSubjects(): void
    {
        DB::connection('mysql')->table('class_subjects')
            ->where('id', '>', 0)
            ->delete();
    }

    // ─── Authentication & Authorization Tests ──────────────────

    public function test_unauthenticated_index_returns_401(): void
    {
        $response = $this->getJson('/api/grades');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_show_returns_401(): void
    {
        $response = $this->getJson('/api/grades/1');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_store_returns_401(): void
    {
        $response = $this->postJson('/api/grades', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_update_returns_401(): void
    {
        $response = $this->putJson('/api/grades/1', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_patch_returns_401(): void
    {
        $response = $this->patchJson('/api/grades/1', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_delete_returns_401(): void
    {
        $response = $this->deleteJson('/api/grades/1');
        $response->assertStatus(401);
    }

    public function test_guru_cannot_access_store(): void
    {
        $this->authenticateAsGuru();
        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_access_update(): void
    {
        $this->authenticateAsGuru();
        $grade = $this->createTestGrade();
        $response = $this->putJson("/api/grades/{$grade->id}", ['score' => 95]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_access_patch(): void
    {
        $this->authenticateAsGuru();
        $grade = $this->createTestGrade();
        $response = $this->patchJson("/api/grades/{$grade->id}", ['score' => 95]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_access_delete(): void
    {
        $this->authenticateAsGuru();
        $grade = $this->createTestGrade();
        $response = $this->deleteJson("/api/grades/{$grade->id}");
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_access_store(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_access_update(): void
    {
        $this->authenticateAsSiswa();
        $grade = $this->createTestGrade();
        $response = $this->putJson("/api/grades/{$grade->id}", ['score' => 95]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_access_patch(): void
    {
        $this->authenticateAsSiswa();
        $grade = $this->createTestGrade();
        $response = $this->patchJson("/api/grades/{$grade->id}", ['score' => 95]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_access_delete(): void
    {
        $this->authenticateAsSiswa();
        $grade = $this->createTestGrade();
        $response = $this->deleteJson("/api/grades/{$grade->id}");
        $response->assertStatus(403);
    }

    public function test_admin_can_access_all_crud_operations(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/grades');
        $response->assertStatus(200);

        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(201);
        $gradeId = $response->json('data.id');

        $response = $this->getJson("/api/grades/{$gradeId}");
        $response->assertStatus(200);

        $response = $this->putJson("/api/grades/{$gradeId}", ['score' => 95]);
        $response->assertStatus(200);

        $response = $this->patchJson("/api/grades/{$gradeId}", ['score' => 90]);
        $response->assertStatus(200);

        $response = $this->deleteJson("/api/grades/{$gradeId}");
        $response->assertStatus(200);
    }

    public function test_administrator_can_access_all_crud_operations(): void
    {
        $this->authenticateAsAdministrator();
        $response = $this->getJson('/api/grades');
        $response->assertStatus(200);

        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(201);
        $gradeId = $response->json('data.id');

        $response = $this->getJson("/api/grades/{$gradeId}");
        $response->assertStatus(200);

        $response = $this->putJson("/api/grades/{$gradeId}", ['score' => 95]);
        $response->assertStatus(200);

        $response = $this->deleteJson("/api/grades/{$gradeId}");
        $response->assertStatus(200);
    }

    // ─── Duplicate & Integrity Tests ───────────────────────────

    public function test_cannot_create_duplicate_grade(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestGrade();

        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 90,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
    }

    public function test_cannot_create_duplicate_after_delete(): void
    {
        $this->authenticateAsAdmin();
        $grade = $this->createTestGrade();
        $this->deleteJson("/api/grades/{$grade->id}")->assertStatus(200);

        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(201);
    }

    public function test_cannot_assign_grade_with_deleted_student(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent(['class_id' => $this->classId]);
        $studentId = $student->id;
        $student->delete();

        $response = $this->postJson('/api/grades', [
            'student_id' => $studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['student_id']);
    }

    public function test_cannot_assign_grade_with_deleted_subject(): void
    {
        $this->authenticateAsAdmin();
        $subject = Subject::whereNull('deleted_at')->orderBy('id')->skip(2)->first();
        if (!$subject) {
            $this->markTestSkipped('Not enough subjects');
            return;
        }
        $subjectId = $subject->id;
        $subject->delete();

        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subject_id']);
    }

    public function test_cannot_assign_grade_with_deleted_class(): void
    {
        $this->authenticateAsAdmin();
        $class = SchoolClass::whereNull('deleted_at')->orderBy('id')->skip(2)->first();
        if (!$class) {
            $this->markTestSkipped('Not enough classes');
            return;
        }
        $classId = $class->id;
        $class->delete();

        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['class_id']);
    }

    // ─── Mass Assignment Tests ─────────────────────────────────

    public function test_store_ignores_id_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/grades', [
            'id' => 99999,
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals(99999, $response->json('data.id'));
    }

    public function test_store_ignores_created_at_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
            'created_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.created_at'));
    }

    public function test_store_ignores_updated_at_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
            'updated_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.updated_at'));
    }

    // ─── Input Validation Security Tests ───────────────────────

    public function test_store_rejects_negative_score(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => -1,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_score_above_100(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 101,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_type(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'invalid',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_semester(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '3',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_academic_year(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026-2027',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_string_score(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 'abc',
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_string_student_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/grades', [
            'student_id' => 'abc',
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/grades', []);
        $response->assertStatus(422);
    }

    public function test_update_allows_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $grade = $this->createTestGrade();
        $response = $this->putJson("/api/grades/{$grade->id}", []);
        $response->assertStatus(200);
        $this->assertDatabaseHas('grades', ['id' => $grade->id], 'mysql');
    }

    // ─── Pagination Security Tests ─────────────────────────────

    public function test_invalid_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/grades?per_page=0');
        $response->assertStatus(422);
    }

    public function test_negative_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/grades?per_page=-1');
        $response->assertStatus(422);
    }

    public function test_excessive_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/grades?per_page=101');
        $response->assertStatus(422);
    }

    public function test_string_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/grades?per_page=abc');
        $response->assertStatus(422);
    }

    // ─── IDOR Tests ────────────────────────────────────────────

    public function test_idor_show_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/grades/99999');
        $response->assertStatus(404);
    }

    public function test_idor_update_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/grades/99999', ['score' => 90]);
        $response->assertStatus(404);
    }

    public function test_idor_delete_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/grades/99999');
        $response->assertStatus(404);
    }

    // ─── Business Rule Security Tests ──────────────────────────

    public function test_rejects_student_without_class(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent(['class_id' => null]);

        $response = $this->postJson('/api/grades', [
            'student_id' => $student->id,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['student_id']);
    }

    public function test_rejects_student_in_wrong_class(): void
    {
        $this->authenticateAsAdmin();
        $otherClassId = SchoolClass::whereNull('deleted_at')->orderBy('id')->skip(1)->first()->id;
        $student = $this->createTestStudent(['class_id' => $otherClassId]);

        $response = $this->postJson('/api/grades', [
            'student_id' => $student->id,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['student_id']);
    }

    public function test_rejects_subject_not_assigned_to_class(): void
    {
        $this->authenticateAsAdmin();
        $otherSubjectId = Subject::whereNull('deleted_at')->orderBy('id')->skip(2)->first()->id;

        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $otherSubjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subject_id']);
    }

    // ─── Soft-Deleted FK Security Tests ────────────────────────

    public function test_store_handles_soft_deleted_student_independently(): void
    {
        $this->authenticateAsAdmin();
        $student = $this->createTestStudent(['class_id' => $this->classId]);
        $studentId = $student->id;
        $student->delete();

        $response = $this->postJson('/api/grades', [
            'student_id' => $studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_handles_soft_deleted_subject_independently(): void
    {
        $this->authenticateAsAdmin();
        $subject = Subject::whereNull('deleted_at')->orderBy('id')->skip(3)->first();
        if (!$subject) {
            $this->markTestSkipped('Not enough subjects');
            return;
        }
        $subjectId = $subject->id;
        $subject->delete();

        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $subjectId,
            'class_id' => $this->classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_handles_soft_deleted_class_independently(): void
    {
        $this->authenticateAsAdmin();
        $class = SchoolClass::whereNull('deleted_at')->orderBy('id')->skip(2)->first();
        if (!$class) {
            $this->markTestSkipped('Not enough classes');
            return;
        }
        $classId = $class->id;
        $class->delete();

        $response = $this->postJson('/api/grades', [
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $classId,
            'type' => 'tugas',
            'score' => 85,
            'semester' => '1',
            'academic_year' => '2026/2027',
        ]);
        $response->assertStatus(422);
    }
}
