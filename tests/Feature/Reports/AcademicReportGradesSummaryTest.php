<?php

namespace Tests\Feature\Reports;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Grade;
use App\Models\Academic\GradeAssessment;
use App\Models\Academic\Semester;
use App\Models\Students\Student;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2L-7E — Academic grades summary migrated to canonical weighted finals.
 *
 * Hermetic suite: builds its own minimal schema on the default (sqlite :memory:)
 * connection. Exercises GET /api/reports/academic/grades-summary end to end.
 */
class AcademicReportGradesSummaryTest extends TestCase
{
    private User $user;

    private Student $student1;

    private Student $student2;

    private Student $student3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->seedFixture();
    }

    private function buildSchema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->nullable();
            $t->string('username')->nullable();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('academic_years', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('semesters', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('academic_year_id');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->string('nis')->nullable();
            $t->string('name');
            $t->string('gender', 1)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('grades', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('class_id');
            $t->string('type');
            $t->decimal('score', 5, 2)->default(0);
            $t->string('semester', 10)->nullable();
            $t->string('academic_year', 20)->nullable();
            $t->unsignedBigInteger('semester_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->timestamps();
        });
        Schema::create('grade_assessments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->unsignedBigInteger('semester_id');
            $t->string('assessment_category', 50);
            $t->unsignedInteger('assessment_sequence');
            $t->decimal('score', 5, 2);
            $t->decimal('max_score', 5, 2)->default(100);
            $t->decimal('weight', 5, 2)->nullable();
            $t->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $this->user = User::create([
            'name' => 'Report User',
            'email' => 'report@test.local',
            'username' => 'report',
            'password' => 'password',
            'is_active' => true,
        ]);

        $ay1 = AcademicYear::create(['name' => '2025/2026']);
        $ay2 = AcademicYear::create(['name' => '2026/2027']);
        Semester::create(['academic_year_id' => $ay1->id, 'name' => '1']);
        Semester::create(['academic_year_id' => $ay1->id, 'name' => '2']);
        Semester::create(['academic_year_id' => $ay2->id, 'name' => '1']);

        $this->student1 = Student::create(['name' => 'Siswa 1', 'class_id' => 1]);
        $this->student2 = Student::create(['name' => 'Siswa 2', 'class_id' => 1]);
        $this->student3 = Student::create(['name' => 'Siswa 3', 'class_id' => 2]);

        Sanctum::actingAs($this->user);
    }

    private function addGrade(int $studentId, array $overrides = []): void
    {
        Grade::create(array_merge([
            'student_id' => $studentId,
            'subject_id' => 1,
            'class_id' => 1,
            'type' => 'tugas',
            'score' => 85.00,
            'semester' => '1',
            'academic_year' => '2025/2026',
            'semester_id' => 1,
            'academic_year_id' => 1,
        ], $overrides));
    }

    private function addAssessment(int $studentId, array $overrides = []): void
    {
        GradeAssessment::create(array_merge([
            'student_id' => $studentId,
            'subject_id' => 1,
            'class_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'assessment_category' => 'tugas',
            'assessment_sequence' => 1,
            'score' => 85.00,
            'max_score' => 100.00,
            'weight' => null,
        ], $overrides));
    }

    private function getSummary(array $params = []): array
    {
        return $this->getJson('/api/reports/academic/grades-summary?'.http_build_query($params))
            ->assertOk()
            ->json();
    }

    public function test_canonical_weighted_final(): void
    {
        $this->addGrade($this->student1->id, ['type' => 'uts', 'score' => 60.00]);
        $this->addGrade($this->student1->id, ['type' => 'uas', 'score' => 90.00]);

        $this->addAssessment($this->student1->id, ['assessment_category' => 'tugas', 'score' => 80.00, 'weight' => 1.0]);
        $this->addAssessment($this->student1->id, ['assessment_category' => 'uts', 'score' => 60.00, 'weight' => 2.0]);
        $this->addAssessment($this->student1->id, ['assessment_category' => 'uas', 'score' => 90.00, 'weight' => 1.0]);

        $json = $this->getSummary();

        $this->assertEquals(72.5, $json['data'][0]['average_score'], '(80*1 + 60*2 + 90*1) / 4');
        $this->assertSame(1, $json['data'][0]['total_grades']);
    }

    public function test_no_assessments_is_zero(): void
    {
        $this->addGrade($this->student1->id);

        $json = $this->getSummary();

        $this->assertEquals(0.0, $json['data'][0]['average_score']);
        $this->assertSame(0, $json['data'][0]['total_grades']);
        $this->assertSame('Siswa 1', $json['data'][0]['student_name']);
    }

    public function test_stale_grade_score_is_ignored(): void
    {
        $this->addGrade($this->student1->id, ['score' => 50.00]);
        $this->addAssessment($this->student1->id, ['score' => 90.00]);

        $json = $this->getSummary();

        $this->assertEquals(90.0, $json['data'][0]['average_score']);
    }

    public function test_multiple_subjects_contribute_one_final_each(): void
    {
        $this->addGrade($this->student1->id, ['type' => 'uts', 'score' => 60.00]);
        $this->addGrade($this->student1->id, ['type' => 'uas', 'score' => 90.00]);
        $this->addGrade($this->student1->id, ['subject_id' => 2, 'score' => 70.00]);
        $this->addGrade($this->student1->id, ['subject_id' => 2, 'type' => 'uts', 'score' => 80.00]);

        $this->addAssessment($this->student1->id, ['assessment_category' => 'tugas', 'score' => 80.00, 'weight' => 1.0]);
        $this->addAssessment($this->student1->id, ['assessment_category' => 'uts', 'score' => 60.00, 'weight' => 2.0]);
        $this->addAssessment($this->student1->id, ['assessment_category' => 'uas', 'score' => 90.00, 'weight' => 1.0]);
        $this->addAssessment($this->student1->id, ['subject_id' => 2, 'assessment_category' => 'tugas', 'score' => 70.00]);
        $this->addAssessment($this->student1->id, ['subject_id' => 2, 'assessment_category' => 'uts', 'score' => 80.00]);

        $json = $this->getSummary();

        $this->assertSame(2, $json['data'][0]['total_grades'], 'one contribution per subject identity');
        $this->assertEquals(73.75, $json['data'][0]['average_score'], '(72.5 + 75) / 2');
    }

    public function test_multiple_assessments_within_bucket_fold_to_one_final(): void
    {
        $this->addGrade($this->student1->id, ['type' => 'uts', 'score' => 100.00]);
        $this->addGrade($this->student1->id, ['type' => 'uas', 'score' => 100.00]);

        $this->addAssessment($this->student1->id, ['assessment_category' => 'tugas', 'score' => 80.00]);
        $this->addAssessment($this->student1->id, ['assessment_category' => 'tugas', 'score' => 90.00, 'assessment_sequence' => 2]);
        $this->addAssessment($this->student1->id, ['assessment_category' => 'uts', 'score' => 100.00]);
        $this->addAssessment($this->student1->id, ['assessment_category' => 'uas', 'score' => 100.00]);

        $json = $this->getSummary();

        $this->assertSame(1, $json['data'][0]['total_grades'], 'one identity contributes exactly one final');
        $this->assertEquals(95.0, $json['data'][0]['average_score'], '(85 + 100 + 100) / 3');
    }

    public function test_different_class_identities_do_not_mix(): void
    {
        $this->addGrade($this->student1->id, ['type' => 'uts', 'score' => 100.00]);
        $this->addGrade($this->student1->id, ['class_id' => 2, 'type' => 'uts', 'score' => 0.00]);

        $this->addAssessment($this->student1->id, ['assessment_category' => 'uts', 'score' => 100.00]);
        $this->addAssessment($this->student1->id, ['class_id' => 2, 'assessment_category' => 'uts', 'score' => 0.00]);

        $json = $this->getSummary();

        $this->assertSame(2, $json['data'][0]['total_grades'], 'class identity is part of the canonical tuple');
        $this->assertEquals(50.0, $json['data'][0]['average_score'], '(100 + 0) / 2');
    }

    public function test_semester_filtering(): void
    {
        $this->addGrade($this->student1->id, ['semester_id' => 1, 'semester' => '1', 'score' => 80.00]);
        $this->addGrade($this->student1->id, ['semester_id' => 2, 'semester' => '2', 'score' => 100.00]);
        $this->addAssessment($this->student1->id, ['semester_id' => 1, 'score' => 80.00]);
        $this->addAssessment($this->student1->id, ['semester_id' => 2, 'score' => 100.00]);

        $first = $this->getSummary(['semester_id' => 1]);
        $this->assertSame(1, $first['data'][0]['total_grades']);
        $this->assertEquals(80.0, $first['data'][0]['average_score']);

        $second = $this->getSummary(['semester_id' => 2]);
        $this->assertSame(1, $second['data'][0]['total_grades']);
        $this->assertEquals(100.0, $second['data'][0]['average_score']);
    }

    public function test_academic_year_filtering(): void
    {
        $this->addGrade($this->student1->id, ['academic_year_id' => 1, 'academic_year' => '2025/2026', 'score' => 80.00]);
        $this->addGrade($this->student1->id, ['academic_year_id' => 2, 'academic_year' => '2026/2027', 'score' => 100.00]);
        $this->addAssessment($this->student1->id, ['academic_year_id' => 1, 'score' => 80.00]);
        $this->addAssessment($this->student1->id, ['academic_year_id' => 2, 'score' => 100.00]);

        $json = $this->getSummary(['academic_year_id' => 1]);
        $this->assertSame(1, $json['data'][0]['total_grades']);
        $this->assertEquals(80.0, $json['data'][0]['average_score']);
    }

    public function test_class_filtering(): void
    {
        $this->addGrade($this->student1->id, ['score' => 80.00]);
        $this->addGrade($this->student2->id, ['score' => 90.00]);
        $this->addGrade($this->student3->id, ['class_id' => 2, 'score' => 95.00]);
        $this->addAssessment($this->student1->id, ['score' => 80.00]);
        $this->addAssessment($this->student2->id, ['score' => 90.00]);
        $this->addAssessment($this->student3->id, ['class_id' => 2, 'score' => 95.00]);

        $json = $this->getSummary(['class_id' => 1]);

        $this->assertSame(2, $json['meta']['total']);
        $this->assertEquals(90.0, $json['data'][0]['average_score']);
        $this->assertEquals(80.0, $json['data'][1]['average_score']);
    }

    public function test_subject_filtering(): void
    {
        $this->addGrade($this->student1->id, ['score' => 80.00]);
        $this->addGrade($this->student1->id, ['subject_id' => 2, 'score' => 90.00]);
        $this->addAssessment($this->student1->id, ['score' => 80.00]);
        $this->addAssessment($this->student1->id, ['subject_id' => 2, 'score' => 90.00]);

        $json = $this->getSummary(['subject_id' => 2]);

        $this->assertSame(1, $json['data'][0]['total_grades']);
        $this->assertEquals(90.0, $json['data'][0]['average_score']);
    }

    public function test_weighted_categories_override_equal_mean(): void
    {
        $this->addGrade($this->student1->id, ['type' => 'uts', 'score' => 60.00]);
        $this->addGrade($this->student1->id, ['type' => 'uas', 'score' => 90.00]);
        $this->addAssessment($this->student1->id, ['score' => 80.00, 'weight' => 1.0]);
        $this->addAssessment($this->student1->id, ['assessment_category' => 'uts', 'score' => 60.00, 'weight' => 2.0]);
        $this->addAssessment($this->student1->id, ['assessment_category' => 'uas', 'score' => 90.00, 'weight' => 1.0]);

        $json = $this->getSummary();

        $this->assertEquals(72.5, $json['data'][0]['average_score'], '72.5, not the grades-backed 76.67');
    }

    public function test_zero_weight_category_is_excluded(): void
    {
        $this->addGrade($this->student1->id, ['type' => 'uts', 'score' => 60.00]);
        $this->addGrade($this->student1->id, ['type' => 'uas', 'score' => 90.00]);
        $this->addAssessment($this->student1->id, ['score' => 80.00, 'weight' => 1.0]);
        $this->addAssessment($this->student1->id, ['assessment_category' => 'uts', 'score' => 60.00, 'weight' => 0.0]);
        $this->addAssessment($this->student1->id, ['assessment_category' => 'uas', 'score' => 90.00, 'weight' => 1.0]);

        $json = $this->getSummary();

        $this->assertEquals(85.0, $json['data'][0]['average_score'], '(80 + 90) / 2');
    }

    public function test_zero_score_remains_valid_contribution(): void
    {
        $this->addGrade($this->student1->id, ['type' => 'uts', 'score' => 100.00]);
        $this->addAssessment($this->student1->id, ['score' => 0.00]);
        $this->addAssessment($this->student1->id, ['assessment_category' => 'uts', 'score' => 100.00]);

        $json = $this->getSummary();

        $this->assertSame(1, $json['data'][0]['total_grades']);
        $this->assertEquals(50.0, $json['data'][0]['average_score']);
    }

    public function test_no_eligible_final_contributes_nothing(): void
    {
        $this->addGrade($this->student1->id, ['score' => 80.00]);
        $this->addAssessment($this->student1->id, ['score' => 80.00, 'weight' => 0.0]);

        $json = $this->getSummary();

        $this->assertSame(0, $json['data'][0]['total_grades']);
        $this->assertEquals(0.0, $json['data'][0]['average_score']);
    }

    public function test_global_sorting_before_pagination(): void
    {
        $this->addGrade($this->student1->id, ['score' => 90.00]);
        $this->addGrade($this->student2->id, ['score' => 70.00]);
        $this->addGrade($this->student3->id, ['class_id' => 2, 'score' => 80.00]);
        $this->addAssessment($this->student1->id, ['score' => 90.00]);
        $this->addAssessment($this->student2->id, ['score' => 70.00]);
        $this->addAssessment($this->student3->id, ['class_id' => 2, 'score' => 80.00]);

        $first = $this->getSummary(['per_page' => 2, 'page' => 1]);
        $this->assertEquals([90.0, 80.0], array_column($first['data'], 'average_score'));

        $second = $this->getSummary(['per_page' => 2, 'page' => 2]);
        $this->assertCount(1, $second['data']);
        $this->assertEquals(70.0, $second['data'][0]['average_score']);
        $this->assertSame(3, $second['meta']['total']);
    }

    public function test_pagination_boundary(): void
    {
        $this->addGrade($this->student1->id, ['score' => 90.00]);
        $this->addGrade($this->student2->id, ['score' => 80.00]);
        $this->addGrade($this->student3->id, ['class_id' => 2, 'score' => 70.00]);
        $this->addAssessment($this->student1->id, ['score' => 90.00]);
        $this->addAssessment($this->student2->id, ['score' => 80.00]);
        $this->addAssessment($this->student3->id, ['class_id' => 2, 'score' => 70.00]);

        $json = $this->getSummary(['per_page' => 2, 'page' => 3]);

        $this->assertSame([], $json['data']);
        $this->assertSame(3, $json['meta']['total']);
        $this->assertSame(2, $json['meta']['last_page']);
    }

    public function test_deterministic_student_id_tie_break(): void
    {
        $this->addGrade($this->student1->id, ['score' => 80.00]);
        $this->addGrade($this->student2->id, ['score' => 80.00]);
        $this->addAssessment($this->student1->id, ['score' => 80.00]);
        $this->addAssessment($this->student2->id, ['score' => 80.00]);

        $json = $this->getSummary();

        $this->assertCount(2, $json['data']);
        $this->assertSame($this->student1->id, $json['data'][0]['student_id'], 'equal averages sort by student_id ASC');
        $this->assertSame($this->student2->id, $json['data'][1]['student_id']);
    }

    public function test_empty_population(): void
    {
        $json = $this->getSummary();

        $this->assertSame([], $json['data']);
        $this->assertSame(0, $json['meta']['total']);
        $this->assertSame(1, $json['meta']['last_page']);
    }

    public function test_response_shape_unchanged(): void
    {
        $this->addGrade($this->student1->id, ['score' => 80.00]);
        $this->addAssessment($this->student1->id, ['score' => 80.00]);

        $json = $this->getJson('/api/reports/academic/grades-summary')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'meta'])
            ->json();

        $this->assertArrayHasKey('student_id', $json['data'][0]);
        $this->assertArrayHasKey('student_name', $json['data'][0]);
        $this->assertArrayHasKey('average_score', $json['data'][0]);
        $this->assertArrayHasKey('total_grades', $json['data'][0]);
    }

    public function test_meta_total_equals_matched_population(): void
    {
        // student2 appears in the report but has no assessment evidence -> 0/0 row.
        $this->addGrade($this->student1->id, ['score' => 80.00]);
        $this->addGrade($this->student2->id, ['score' => 90.00]);
        $this->addAssessment($this->student1->id, ['score' => 80.00]);

        $json = $this->getSummary();

        $this->assertSame(2, $json['meta']['total'], 'students without assessments remain in the population');
        $this->assertSame(1, $json['data'][0]['total_grades']);
        $this->assertEquals(80.0, $json['data'][0]['average_score']);
        $this->assertSame(0, $json['data'][1]['total_grades']);
        $this->assertEquals(0.0, $json['data'][1]['average_score']);
    }

    public function test_legacy_string_alias_filters(): void
    {
        $this->addGrade($this->student1->id, ['semester_id' => 1, 'semester' => '1', 'academic_year' => '2025/2026', 'score' => 80.00]);
        $this->addGrade($this->student1->id, ['semester_id' => 2, 'semester' => '2', 'academic_year' => '2025/2026', 'score' => 100.00]);
        $this->addAssessment($this->student1->id, ['semester_id' => 1, 'score' => 80.00]);
        $this->addAssessment($this->student1->id, ['semester_id' => 2, 'score' => 100.00]);

        $json = $this->getSummary(['semester' => '2', 'academic_year' => '2025/2026']);

        $this->assertSame(1, $json['data'][0]['total_grades']);
        $this->assertEquals(100.0, $json['data'][0]['average_score']);
    }

    public function test_single_bulk_assessment_query_for_population(): void
    {
        $this->addGrade($this->student1->id, ['score' => 90.00]);
        $this->addGrade($this->student2->id, ['score' => 80.00]);
        $this->addGrade($this->student3->id, ['class_id' => 2, 'score' => 70.00]);
        $this->addAssessment($this->student1->id, ['score' => 90.00]);
        $this->addAssessment($this->student2->id, ['score' => 80.00]);
        $this->addAssessment($this->student3->id, ['class_id' => 2, 'score' => 70.00]);

        DB::enableQueryLog();

        $this->getSummary();

        $queries = DB::getQueryLog();

        $assessmentQueries = collect($queries)
            ->filter(fn (array $entry) => str_contains($entry['query'], 'grade_assessments'))
            ->count();

        $this->assertSame(1, $assessmentQueries, 'exactly one bulk assessment query for the whole population (no N+1)');
    }
}
