<?php

namespace Tests\Feature\Grade;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Grade;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Student portal grade period filtering (Wave 1).
 *
 * The student frontend sends `semester_id` (+ optionally `academic_year_id`).
 * Previously this was silently ignored because the backend only understood the
 * legacy string `semester`. These tests lock in the fix and the response
 * contract (legacy `semester`/`academic_year` strings are retained).
 */
class StudentGradePeriodFilterTest extends TestCase
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

        $this->cleanup();
        $this->setupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function setupTestData(): void
    {
        $this->classId = SchoolClass::whereNull('deleted_at')->orderBy('id')->first()->id;
        $this->subjectId = Subject::whereNull('deleted_at')->orderBy('id')->first()->id;

        $siswaRole = Role::where('name', 'Siswa')->firstOrFail();

        $user = User::create([
            'username' => 'spp_' . mt_rand(100000, 999999),
            'name' => 'Student Portal Test',
            'email' => 'spp.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $siswaRole->id,
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'class_id' => $this->classId,
            'nisn' => 'GN-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'nis' => 'SN-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'name' => 'Student Portal Test',
            'gender' => 'P',
        ]);

        $this->studentId = $student->id;

        Sanctum::actingAs($user);

        $this->createGradeFor($this->resolvePeriod('2025/2026', '1'), 'uts', 85);
        $this->createGradeFor($this->resolvePeriod('2025/2026', '2'), 'tugas', 70);
    }

    private function createGradeFor(array $period, string $type, float $score): Grade
    {
        return Grade::create([
            'student_id' => $this->studentId,
            'subject_id' => $this->subjectId,
            'class_id' => $this->classId,
            'type' => $type,
            'score' => $score,
            'academic_year_id' => $period['academic_year_id'],
            'semester_id' => $period['semester_id'],
            'academic_year' => $period['academic_year'],
            'semester' => $period['semester'],
        ]);
    }

    private function resolvePeriod(string $yearName, string $semesterName): array
    {
        $year = AcademicYear::where('name', $yearName)->whereNull('deleted_at')->firstOrFail();
        $semester = Semester::where('academic_year_id', $year->id)->where('name', $semesterName)->firstOrFail();

        return [
            'academic_year_id' => $year->id,
            'semester_id' => $semester->id,
            'academic_year' => $year->name,
            'semester' => $semester->name,
        ];
    }

    private function cleanup(): void
    {
        DB::table('grades')->where('id', '>', 0)->delete();
        Student::where('nisn', 'like', 'GN-%')->forceDelete();
        Student::where('nis', 'like', 'SN-%')->forceDelete();
        User::where('username', 'like', 'spp_%')->delete();
    }

    public function test_student_grades_index_honors_semester_id_filter(): void
    {
        $period = $this->resolvePeriod('2025/2026', '1');

        $response = $this->getJson('/api/student/grades?semester_id=' . $period['semester_id']);

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals(85.0, $data[0]['uts']);
        $this->assertEquals('1', $data[0]['semester']);
        $this->assertEquals('2025/2026', $data[0]['academic_year']);
    }

    public function test_student_grades_index_honors_academic_year_id_filter(): void
    {
        $period = $this->resolvePeriod('2025/2026', '2');

        $response = $this->getJson('/api/student/grades?' . http_build_query([
            'academic_year_id' => $period['academic_year_id'],
            'semester_id' => $period['semester_id'],
        ]));

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('2', $data[0]['semester']);
        $this->assertEquals('2025/2026', $data[0]['academic_year']);
        $this->assertEquals(70.0, $data[0]['tugas']);
    }

    public function test_student_grades_index_returns_empty_for_unknown_semester_id(): void
    {
        $response = $this->getJson('/api/student/grades?semester_id=999999');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_student_grades_summary_honors_semester_id_filter(): void
    {
        $period = $this->resolvePeriod('2025/2026', '2');

        $response = $this->getJson('/api/student/grades/summary?semester_id=' . $period['semester_id']);

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals(1, $data['total_subjects']);
        $this->assertEquals(70.0, $data['average']);
        $this->assertEquals(70.0, $data['highest']);
    }

    public function test_student_grades_index_rejects_contradictory_semester(): void
    {
        $period = $this->resolvePeriod('2025/2026', '1');

        $response = $this->getJson('/api/student/grades?' . http_build_query([
            'semester_id' => $period['semester_id'],
            'semester' => '2',
        ]));

        $response->assertStatus(422);
    }
}