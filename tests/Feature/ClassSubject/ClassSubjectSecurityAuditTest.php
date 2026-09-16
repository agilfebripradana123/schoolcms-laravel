<?php

namespace Tests\Feature\ClassSubject;

use App\Models\Academic\ClassSubject;
use App\Models\System\Role;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Subject;
use App\Models\Staff\Teacher;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClassSubjectSecurityAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupTestClassSubjects();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestClassSubjects();
        parent::tearDown();
    }

    // ─── Helpers ─────────────────────────────────────────────────

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

    private function createTestUser(int $roleId, string $prefix = 'test'): User
    {
        return User::create([
            'username' => $prefix . '_' . mt_rand(100000, 999999),
            'name' => 'Test User ClassSubject Audit ' . $prefix,
            'email' => $prefix . '.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    private function cleanupTestClassSubjects(): void
    {
        DB::table('class_subjects')->whereNull('teacher_id')->delete();
    }

    private function getFirstClassId(): int
    {
        return SchoolClass::whereNull('deleted_at')->orderBy('id')->first()->id;
    }

    private function getSecondClassId(): int
    {
        return SchoolClass::whereNull('deleted_at')->orderBy('id')->skip(1)->first()->id;
    }

    private function getFirstSubjectId(): int
    {
        return Subject::whereNull('deleted_at')->orderBy('id')->first()->id;
    }

    private function getSecondSubjectId(): int
    {
        return Subject::whereNull('deleted_at')->orderBy('id')->skip(1)->first()->id;
    }

    private function getFirstTeacherId(): int
    {
        return Teacher::whereNull('deleted_at')->orderBy('id')->first()->id;
    }

    private function createTestClassSubject(array $overrides = []): ClassSubject
    {
        $defaults = [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
            'teacher_id' => $this->getFirstTeacherId(),
        ];

        return ClassSubject::create(array_merge($defaults, $overrides));
    }

    // ─── Authentication Security Tests ───────────────────────────

    public function test_unauthenticated_cannot_access_index(): void
    {
        $response = $this->getJson('/api/class-subjects');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_access_show(): void
    {
        $response = $this->getJson('/api/class-subjects/1');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_store(): void
    {
        $response = $this->postJson('/api/class-subjects', [
            'class_id' => 1,
            'subject_id' => 1,
        ]);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_update(): void
    {
        $response = $this->putJson('/api/class-subjects/1', [
            'teacher_id' => 1,
        ]);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_delete(): void
    {
        $response = $this->deleteJson('/api/class-subjects/1');
        $response->assertStatus(401);
    }

    // ─── Role Authorization Security Tests ───────────────────────

    public function test_guru_cannot_store(): void
    {
        $this->authenticateAsGuru();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
        ]);

        $response->assertStatus(403);
    }

    public function test_guru_cannot_update(): void
    {
        $this->authenticateAsGuru();

        $classSubject = $this->createTestClassSubject();

        $response = $this->putJson("/api/class-subjects/{$classSubject->id}", [
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(403);
    }

    public function test_guru_cannot_delete(): void
    {
        $this->authenticateAsGuru();

        $classSubject = $this->createTestClassSubject();

        $response = $this->deleteJson("/api/class-subjects/{$classSubject->id}");

        $response->assertStatus(403);
    }

    public function test_siswa_cannot_store(): void
    {
        $this->authenticateAsSiswa();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
        ]);

        $response->assertStatus(403);
    }

    public function test_siswa_cannot_update(): void
    {
        $this->authenticateAsSiswa();

        $classSubject = $this->createTestClassSubject();

        $response = $this->putJson("/api/class-subjects/{$classSubject->id}", [
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(403);
    }

    public function test_siswa_cannot_delete(): void
    {
        $this->authenticateAsSiswa();

        $classSubject = $this->createTestClassSubject();

        $response = $this->deleteJson("/api/class-subjects/{$classSubject->id}");

        $response->assertStatus(403);
    }

    public function test_guru_can_read_index(): void
    {
        $this->authenticateAsGuru();
        $response = $this->getJson('/api/class-subjects');
        $response->assertStatus(200);
    }

    public function test_guru_can_read_show(): void
    {
        $this->authenticateAsGuru();
        $classSubject = $this->createTestClassSubject();
        $response = $this->getJson("/api/class-subjects/{$classSubject->id}");
        $response->assertStatus(200);
    }

    public function test_siswa_can_read_index(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->getJson('/api/class-subjects');
        $response->assertStatus(200);
    }

    public function test_siswa_can_read_show(): void
    {
        $this->authenticateAsSiswa();
        $classSubject = $this->createTestClassSubject();
        $response = $this->getJson("/api/class-subjects/{$classSubject->id}");
        $response->assertStatus(200);
    }

    // ─── Duplicate Prevention Security Tests ─────────────────────

    public function test_cannot_create_duplicate_class_subject(): void
    {
        $this->authenticateAsAdmin();

        $classId = $this->getFirstClassId();
        $subjectId = $this->getFirstSubjectId();

        $this->createTestClassSubject([
            'class_id' => $classId,
            'subject_id' => $subjectId,
        ]);

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $classId,
            'subject_id' => $subjectId,
        ]);

        $response->assertStatus(422);
    }

    public function test_same_class_different_subjects_allowed(): void
    {
        $this->authenticateAsAdmin();

        $classId = $this->getFirstClassId();

        $this->createTestClassSubject([
            'class_id' => $classId,
            'subject_id' => $this->getFirstSubjectId(),
        ]);

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $classId,
            'subject_id' => $this->getSecondSubjectId(),
        ]);

        $response->assertStatus(201);
    }

    public function test_same_subject_different_classes_allowed(): void
    {
        $this->authenticateAsAdmin();

        $subjectId = $this->getFirstSubjectId();

        $this->createTestClassSubject([
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $subjectId,
        ]);

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getSecondClassId(),
            'subject_id' => $subjectId,
        ]);

        $response->assertStatus(201);
    }

    // ─── Soft-Deleted Related Records Security Tests ─────────────

    public function test_cannot_create_with_soft_deleted_class(): void
    {
        $this->authenticateAsAdmin();

        $tempClass = SchoolClass::create([
            'name' => 'TempCSAudit',
            'level' => 'X',
            'academic_year' => '2026/2027',
        ]);
        $tempClass->delete();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $tempClass->id,
            'subject_id' => $this->getFirstSubjectId(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['class_id']);

        SchoolClass::withTrashed()->where('id', $tempClass->id)->forceDelete();
    }

    public function test_cannot_create_with_soft_deleted_subject(): void
    {
        $this->authenticateAsAdmin();

        $tempSubject = Subject::create([
            'code' => 'TEMP-CS-AUDIT',
            'name' => 'Temp Subject Audit',
            'type' => 'wajib',
        ]);
        $tempSubject->delete();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $tempSubject->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subject_id']);

        Subject::withTrashed()->where('id', $tempSubject->id)->forceDelete();
    }

    public function test_cannot_create_with_soft_deleted_teacher(): void
    {
        $this->authenticateAsAdmin();

        $tempTeacher = Teacher::create([
            'teacher_code' => 'T-CS-AUDIT-' . mt_rand(1000, 9999),
            'nip' => str_pad((string) mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT),
            'full_name' => 'Temp Teacher Audit',
            'gender' => 'L',
            'is_active' => true,
        ]);
        $tempTeacher->delete();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
            'teacher_id' => $tempTeacher->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['teacher_id']);

        Teacher::withTrashed()->where('id', $tempTeacher->id)->forceDelete();
    }

    // ─── Invalid Foreign Key Security Tests ──────────────────────

    public function test_cannot_create_with_invalid_class_id(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => 99999,
            'subject_id' => $this->getFirstSubjectId(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['class_id']);
    }

    public function test_cannot_create_with_invalid_subject_id(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => 99999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subject_id']);
    }

    public function test_cannot_create_with_invalid_teacher_id(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
            'teacher_id' => 99999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['teacher_id']);
    }

    // ─── Mass Assignment Security Tests ──────────────────────────

    public function test_cannot_inject_id_on_create(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'id' => 99999,
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
        ]);

        $response->assertStatus(201);
        $this->assertNotEquals(99999, $response->json('data.id'));
    }

    public function test_cannot_inject_created_at(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
            'created_at' => '2000-01-01 00:00:00',
        ]);

        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.created_at'));
    }

    // ─── Sensitive Field Exposure Tests ──────────────────────────

    public function test_index_no_deleted_at_exposed(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestClassSubject();

        $response = $this->getJson('/api/class-subjects');
        $response->assertStatus(200);

        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('deleted_at', $item);
        }
    }

    public function test_show_no_deleted_at_exposed(): void
    {
        $this->authenticateAsAdmin();
        $classSubject = $this->createTestClassSubject();

        $response = $this->getJson("/api/class-subjects/{$classSubject->id}");
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }

    // ─── IDOR Security Tests ────────────────────────────────────

    public function test_idor_show_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/class-subjects/99999');
        $response->assertStatus(404);
    }

    public function test_idor_update_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/class-subjects/99999', [
            'teacher_id' => $this->getFirstTeacherId(),
        ]);
        $response->assertStatus(404);
    }

    public function test_idor_delete_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/class-subjects/99999');
        $response->assertStatus(404);
    }

    // ─── Database Integrity Security Tests ───────────────────────

    public function test_hard_delete_removes_record(): void
    {
        $this->authenticateAsAdmin();
        $classSubject = $this->createTestClassSubject();

        $this->deleteJson("/api/class-subjects/{$classSubject->id}")->assertStatus(200);

        $this->assertDatabaseMissing('class_subjects', ['id' => $classSubject->id]);
    }

    public function test_hard_delete_preserves_classes_table(): void
    {
        $this->authenticateAsAdmin();
        $classId = $this->getFirstClassId();
        $classSubject = $this->createTestClassSubject(['class_id' => $classId]);

        $this->deleteJson("/api/class-subjects/{$classSubject->id}")->assertStatus(200);

        $this->assertDatabaseHas('classes', ['id' => $classId]);
    }

    public function test_hard_delete_preserves_subjects_table(): void
    {
        $this->authenticateAsAdmin();
        $subjectId = $this->getFirstSubjectId();
        $classSubject = $this->createTestClassSubject(['subject_id' => $subjectId]);

        $this->deleteJson("/api/class-subjects/{$classSubject->id}")->assertStatus(200);

        $this->assertDatabaseHas('subjects', ['id' => $subjectId]);
    }

    public function test_hard_delete_preserves_teachers_table(): void
    {
        $this->authenticateAsAdmin();
        $teacherId = $this->getFirstTeacherId();
        $classSubject = $this->createTestClassSubject(['teacher_id' => $teacherId]);

        $this->deleteJson("/api/class-subjects/{$classSubject->id}")->assertStatus(200);

        $this->assertDatabaseHas('teachers', ['id' => $teacherId]);
    }

    public function test_delete_one_preserves_others(): void
    {
        $this->authenticateAsAdmin();

        $cs1 = $this->createTestClassSubject([
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
        ]);
        $cs2 = $this->createTestClassSubject([
            'class_id' => $this->getSecondClassId(),
            'subject_id' => $this->getSecondSubjectId(),
        ]);

        $this->deleteJson("/api/class-subjects/{$cs1->id}")->assertStatus(200);

        $this->assertDatabaseHas('class_subjects', ['id' => $cs2->id]);
    }

    // ─── Pagination Abuse Tests ──────────────────────────────────

    public function test_invalid_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/class-subjects?per_page=-1');
        $response->assertStatus(422);
    }

    public function test_zero_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/class-subjects?per_page=0');
        $response->assertStatus(422);
    }

    public function test_excessive_per_page_capped(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/class-subjects?per_page=101');
        $response->assertStatus(422);
    }

    // ─── SQL Injection Prevention Tests ──────────────────────────

    public function test_class_id_filter_no_sql_injection(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/class-subjects?class_id=1%20OR%201=1');
        $response->assertStatus(422);
    }

    public function test_subject_id_filter_no_sql_injection(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/class-subjects?subject_id=1%20OR%201=1');
        $response->assertStatus(422);
    }

    public function test_teacher_id_filter_no_sql_injection(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/class-subjects?teacher_id=1%20OR%201=1');
        $response->assertStatus(422);
    }

    // ─── Update Duplicate Prevention Security Tests ──────────────

    public function test_update_cannot_create_duplicate(): void
    {
        $this->authenticateAsAdmin();

        $cs1 = $this->createTestClassSubject([
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
        ]);

        $cs2 = $this->createTestClassSubject([
            'class_id' => $this->getSecondClassId(),
            'subject_id' => $this->getSecondSubjectId(),
        ]);

        $response = $this->putJson("/api/class-subjects/{$cs2->id}", [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
        ]);

        $response->assertStatus(422);
    }

    public function test_update_self_same_combo_is_allowed(): void
    {
        $this->authenticateAsAdmin();

        $classSubject = $this->createTestClassSubject([
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
        ]);

        $response = $this->putJson("/api/class-subjects/{$classSubject->id}", [
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(200);
    }
}
