<?php

namespace Tests\Feature\GradeAssessment;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Grade;
use App\Models\Academic\GradeAssessment;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Students\Student;
use App\Services\Academic\GradeAggregationException;
use App\Services\Academic\GradeAggregationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsGradeTestSchema;
use Tests\TestCase;

/**
 * Phase 2L-3 Stage B — derived aggregation engine.
 *
 * Hermetic suite; builds its own schema on the default (sqlite :memory:)
 * connection in setUp — never touches live MySQL.
 *
 * The engine is COMPUTE ONLY: these tests also pin that grades.score is never
 * mutated by aggregation.
 */
class GradeAggregationTest extends TestCase
{
    use BuildsGradeTestSchema;

    private GradeAggregationService $service;

    private Student $student1;

    private Student $student2;

    private Subject $subject1;

    private Subject $subject2;

    private SchoolClass $class1;

    private SchoolClass $class2;

    private AcademicYear $ay;

    private Semester $semester1;

    private Semester $semester2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGradeSchema();

        Schema::create('grade_assessments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->unsignedBigInteger('semester_id');
            $t->string('assessment_category', 50);
            $t->unsignedInteger('assessment_sequence');
            $t->string('assessment_name', 100)->nullable();
            $t->decimal('score', 5, 2);
            $t->decimal('max_score', 5, 2)->default(100);
            $t->decimal('weight', 5, 2)->nullable();
            $t->string('source_type', 50)->nullable();
            $t->unsignedInteger('source_id')->nullable();
            $t->date('assessed_date')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(
                ['student_id', 'subject_id', 'class_id', 'academic_year_id', 'semester_id', 'assessment_category', 'assessment_sequence'],
                'uq_grade_assessments_cat_seq'
            );
        });

        $this->seedGradeBaseline();

        $this->service = app(GradeAggregationService::class);
        $this->class1 = SchoolClass::where('name', '10A')->firstOrFail();
        $this->class2 = SchoolClass::where('name', '10B')->firstOrFail();
        $this->subject1 = Subject::where('code', 'MTK')->firstOrFail();
        $this->subject2 = Subject::where('code', 'PAI')->firstOrFail();
        $this->ay = AcademicYear::where('name', '2026/2027')->firstOrFail();
        $this->semester1 = Semester::where('academic_year_id', $this->ay->id)->where('name', '1')->firstOrFail();
        $this->semester2 = Semester::where('academic_year_id', $this->ay->id)->where('name', '2')->firstOrFail();

        $this->student1 = Student::create(['name' => 'Student 1', 'class_id' => $this->class1->id]);
        $this->student2 = Student::create(['name' => 'Student 2', 'class_id' => $this->class1->id]);
    }

    private function assessment(array $overrides = []): GradeAssessment
    {
        return GradeAssessment::create(array_merge([
            'student_id' => $this->student1->id,
            'subject_id' => $this->subject1->id,
            'class_id' => $this->class1->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $this->semester1->id,
            'assessment_category' => 'tugas',
            'assessment_sequence' => 1,
            'score' => 80.00,
            'max_score' => 100.00,
        ], $overrides));
    }

    /** @return array<string, array{score: float, assessment_count: int}> */
    private function aggregate(): array
    {
        return $this->service->aggregate(
            $this->student1->id,
            $this->subject1->id,
            $this->class1->id,
            $this->ay->id,
            $this->semester1->id,
        );
    }

    public function test_single_assessment(): void
    {
        $this->assessment(['assessment_category' => 'tugas', 'score' => 80.00]);

        $result = $this->aggregate();

        $this->assertSame(80.0, $result['tugas']['score']);
        $this->assertSame(1, $result['tugas']['assessment_count']);
    }

    public function test_non_hundred_max_score_rescales(): void
    {
        $this->assessment(['score' => 40.00, 'max_score' => 50.00]);

        $result = $this->aggregate();

        $this->assertSame(80.0, $result['tugas']['score']);
        $this->assertSame(1, $result['tugas']['assessment_count']);
    }

    public function test_multiple_assessments_use_arithmetic_mean(): void
    {
        $this->assessment(['assessment_sequence' => 1, 'score' => 70.00]);
        $this->assessment(['assessment_sequence' => 2, 'score' => 80.00]);
        $this->assessment(['assessment_sequence' => 3, 'score' => 90.00]);

        $result = $this->aggregate();

        $this->assertSame(80.0, $result['tugas']['score']);
        $this->assertSame(3, $result['tugas']['assessment_count']);
    }

    public function test_category_bucket_mapping_folds_into_tugas(): void
    {
        $this->assessment(['assessment_category' => 'tugas', 'score' => 80.00]);
        $this->assessment(['assessment_category' => 'PH', 'score' => 90.00]);
        $this->assessment(['assessment_category' => 'PTS', 'score' => 70.00]);

        $result = $this->aggregate();

        $this->assertArrayHasKey('tugas', $result);
        $this->assertArrayNotHasKey('uts', $result);
        $this->assertArrayNotHasKey('uas', $result);
        $this->assertSame(80.0, $result['tugas']['score']);
        $this->assertSame(3, $result['tugas']['assessment_count']);
    }

    public function test_uts_is_isolated_to_uts_bucket(): void
    {
        $this->assessment(['assessment_category' => 'tugas', 'score' => 90.00]);
        $this->assessment(['assessment_category' => 'uts', 'assessment_sequence' => 1, 'score' => 60.00]);

        $result = $this->aggregate();

        $this->assertSame(90.0, $result['tugas']['score']);
        $this->assertSame(60.0, $result['uts']['score']);
        $this->assertSame(1, $result['uts']['assessment_count']);
    }

    public function test_uas_is_isolated_to_uas_bucket(): void
    {
        $this->assessment(['assessment_category' => 'tugas', 'score' => 90.00]);
        $this->assessment(['assessment_category' => 'uas', 'assessment_sequence' => 1, 'score' => 55.00]);

        $result = $this->aggregate();

        $this->assertSame(90.0, $result['tugas']['score']);
        $this->assertSame(55.0, $result['uas']['score']);
        $this->assertSame(1, $result['uas']['assessment_count']);
    }

    public function test_missing_category_is_omitted_not_zeroed(): void
    {
        $this->assessment(['assessment_category' => 'tugas', 'score' => 80.00]);

        $result = $this->aggregate();

        $this->assertArrayHasKey('tugas', $result);
        $this->assertArrayNotHasKey('uts', $result);
        $this->assertArrayNotHasKey('uas', $result);
    }

    public function test_remedial_participates_in_tugas_bucket(): void
    {
        $this->assessment(['assessment_category' => 'tugas', 'assessment_sequence' => 1, 'score' => 90.00]);
        $this->assessment(['assessment_category' => 'remedial', 'assessment_sequence' => 1, 'score' => 50.00]);

        $result = $this->aggregate();

        $this->assertSame(70.0, $result['tugas']['score']);
        $this->assertSame(2, $result['tugas']['assessment_count']);
    }

    public function test_other_participates_in_tugas_bucket(): void
    {
        $this->assessment(['assessment_category' => 'tugas', 'assessment_sequence' => 1, 'score' => 80.00]);
        $this->assessment(['assessment_category' => 'other', 'assessment_sequence' => 1, 'score' => 100.00]);

        $result = $this->aggregate();

        $this->assertSame(90.0, $result['tugas']['score']);
        $this->assertSame(2, $result['tugas']['assessment_count']);
    }

    public function test_identity_isolation_never_mixes_records(): void
    {
        $this->assessment(['assessment_category' => 'tugas', 'score' => 80.00]);

        // Another student, same class/subject/period.
        $this->assessment([
            'student_id' => $this->student2->id,
            'assessment_category' => 'tugas',
            'score' => 10.00,
        ]);
        // Same student, another subject.
        $this->assessment([
            'subject_id' => $this->subject2->id,
            'assessment_category' => 'tugas',
            'score' => 20.00,
        ]);
        // Same student, another class.
        $this->assessment([
            'class_id' => $this->class2->id,
            'assessment_category' => 'tugas',
            'score' => 30.00,
        ]);
        // Same student, another semester.
        $this->assessment([
            'semester_id' => $this->semester2->id,
            'assessment_category' => 'tugas',
            'score' => 40.00,
        ]);
        // Same student, another academic year.
        $otherYear = AcademicYear::where('name', '2025/2026')->firstOrFail();
        $otherYearSemester = Semester::where('academic_year_id', $otherYear->id)->where('name', '1')->firstOrFail();
        $this->assessment([
            'academic_year_id' => $otherYear->id,
            'semester_id' => $otherYearSemester->id,
            'assessment_category' => 'tugas',
            'score' => 50.00,
        ]);

        $result = $this->aggregate();

        $this->assertSame(['tugas'], array_keys($result));
        $this->assertSame(80.0, $result['tugas']['score']);
        $this->assertSame(1, $result['tugas']['assessment_count']);
    }

    public function test_max_score_zero_is_rejected(): void
    {
        $this->assessment(['max_score' => 0.00]);

        $this->expectException(GradeAggregationException::class);

        $this->aggregate();
    }

    public function test_derived_score_stays_within_domain(): void
    {
        // 100/50 = 200% -> clamped to 100; 0/50 = 0 -> clamped to 0.
        $this->assessment(['assessment_sequence' => 1, 'score' => 100.00, 'max_score' => 50.00]);
        $this->assessment(['assessment_sequence' => 2, 'score' => 0.00, 'max_score' => 50.00]);

        $result = $this->aggregate();

        $this->assertGreaterThanOrEqual(0.0, $result['tugas']['score']);
        $this->assertLessThanOrEqual(100.0, $result['tugas']['score']);
        $this->assertSame(50.0, $result['tugas']['score']);
    }

    public function test_weight_is_ignored(): void
    {
        $this->assessment(['assessment_sequence' => 1, 'score' => 60.00, 'weight' => 10.00]);
        $this->assessment(['assessment_sequence' => 2, 'score' => 90.00, 'weight' => 40.00]);

        $result = $this->aggregate();

        // Unweighted mean (75), NOT the weighted mix (60*10 + 90*40)/50.
        $this->assertSame(75.0, $result['tugas']['score']);
    }

    public function test_aggregation_is_deterministic(): void
    {
        $this->assessment(['assessment_sequence' => 1, 'score' => 70.00]);
        $this->assessment(['assessment_sequence' => 2, 'score' => 95.00]);
        $this->assessment(['assessment_category' => 'uts', 'assessment_sequence' => 1, 'score' => 55.00]);

        $first = $this->aggregate();
        $second = $this->aggregate();

        $this->assertSame($first, $second);
        $this->assertSame(82.5, $first['tugas']['score']);
        $this->assertSame(55.0, $first['uts']['score']);
    }

    public function test_aggregation_never_writes_back_to_grades(): void
    {
        $grade = Grade::create([
            'student_id' => $this->student1->id,
            'subject_id' => $this->subject1->id,
            'class_id' => $this->class1->id,
            'type' => 'tugas',
            'score' => 99.00,
            'semester' => '1',
            'academic_year' => '2026/2027',
            'semester_id' => $this->semester1->id,
            'academic_year_id' => $this->ay->id,
        ]);

        $this->assessment(['assessment_category' => 'tugas', 'score' => 80.00]);

        $this->aggregate();

        $this->assertSame(1, Grade::count(), 'aggregation must never create grade rows');
        $grade->refresh();
        $this->assertSame('99.00', (string) $grade->score, 'grades.score must remain unchanged');
        $this->assertSame('tugas', $grade->type);
    }

    public function test_exam_sourced_assessment_participates_normally(): void
    {
        $this->assessment([
            'assessment_category' => 'uts',
            'assessment_sequence' => 1,
            'score' => 70.00,
            'source_type' => 'exam_result',
            'source_id' => 42,
        ]);
        $this->assessment([
            'assessment_category' => 'uas',
            'assessment_sequence' => 1,
            'score' => 80.00,
            'source_type' => 'exam_result',
            'source_id' => 43,
        ]);

        $result = $this->aggregate();

        $this->assertSame(70.0, $result['uts']['score']);
        $this->assertSame(1, $result['uts']['assessment_count']);
        $this->assertSame(80.0, $result['uas']['score']);
        $this->assertSame(1, $result['uas']['assessment_count']);
        $this->assertSame(0, Grade::count(), 'aggregation must not mutate any Grade row');
    }
}
