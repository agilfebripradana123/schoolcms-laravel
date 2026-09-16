<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ReportCard;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportCardApiTest extends TestCase
{
    private int $studentId;
    private int $classId;
    private int $academicYearId;
    private int $semesterId;

    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupTestReportCards();
        $this->cleanupTestStudents();
        $this->setupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestReportCards();
        $this->cleanupTestStudents();
        parent::tearDown();
    }

    private function setupTestData(): void
    {
        $this->classId = SchoolClass::whereNull('deleted_at')->orderBy('id')->first()->id;
        $this->academicYearId = AcademicYear::orderBy('id')->first()->id;
        $this->semesterId = Semester::orderBy('id')->first()->id;

        $student = $this->createTestStudent(['class_id' => $this->classId]);
        $this->studentId = $student->id;
    }

    private function cleanupTestReportCards(): void
    {
        DB::connection()->table('report_cards')
            ->where('id', '>', 105)
            ->delete();
    }

    private function cleanupTestStudents(): void
    {
        Student::where('nisn', 'LIKE', 'RC-%')->forceDelete();
        Student::where('nis', 'LIKE', 'RC-%')->forceDelete();
    }

    private function authenticateAsAdmin(): void
    {
        $adminRole = Role::where('name', 'Admin')->first();
        $user = User::where('role_id', $adminRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function createTestStudent(array $overrides = []): Student
    {
        $defaults = [
            'nisn' => 'RC-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'nis' => 'RC-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'name' => 'Test Student RC',
            'gender' => 'L',
            'birth_place' => 'Test City',
            'birth_date' => '2008-01-01',
            'address' => 'Test Address',
        ];

        return Student::create(array_merge($defaults, $overrides));
    }

    private function createTestReportCard(array $overrides = []): ReportCard
    {
        $defaults = [
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
            'teacher_notes' => 'Test notes',
            'status' => 'draft',
        ];

        return ReportCard::create(array_merge($defaults, $overrides));
    }

    // ─── Index Tests ───────────────────────────────────────────

    public function test_index_returns_200(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/report-cards');
        $response->assertStatus(200);
    }

    public function test_index_returns_json_structure(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/report-cards');
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
        $this->createTestReportCard();
        $response = $this->getJson('/api/report-cards');
        $response->assertStatus(200);
        $first = $response->json('data')[0] ?? null;
        if ($first) {
            $this->assertArrayHasKey('student', $first);
            $this->assertArrayHasKey('class', $first);
            $this->assertArrayHasKey('academic_year', $first);
            $this->assertArrayHasKey('semester', $first);
        }
    }

    // ─── Show Tests ────────────────────────────────────────────

    public function test_show_returns_200(): void
    {
        $this->authenticateAsAdmin();
        $reportCard = $this->createTestReportCard();
        $response = $this->getJson("/api/report-cards/{$reportCard->id}");
        $response->assertStatus(200);
    }

    public function test_show_returns_correct_data(): void
    {
        $this->authenticateAsAdmin();
        $reportCard = $this->createTestReportCard();
        $response = $this->getJson("/api/report-cards/{$reportCard->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $reportCard->id,
                'student_id' => $this->studentId,
                'class_id' => $this->classId,
                'status' => 'draft',
            ],
        ]);
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/report-cards/99999');
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Report card not found',
        ]);
    }

    // ─── Store Tests ───────────────────────────────────────────

    public function test_store_creates_report_card(): void
    {
        $this->authenticateAsAdmin();
        $student2 = $this->createTestStudent(['class_id' => $this->classId]);
        $response = $this->postJson('/api/report-cards', [
            'student_id' => $student2->id,
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
            'teacher_notes' => 'Good student',
            'status' => 'draft',
        ]);
        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Report card created successfully',
        ]);
    }

    public function test_store_requires_student_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/report-cards', [
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
            'status' => 'draft',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['student_id']);
    }

    public function test_store_requires_class_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/report-cards', [
            'student_id' => $this->studentId,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
            'status' => 'draft',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['class_id']);
    }

    public function test_store_requires_academic_year_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/report-cards', [
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'semester_id' => $this->semesterId,
            'status' => 'draft',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['academic_year_id']);
    }

    public function test_store_requires_semester_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/report-cards', [
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
            'status' => 'draft',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['semester_id']);
    }

    public function test_store_prevents_duplicate(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestReportCard();
        $response = $this->postJson('/api/report-cards', [
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
            'status' => 'draft',
        ]);
        $response->assertStatus(422);
    }

    // ─── Update Tests ──────────────────────────────────────────

    public function test_update_changes_report_card(): void
    {
        $this->authenticateAsAdmin();
        $reportCard = $this->createTestReportCard(['status' => 'draft']);
        $response = $this->putJson("/api/report-cards/{$reportCard->id}", [
            'status' => 'published',
            'teacher_notes' => 'Updated notes',
        ]);
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'status' => 'published',
            ],
        ]);
    }

    public function test_update_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/report-cards/99999', [
            'status' => 'published',
        ]);
        $response->assertStatus(404);
    }

    // ─── Delete Tests ──────────────────────────────────────────

    public function test_delete_removes_report_card(): void
    {
        $this->authenticateAsAdmin();
        $reportCard = $this->createTestReportCard();
        $response = $this->deleteJson("/api/report-cards/{$reportCard->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Report card deleted successfully',
        ]);
        $this->assertDatabaseMissing('report_cards', ['id' => $reportCard->id]);
    }

    public function test_delete_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/report-cards/99999');
        $response->assertStatus(404);
    }

    // ─── Filter Tests ──────────────────────────────────────────

    public function test_filter_by_student_id(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestReportCard();
        $response = $this->getJson("/api/report-cards?student_id={$this->studentId}");
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals($this->studentId, $item['student_id']);
        }
    }

    public function test_filter_by_class_id(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestReportCard();
        $response = $this->getJson("/api/report-cards?class_id={$this->classId}");
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals($this->classId, $item['class_id']);
        }
    }

    public function test_filter_by_status(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestReportCard(['status' => 'published']);
        $response = $this->getJson('/api/report-cards?status=published');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('published', $item['status']);
        }
    }

    // ─── Authorization Tests ───────────────────────────────────

    public function test_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/report-cards');
        $response->assertStatus(401);
    }
}
