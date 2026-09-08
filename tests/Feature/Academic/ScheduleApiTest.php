<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Period;
use App\Models\Academic\Schedule;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Staff\Teacher;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleApiTest extends TestCase
{
    private int $classId;
    private int $subjectId;
    private int $teacherId;
    private int $periodId;
    private int $academicYearId;
    private int $semesterId;

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

        $this->cleanupTestSchedules();
        $this->setupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestSchedules();
        parent::tearDown();
    }

    private function setupTestData(): void
    {
        $this->classId = SchoolClass::whereNull('deleted_at')->orderBy('id')->first()->id;
        $this->subjectId = Subject::whereNull('deleted_at')->orderBy('id')->first()->id;
        $this->teacherId = Teacher::whereNull('deleted_at')->orderBy('id')->first()->id;
        $this->periodId = Period::orderBy('id')->first()->id;
        $this->academicYearId = AcademicYear::orderBy('id')->first()->id;
        $this->semesterId = Semester::orderBy('id')->first()->id;
    }

    private function cleanupTestSchedules(): void
    {
        DB::connection('mysql')->table('schedules')
            ->where('id', '>', 100)
            ->delete();
    }

    private function authenticateAsAdmin(): void
    {
        $adminRole = Role::where('name', 'Admin')->first();
        $user = User::where('role_id', $adminRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function createTestSchedule(array $overrides = []): Schedule
    {
        $defaults = [
            'class_id' => $this->classId,
            'subject_id' => $this->subjectId,
            'teacher_id' => $this->teacherId,
            'day' => 'senin',
            'period_id' => $this->periodId,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
        ];

        return Schedule::create(array_merge($defaults, $overrides));
    }

    // ─── Index Tests ───────────────────────────────────────────

    public function test_index_returns_200(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/schedules');
        $response->assertStatus(200);
    }

    public function test_index_returns_json_structure(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/schedules');
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
        $this->createTestSchedule();
        $response = $this->getJson('/api/schedules');
        $response->assertStatus(200);
        $first = $response->json('data')[0] ?? null;
        if ($first) {
            $this->assertArrayHasKey('class', $first);
            $this->assertArrayHasKey('subject', $first);
            $this->assertArrayHasKey('period', $first);
        }
    }

    // ─── Show Tests ────────────────────────────────────────────

    public function test_show_returns_200(): void
    {
        $this->authenticateAsAdmin();
        $schedule = $this->createTestSchedule();
        $response = $this->getJson("/api/schedules/{$schedule->id}");
        $response->assertStatus(200);
    }

    public function test_show_returns_correct_data(): void
    {
        $this->authenticateAsAdmin();
        $schedule = $this->createTestSchedule();
        $response = $this->getJson("/api/schedules/{$schedule->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $schedule->id,
                'class_id' => $this->classId,
                'subject_id' => $this->subjectId,
                'day' => 'senin',
            ],
        ]);
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/schedules/99999');
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Schedule not found',
        ]);
    }

    // ─── Store Tests ───────────────────────────────────────────

    public function test_store_creates_schedule(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/schedules', [
            'class_id' => $this->classId,
            'subject_id' => $this->subjectId,
            'teacher_id' => $this->teacherId,
            'day' => 'selasa',
            'period_id' => $this->periodId,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
        ]);
        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Schedule created successfully',
        ]);
    }

    public function test_store_requires_class_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/schedules', [
            'subject_id' => $this->subjectId,
            'day' => 'senin',
            'period_id' => $this->periodId,
            'academic_year_id' => $this->academicYearId,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['class_id']);
    }

    public function test_store_requires_subject_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/schedules', [
            'class_id' => $this->classId,
            'day' => 'senin',
            'period_id' => $this->periodId,
            'academic_year_id' => $this->academicYearId,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subject_id']);
    }

    public function test_store_requires_day(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/schedules', [
            'class_id' => $this->classId,
            'subject_id' => $this->subjectId,
            'period_id' => $this->periodId,
            'academic_year_id' => $this->academicYearId,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['day']);
    }

    public function test_store_requires_period_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/schedules', [
            'class_id' => $this->classId,
            'subject_id' => $this->subjectId,
            'day' => 'senin',
            'academic_year_id' => $this->academicYearId,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['period_id']);
    }

    public function test_store_requires_academic_year_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/schedules', [
            'class_id' => $this->classId,
            'subject_id' => $this->subjectId,
            'day' => 'senin',
            'period_id' => $this->periodId,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['academic_year_id']);
    }

    public function test_store_rejects_semester_from_another_academic_year(): void
    {
        $this->authenticateAsAdmin();

        $foreignSemester = Semester::where('academic_year_id', '!=', $this->academicYearId)
            ->orderBy('id')
            ->first();

        $this->assertNotNull($foreignSemester, 'No semester from another academic year exists in the seed data.');

        $response = $this->postJson('/api/schedules', [
            'class_id' => $this->classId,
            'subject_id' => $this->subjectId,
            'teacher_id' => $this->teacherId,
            'day' => 'kamis',
            'period_id' => $this->periodId,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $foreignSemester->id,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['semester_id']);
    }

    public function test_store_accepts_semester_from_same_academic_year(): void
    {
        $this->authenticateAsAdmin();

        $sameYearSemester = Semester::where('academic_year_id', $this->academicYearId)
            ->orderBy('id')
            ->first();

        $this->assertNotNull($sameYearSemester, 'No semester for the selected academic year exists in the seed data.');

        $response = $this->postJson('/api/schedules', [
            'class_id' => $this->classId,
            'subject_id' => $this->subjectId,
            'teacher_id' => $this->teacherId,
            'day' => 'kamis',
            'period_id' => $this->periodId,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $sameYearSemester->id,
        ]);
        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Schedule created successfully',
        ]);
    }

    // ─── Update Tests ──────────────────────────────────────────

    public function test_update_changes_schedule(): void
    {
        $this->authenticateAsAdmin();
        $schedule = $this->createTestSchedule(['day' => 'senin']);
        $response = $this->putJson("/api/schedules/{$schedule->id}", [
            'day' => 'rabu',
        ]);
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'day' => 'rabu',
            ],
        ]);
    }

    public function test_update_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/schedules/99999', [
            'day' => 'rabu',
        ]);
        $response->assertStatus(404);
    }

    // ─── Delete Tests ──────────────────────────────────────────

    public function test_delete_removes_schedule(): void
    {
        $this->authenticateAsAdmin();
        $schedule = $this->createTestSchedule();
        $response = $this->deleteJson("/api/schedules/{$schedule->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Schedule deleted successfully',
        ]);
        $this->assertDatabaseMissing('schedules', ['id' => $schedule->id], 'mysql');
    }

    public function test_delete_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/schedules/99999');
        $response->assertStatus(404);
    }

    // ─── Filter Tests ──────────────────────────────────────────

    public function test_filter_by_class_id(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestSchedule();
        $response = $this->getJson("/api/schedules?class_id={$this->classId}");
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals($this->classId, $item['class_id']);
        }
    }

    public function test_filter_by_day(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestSchedule(['day' => 'rabu']);
        $response = $this->getJson('/api/schedules?day=rabu');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('rabu', $item['day']);
        }
    }

    public function test_filter_by_teacher_id(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestSchedule();
        $response = $this->getJson("/api/schedules?teacher_id={$this->teacherId}");
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals($this->teacherId, $item['teacher_id']);
        }
    }

    // ─── Authorization Tests ───────────────────────────────────

    public function test_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/schedules');
        $response->assertStatus(401);
    }
}
