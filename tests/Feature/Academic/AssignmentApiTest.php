<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Assignment;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Subject;
use App\Models\Staff\Teacher;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssignmentApiTest extends TestCase
{
    private int $classId;
    private int $subjectId;
    private int $teacherId;
    private int $academicYearId;

    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupTestAssignments();
        $this->setupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestAssignments();
        parent::tearDown();
    }

    private function setupTestData(): void
    {
        $this->classId = SchoolClass::whereNull('deleted_at')->orderBy('id')->first()->id;
        $this->subjectId = Subject::whereNull('deleted_at')->orderBy('id')->first()->id;
        $this->teacherId = Teacher::whereNull('deleted_at')->orderBy('id')->first()->id;
        $this->academicYearId = AcademicYear::orderBy('id')->first()->id;
    }

    private function cleanupTestAssignments(): void
    {
        DB::connection()->table('assignments')
            ->where('title', 'LIKE', 'Test Assignment%')
            ->delete();
    }

    private function authenticateAsAdmin(): void
    {
        $adminRole = Role::where('name', 'Admin')->first();
        $user = User::where('role_id', $adminRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function createTestAssignment(array $overrides = []): Assignment
    {
        $defaults = [
            'title' => 'Test Assignment ' . mt_rand(1000, 9999),
            'description' => 'Test assignment description',
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'teacher_id' => $this->teacherId,
            'due_date' => '2026-12-31',
            'academic_year_id' => $this->academicYearId,
        ];

        return Assignment::create(array_merge($defaults, $overrides));
    }

    // ─── Index Tests ───────────────────────────────────────────

    public function test_index_returns_200(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/assignments');
        $response->assertStatus(200);
    }

    public function test_index_returns_json_structure(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/assignments');
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
    }

    public function test_index_includes_relationships(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestAssignment();
        $response = $this->getJson('/api/assignments');
        $response->assertStatus(200);
        $first = $response->json('data')[0] ?? null;
        if ($first) {
            $this->assertArrayHasKey('subject', $first);
            $this->assertArrayHasKey('class', $first);
            $this->assertArrayHasKey('academic_year', $first);
        }
    }

    // ─── Show Tests ────────────────────────────────────────────

    public function test_show_returns_200(): void
    {
        $this->authenticateAsAdmin();
        $assignment = $this->createTestAssignment();
        $response = $this->getJson("/api/assignments/{$assignment->id}");
        $response->assertStatus(200);
    }

    public function test_show_returns_correct_data(): void
    {
        $this->authenticateAsAdmin();
        $assignment = $this->createTestAssignment();
        $response = $this->getJson("/api/assignments/{$assignment->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $assignment->id,
                'title' => $assignment->title,
                'subject_id' => $this->subjectId,
                'class_id' => $this->classId,
            ],
        ]);
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/assignments/99999');
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Assignment not found',
        ]);
    }

    // ─── Store Tests ───────────────────────────────────────────

    public function test_store_creates_assignment(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assignments', [
            'title' => 'New Test Assignment',
            'description' => 'New description',
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'teacher_id' => $this->teacherId,
            'due_date' => '2026-12-31',
            'academic_year_id' => $this->academicYearId,
        ]);
        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Assignment created successfully',
        ]);
    }

    public function test_store_requires_title(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assignments', [
            'description' => 'Description',
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title']);
    }

    public function test_store_requires_subject_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assignments', [
            'title' => 'Test',
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subject_id']);
    }

    public function test_store_requires_class_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assignments', [
            'title' => 'Test',
            'subject_id' => $this->subjectId,
            'academic_year_id' => $this->academicYearId,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['class_id']);
    }

    public function test_store_requires_academic_year_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assignments', [
            'title' => 'Test',
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['academic_year_id']);
    }

    public function test_store_validates_due_date_format(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assignments', [
            'title' => 'Test',
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
            'due_date' => 'invalid-date',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['due_date']);
    }

    // ─── Update Tests ──────────────────────────────────────────

    public function test_update_changes_assignment(): void
    {
        $this->authenticateAsAdmin();
        $assignment = $this->createTestAssignment();
        $response = $this->putJson("/api/assignments/{$assignment->id}", [
            'title' => 'Updated Title',
        ]);
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'title' => 'Updated Title',
            ],
        ]);
    }

    public function test_update_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/assignments/99999', [
            'title' => 'Updated',
        ]);
        $response->assertStatus(404);
    }

    // ─── Delete Tests ──────────────────────────────────────────

    public function test_delete_removes_assignment(): void
    {
        $this->authenticateAsAdmin();
        $assignment = $this->createTestAssignment();
        $response = $this->deleteJson("/api/assignments/{$assignment->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Assignment deleted successfully',
        ]);
        $this->assertDatabaseMissing('assignments', ['id' => $assignment->id]);
    }

    public function test_delete_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/assignments/99999');
        $response->assertStatus(404);
    }

    // ─── Filter Tests ──────────────────────────────────────────

    public function test_filter_by_class_id(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestAssignment();
        $response = $this->getJson("/api/assignments?class_id={$this->classId}");
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals($this->classId, $item['class_id']);
        }
    }

    public function test_filter_by_subject_id(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestAssignment();
        $response = $this->getJson("/api/assignments?subject_id={$this->subjectId}");
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals($this->subjectId, $item['subject_id']);
        }
    }

    public function test_filter_by_teacher_id(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestAssignment();
        $response = $this->getJson("/api/assignments?teacher_id={$this->teacherId}");
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals($this->teacherId, $item['teacher_id']);
        }
    }

    public function test_filter_by_academic_year_id(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestAssignment();
        $response = $this->getJson("/api/assignments?academic_year_id={$this->academicYearId}");
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals($this->academicYearId, $item['academic_year_id']);
        }
    }

    public function test_filter_by_search_query(): void
    {
        $this->authenticateAsAdmin();
        $assignment = $this->createTestAssignment(['title' => 'Test Assignment SearchMe']);
        $response = $this->getJson('/api/assignments?q=SearchMe');
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }

    // ─── Authorization Tests ───────────────────────────────────

    public function test_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/assignments');
        $response->assertStatus(401);
    }
}
