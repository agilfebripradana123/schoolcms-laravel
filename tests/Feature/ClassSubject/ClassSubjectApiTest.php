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

class ClassSubjectApiTest extends TestCase
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

    private function authenticate(): void
    {
        $user = User::first();
        Sanctum::actingAs($user);
    }

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
            'name' => 'Test User ClassSubject ' . $prefix,
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
        return SchoolClass::whereNull('deleted_at')->first()->id;
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
        return Teacher::whereNull('deleted_at')->first()->id;
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

    // ─── Authentication Tests ────────────────────────────────────

    public function test_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/class-subjects');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_index(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/class-subjects');

        $response->assertStatus(200);
    }

    public function test_authenticated_user_can_access_show(): void
    {
        $this->authenticate();

        $classSubject = $this->createTestClassSubject();
        $response = $this->getJson("/api/class-subjects/{$classSubject->id}");

        $response->assertStatus(200);
    }

    // ─── Index Tests ─────────────────────────────────────────────

    public function test_index_returns_200(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/class-subjects');

        $response->assertStatus(200);
    }

    public function test_index_returns_json(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/class-subjects');

        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_index_response_has_success_message_data_meta(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/class-subjects');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta' => [
                'current_page',
                'per_page',
                'total',
                'last_page',
            ],
        ]);
        $response->assertJson([
            'success' => true,
            'message' => 'Class subjects retrieved successfully',
        ]);
    }

    public function test_index_returns_class_subjects(): void
    {
        $this->authenticate();

        $classSubject = $this->createTestClassSubject();

        $response = $this->getJson('/api/class-subjects');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_index_includes_eager_loaded_relations(): void
    {
        $this->authenticate();

        $classSubject = $this->createTestClassSubject();

        $response = $this->getJson('/api/class-subjects');

        $response->assertStatus(200);
        $first = $response->json('data')[0];
        $this->assertArrayHasKey('class', $first);
        $this->assertArrayHasKey('subject', $first);
        $this->assertArrayHasKey('teacher', $first);
    }

    // ─── Pagination Tests ────────────────────────────────────────

    public function test_pagination_default_per_page(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/class-subjects');

        $response->assertStatus(200);

        $meta = $response->json('meta');
        $this->assertEquals(1, $meta['current_page']);
        $this->assertEquals(10, $meta['per_page']);
        $this->assertIsInt($meta['total']);
        $this->assertGreaterThanOrEqual(0, $meta['last_page']);
    }

    public function test_pagination_per_page_works(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/class-subjects?per_page=5');

        $response->assertStatus(200);

        $meta = $response->json('meta');
        $this->assertEquals(5, $meta['per_page']);
        $this->assertLessThanOrEqual(5, count($response->json('data')));
    }

    public function test_pagination_page_works(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/class-subjects?page=1&per_page=5');

        $response->assertStatus(200);

        $meta = $response->json('meta');
        $this->assertEquals(1, $meta['current_page']);
    }

    public function test_pagination_invalid_per_page_uses_default(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/class-subjects?per_page=-1');

        $response->assertStatus(422);
    }

    // ─── Filter Tests ────────────────────────────────────────────

    public function test_filter_by_class_id(): void
    {
        $this->authenticate();

        $class1 = $this->getFirstClassId();
        $class2 = $this->getSecondClassId();

        $this->createTestClassSubject(['class_id' => $class1, 'subject_id' => $this->getFirstSubjectId()]);
        $this->createTestClassSubject(['class_id' => $class2, 'subject_id' => $this->getSecondSubjectId()]);

        $response = $this->getJson("/api/class-subjects?class_id={$class1}");

        $response->assertStatus(200);

        foreach ($response->json('data') as $item) {
            $this->assertEquals($class1, $item['class_id']);
        }
    }

    public function test_filter_by_subject_id(): void
    {
        $this->authenticate();

        $subject1 = $this->getFirstSubjectId();
        $subject2 = $this->getSecondSubjectId();

        $this->createTestClassSubject(['class_id' => $this->getFirstClassId(), 'subject_id' => $subject1]);
        $this->createTestClassSubject(['class_id' => $this->getSecondClassId(), 'subject_id' => $subject2]);

        $response = $this->getJson("/api/class-subjects?subject_id={$subject2}");

        $response->assertStatus(200);

        foreach ($response->json('data') as $item) {
            $this->assertEquals($subject2, $item['subject_id']);
        }
    }

    public function test_filter_by_teacher_id(): void
    {
        $this->authenticate();

        $teacher = $this->getFirstTeacherId();

        $this->createTestClassSubject(['teacher_id' => $teacher]);

        $response = $this->getJson("/api/class-subjects?teacher_id={$teacher}");

        $response->assertStatus(200);

        foreach ($response->json('data') as $item) {
            $this->assertEquals($teacher, $item['teacher_id']);
        }
    }

    public function test_filter_by_class_id_and_subject_id(): void
    {
        $this->authenticate();

        $classId = $this->getFirstClassId();
        $subjectId = $this->getFirstSubjectId();

        $this->createTestClassSubject(['class_id' => $classId, 'subject_id' => $subjectId]);

        $response = $this->getJson("/api/class-subjects?class_id={$classId}&subject_id={$subjectId}");

        $response->assertStatus(200);

        foreach ($response->json('data') as $item) {
            $this->assertEquals($classId, $item['class_id']);
            $this->assertEquals($subjectId, $item['subject_id']);
        }
    }

    public function test_filter_returns_empty_when_no_match(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/class-subjects?class_id=99999');

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    // ─── Show Tests ──────────────────────────────────────────────

    public function test_show_returns_200(): void
    {
        $this->authenticate();

        $classSubject = $this->createTestClassSubject();

        $response = $this->getJson("/api/class-subjects/{$classSubject->id}");

        $response->assertStatus(200);
    }

    public function test_show_returns_correct_data(): void
    {
        $this->authenticate();

        $classSubject = $this->createTestClassSubject();

        $response = $this->getJson("/api/class-subjects/{$classSubject->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Class subject retrieved successfully',
            'data' => [
                'id' => $classSubject->id,
                'class_id' => $classSubject->class_id,
                'subject_id' => $classSubject->subject_id,
                'teacher_id' => $classSubject->teacher_id,
            ],
        ]);
    }

    public function test_show_includes_eager_loaded_relations(): void
    {
        $this->authenticate();

        $classSubject = $this->createTestClassSubject();

        $response = $this->getJson("/api/class-subjects/{$classSubject->id}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertArrayHasKey('class', $data);
        $this->assertArrayHasKey('subject', $data);
        $this->assertArrayHasKey('teacher', $data);
        $this->assertEquals($classSubject->class_id, $data['class']['id']);
        $this->assertEquals($classSubject->subject_id, $data['subject']['id']);
        $this->assertEquals($classSubject->teacher_id, $data['teacher']['id']);
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/class-subjects/99999');

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Class subject not found',
        ]);
    }

    // ─── Store Tests ─────────────────────────────────────────────

    public function test_admin_can_store_class_subject(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Class subject created successfully',
        ]);
    }

    public function test_administrator_can_store_class_subject(): void
    {
        $this->authenticateAsAdministrator();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(201);
    }

    public function test_guru_cannot_store_class_subject(): void
    {
        $this->authenticateAsGuru();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(403);
    }

    public function test_siswa_cannot_store_class_subject(): void
    {
        $this->authenticateAsSiswa();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(403);
    }

    public function test_store_returns_created_status(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(201);
    }

    public function test_store_creates_database_record(): void
    {
        $this->authenticateAsAdmin();

        $classId = $this->getFirstClassId();
        $subjectId = $this->getFirstSubjectId();
        $teacherId = $this->getFirstTeacherId();

        $this->postJson('/api/class-subjects', [
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'teacher_id' => $teacherId,
        ])->assertStatus(201);

        $this->assertDatabaseHas('class_subjects', [
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'teacher_id' => $teacherId,
        ]);
    }

    public function test_store_with_teacher_id(): void
    {
        $this->authenticateAsAdmin();

        $teacherId = $this->getFirstTeacherId();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
            'teacher_id' => $teacherId,
        ]);

        $response->assertStatus(201);
        $this->assertEquals($teacherId, $response->json('data.teacher_id'));
    }

    public function test_store_without_teacher_id(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.teacher_id'));
    }

    public function test_store_with_null_teacher_id(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
            'teacher_id' => null,
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.teacher_id'));
    }

    public function test_store_returns_eager_loaded_relations(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertArrayHasKey('class', $data);
        $this->assertArrayHasKey('subject', $data);
        $this->assertArrayHasKey('teacher', $data);
    }

    // ─── Store Validation Tests ──────────────────────────────────

    public function test_store_requires_class_id(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'subject_id' => $this->getFirstSubjectId(),
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['class_id']);
    }

    public function test_store_requires_subject_id(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subject_id']);
    }

    public function test_store_rejects_nonexistent_class_id(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => 99999,
            'subject_id' => $this->getFirstSubjectId(),
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['class_id']);
    }

    public function test_store_rejects_nonexistent_subject_id(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => 99999,
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subject_id']);
    }

    public function test_store_rejects_nonexistent_teacher_id(): void
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

    public function test_store_rejects_duplicate_class_subject(): void
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
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_string_class_id(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => 'abc',
            'subject_id' => $this->getFirstSubjectId(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['class_id']);
    }

    public function test_store_rejects_string_subject_id(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/class-subjects', [
            'class_id' => $this->getFirstClassId(),
            'subject_id' => 'abc',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subject_id']);
    }

    // ─── Update Tests ────────────────────────────────────────────

    public function test_admin_can_update_class_subject(): void
    {
        $this->authenticateAsAdmin();

        $classSubject = $this->createTestClassSubject();

        $response = $this->putJson("/api/class-subjects/{$classSubject->id}", [
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Class subject updated successfully',
        ]);
    }

    public function test_administrator_can_update_class_subject(): void
    {
        $this->authenticateAsAdministrator();

        $classSubject = $this->createTestClassSubject();

        $response = $this->putJson("/api/class-subjects/{$classSubject->id}", [
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(200);
    }

    public function test_guru_cannot_update_class_subject(): void
    {
        $this->authenticateAsGuru();

        $classSubject = $this->createTestClassSubject();

        $response = $this->putJson("/api/class-subjects/{$classSubject->id}", [
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(403);
    }

    public function test_siswa_cannot_update_class_subject(): void
    {
        $this->authenticateAsSiswa();

        $classSubject = $this->createTestClassSubject();

        $response = $this->putJson("/api/class-subjects/{$classSubject->id}", [
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(403);
    }

    public function test_update_changes_teacher(): void
    {
        $this->authenticateAsAdmin();

        $classSubject = $this->createTestClassSubject([
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $newTeacherId = Teacher::whereNull('deleted_at')->where('id', '!=', $classSubject->teacher_id)->first()->id;

        $response = $this->putJson("/api/class-subjects/{$classSubject->id}", [
            'teacher_id' => $newTeacherId,
        ]);

        $response->assertStatus(200);
        $this->assertEquals($newTeacherId, $response->json('data.teacher_id'));
    }

    public function test_update_changes_class(): void
    {
        $this->authenticateAsAdmin();

        $classSubject = $this->createTestClassSubject([
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getSecondSubjectId(),
        ]);

        $response = $this->putJson("/api/class-subjects/{$classSubject->id}", [
            'class_id' => $this->getSecondClassId(),
        ]);

        $response->assertStatus(200);
        $this->assertEquals($this->getSecondClassId(), $response->json('data.class_id'));
    }

    public function test_update_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->putJson('/api/class-subjects/99999', [
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(404);
    }

    public function test_update_rejects_duplicate_class_subject(): void
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

    public function test_update_same_class_subject_to_self_is_allowed(): void
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

    public function test_update_returns_eager_loaded_relations(): void
    {
        $this->authenticateAsAdmin();

        $classSubject = $this->createTestClassSubject();

        $response = $this->putJson("/api/class-subjects/{$classSubject->id}", [
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertArrayHasKey('class', $data);
        $this->assertArrayHasKey('subject', $data);
        $this->assertArrayHasKey('teacher', $data);
    }

    // ─── Destroy Tests ───────────────────────────────────────────

    public function test_admin_can_delete_class_subject(): void
    {
        $this->authenticateAsAdmin();

        $classSubject = $this->createTestClassSubject();

        $response = $this->deleteJson("/api/class-subjects/{$classSubject->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Class subject deleted successfully',
        ]);
    }

    public function test_administrator_can_delete_class_subject(): void
    {
        $this->authenticateAsAdministrator();

        $classSubject = $this->createTestClassSubject();

        $response = $this->deleteJson("/api/class-subjects/{$classSubject->id}");

        $response->assertStatus(200);
    }

    public function test_guru_cannot_delete_class_subject(): void
    {
        $this->authenticateAsGuru();

        $classSubject = $this->createTestClassSubject();

        $response = $this->deleteJson("/api/class-subjects/{$classSubject->id}");

        $response->assertStatus(403);
    }

    public function test_siswa_cannot_delete_class_subject(): void
    {
        $this->authenticateAsSiswa();

        $classSubject = $this->createTestClassSubject();

        $response = $this->deleteJson("/api/class-subjects/{$classSubject->id}");

        $response->assertStatus(403);
    }

    public function test_delete_removes_from_database(): void
    {
        $this->authenticateAsAdmin();

        $classSubject = $this->createTestClassSubject();

        $this->deleteJson("/api/class-subjects/{$classSubject->id}")->assertStatus(200);

        $this->assertDatabaseMissing('class_subjects', [
            'id' => $classSubject->id,
        ]);
    }

    public function test_delete_does_not_affect_classes(): void
    {
        $this->authenticateAsAdmin();

        $classId = $this->getFirstClassId();
        $classSubject = $this->createTestClassSubject(['class_id' => $classId]);

        $this->deleteJson("/api/class-subjects/{$classSubject->id}")->assertStatus(200);

        $this->assertDatabaseHas('classes', [
            'id' => $classId,
        ]);
    }

    public function test_delete_does_not_affect_subjects(): void
    {
        $this->authenticateAsAdmin();

        $subjectId = $this->getFirstSubjectId();
        $classSubject = $this->createTestClassSubject(['subject_id' => $subjectId]);

        $this->deleteJson("/api/class-subjects/{$classSubject->id}")->assertStatus(200);

        $this->assertDatabaseHas('subjects', [
            'id' => $subjectId,
        ]);
    }

    public function test_delete_does_not_affect_teachers(): void
    {
        $this->authenticateAsAdmin();

        $teacherId = $this->getFirstTeacherId();
        $classSubject = $this->createTestClassSubject(['teacher_id' => $teacherId]);

        $this->deleteJson("/api/class-subjects/{$classSubject->id}")->assertStatus(200);

        $this->assertDatabaseHas('teachers', [
            'id' => $teacherId,
        ]);
    }

    public function test_delete_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->deleteJson('/api/class-subjects/99999');

        $response->assertStatus(404);
    }

    // ─── IDOR Tests ──────────────────────────────────────────────

    public function test_idor_show_returns_404_for_nonexistent(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/class-subjects/99999');

        $response->assertStatus(404);
    }

    public function test_idor_update_returns_404_for_nonexistent(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->putJson('/api/class-subjects/99999', [
            'teacher_id' => $this->getFirstTeacherId(),
        ]);

        $response->assertStatus(404);
    }

    public function test_idor_delete_returns_404_for_nonexistent(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->deleteJson('/api/class-subjects/99999');

        $response->assertStatus(404);
    }

    // ─── Database Integrity Tests ────────────────────────────────

    public function test_delete_preserves_other_class_subjects(): void
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

        $this->assertDatabaseHas('class_subjects', [
            'id' => $cs2->id,
        ]);
    }

    public function test_store_preserves_existing_records(): void
    {
        $this->authenticateAsAdmin();

        $cs1 = $this->createTestClassSubject([
            'class_id' => $this->getFirstClassId(),
            'subject_id' => $this->getFirstSubjectId(),
        ]);

        $existingCount = DB::connection()->table('class_subjects')->count();

        $this->postJson('/api/class-subjects', [
            'class_id' => $this->getSecondClassId(),
            'subject_id' => $this->getSecondSubjectId(),
        ])->assertStatus(201);

        $newCount = DB::connection()->table('class_subjects')->count();
        $this->assertEquals($existingCount + 1, $newCount);
    }

    // ─── Mass Assignment Tests ───────────────────────────────────

    public function test_store_ignores_id_field(): void
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

    public function test_store_ignores_created_at_field(): void
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

    // ─── Sensitive Field Tests ───────────────────────────────────

    public function test_index_does_not_expose_sensitive_fields(): void
    {
        $this->authenticate();

        $this->createTestClassSubject();

        $response = $this->getJson('/api/class-subjects');

        $response->assertStatus(200);

        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('deleted_at', $item);
        }
    }

    public function test_show_does_not_expose_sensitive_fields(): void
    {
        $this->authenticate();

        $classSubject = $this->createTestClassSubject();

        $response = $this->getJson("/api/class-subjects/{$classSubject->id}");

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }
}
