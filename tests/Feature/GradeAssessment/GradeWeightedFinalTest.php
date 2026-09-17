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
 * Phase 2L-4 — category-level weighted final score (derived, read-only).
 *
 * Hermetic suite; builds its own schema on the default (sqlite :memory:)
 * connection in setUp — never touches live MySQL.
 *
 * Contract: NULL ≡ equal share (1.0); uniform effective weights are kept;
 * conflicting weights fall back to equal share; weight 0 excludes the category
 * from the weighted final numerator/denominator; result rounded to 2 decimals;
 * Grade write-back and aggregate() remain untouched.
 */
class GradeWeightedFinalTest extends TestCase
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

    private function assessment(string $category, float $score, int $sequence = 1, ?float $weight = null, array $identity = []): GradeAssessment
    {
        return GradeAssessment::create([
            'student_id' => $identity['student_id'] ?? $this->student1->id,
            'subject_id' => $identity['subject_id'] ?? $this->subject1->id,
            'class_id' => $identity['class_id'] ?? $this->class1->id,
            'academic_year_id' => $identity['academic_year_id'] ?? $this->ay->id,
            'semester_id' => $identity['semester_id'] ?? $this->semester1->id,
            'assessment_category' => $category,
            'assessment_sequence' => $sequence,
            'score' => $score,
            'max_score' => 100.00,
            'weight' => $weight,
        ]);
    }

    /** @return array{weighted_final_score: float|null, categories: array} */
    private function weightedFinal(): array
    {
        return $this->service->weightedFinalScore(
            $this->student1->id,
            $this->subject1->id,
            $this->class1->id,
            $this->ay->id,
            $this->semester1->id,
        );
    }

    public function test_all_null_weights_equal_existing_final(): void
    {
        $this->assessment('tugas', 80.00, 1);
        $this->assessment('tugas', 90.00, 2);
        $this->assessment('uts', 60.00);
        $this->assessment('uas', 90.00);

        $result = $this->weightedFinal();

        // Equal mean of present buckets: (85 + 60 + 90) / 3 = 78.33
        $this->assertEquals(78.33, $result['weighted_final_score']);
        $this->assertSame(1.0, $result['categories']['tugas']['weight']);
        $this->assertSame(1.0, $result['categories']['uts']['weight']);
        $this->assertSame(1.0, $result['categories']['uas']['weight']);
        $this->assertSame(85.0, $result['categories']['tugas']['score']);
    }

    public function test_uniform_category_weights_are_proportional(): void
    {
        $this->assessment('tugas', 80.00, 1, 1.0);
        $this->assessment('uts', 60.00, 1, 2.0);
        $this->assessment('uas', 90.00, 1, 1.0);

        $result = $this->weightedFinal();

        // (80*1 + 60*2 + 90*1) / 4 = 72.5
        $this->assertSame(72.5, $result['weighted_final_score']);
        $this->assertSame(2.0, $result['categories']['uts']['weight']);
        $this->assertSame(1.0, $result['categories']['tugas']['weight']);
    }

    public function test_zero_weight_excludes_category(): void
    {
        $this->assessment('tugas', 80.00, 1, null);
        $this->assessment('uts', 60.00, 1, 0.0);
        $this->assessment('uas', 90.00, 1, null);

        $result = $this->weightedFinal();

        // uts excluded: (80*1 + 90*1) / 2 = 85
        $this->assertSame(85.0, $result['weighted_final_score']);
        $this->assertArrayHasKey('uts', $result['categories'], 'zero-weight category is still reported');
        $this->assertSame(0.0, $result['categories']['uts']['weight']);
        $this->assertSame(60.0, $result['categories']['uts']['score'], 'bucket score still computed');
        $this->assertSame(1, $result['categories']['uts']['assessment_count']);
    }

    public function test_weight_over_100_is_relative(): void
    {
        $this->assessment('tugas', 80.00, 1, 200.0);
        $this->assessment('uts', 60.00, 1, 1.0);

        $result = $this->weightedFinal();

        // (80*200 + 60*1) / 201 = 79.90 (rounded)
        $this->assertEquals(79.9, $result['weighted_final_score']);
        $this->assertSame(200.0, $result['categories']['tugas']['weight']);
    }

    public function test_conflicting_weights_fall_back_to_equal_share(): void
    {
        $this->assessment('tugas', 80.00, 1, 1.0);
        $this->assessment('tugas', 90.00, 2, 2.0);
        $this->assessment('tugas', 70.00, 3, 1.0);

        $result = $this->weightedFinal();

        // Non-uniform effective weights [1,2,1] -> fallback weight 1.
        $this->assertSame(1.0, $result['categories']['tugas']['weight']);
        // Bucket mean stays unweighted arithmetic mean: (80+90+70)/3 = 80.
        $this->assertSame(80.0, $result['categories']['tugas']['score']);
        $this->assertSame(80.0, $result['weighted_final_score']);
    }

    public function test_mixed_null_and_non_null_conflict_falls_back(): void
    {
        // NULL->1, so effective weights [1,2,2] -> non-uniform -> fallback 1.
        $this->assessment('tugas', 80.00, 1, null);
        $this->assessment('tugas', 90.00, 2, 2.0);
        $this->assessment('tugas', 90.00, 3, 2.0);

        $result = $this->weightedFinal();

        $this->assertSame(1.0, $result['categories']['tugas']['weight']);
        $this->assertEquals(86.67, $result['categories']['tugas']['score']);
        $this->assertEquals(86.67, $result['weighted_final_score']);
    }

    public function test_mixed_null_also_falls_back(): void
    {
        // effective [1,1,2] -> non-uniform -> fallback 1.
        $this->assessment('tugas', 80.00, 1, null);
        $this->assessment('tugas', 90.00, 2, null);
        $this->assessment('tugas', 70.00, 3, 2.0);

        $result = $this->weightedFinal();

        $this->assertSame(1.0, $result['categories']['tugas']['weight']);
        $this->assertEquals(80.0, $result['weighted_final_score']);
    }

    public function test_all_null_single_bucket_keeps_equal_share(): void
    {
        $this->assessment('tugas', 75.00);
        $this->assessment('tugas', 85.00, 2);

        $result = $this->weightedFinal();

        $this->assertSame(1.0, $result['categories']['tugas']['weight']);
        $this->assertSame(80.0, $result['weighted_final_score']);
    }

    public function test_no_eligible_weighted_category_returns_null(): void
    {
        $this->assessment('tugas', 80.00, 1, 0.0);
        $this->assessment('uts', 60.00, 1, 0.0);

        $result = $this->weightedFinal();

        $this->assertNull($result['weighted_final_score']);
        $this->assertArrayHasKey('tugas', $result['categories'], 'bucket still reported for coverage');
        $this->assertArrayHasKey('uts', $result['categories']);
    }

    public function test_missing_bucket_does_not_participate(): void
    {
        $this->assessment('tugas', 88.00);

        $result = $this->weightedFinal();

        $this->assertArrayHasKey('tugas', $result['categories']);
        $this->assertArrayNotHasKey('uts', $result['categories']);
        $this->assertArrayNotHasKey('uas', $result['categories']);
        $this->assertSame(88.0, $result['weighted_final_score']);
    }

    public function test_identity_isolation(): void
    {
        $this->assessment('tugas', 80.00, 1, 1.0);
        $this->assessment('uts', 60.00, 1, 2.0);

        // Foreign identity rows with hostile weights.
        $this->assessment('tugas', 5.00, 1, 1000.0, ['student_id' => $this->student2->id]);
        $this->assessment('tugas', 5.00, 1, 1000.0, ['subject_id' => $this->subject2->id]);
        $this->assessment('tugas', 5.00, 1, 1000.0, ['class_id' => $this->class2->id]);
        $this->assessment('tugas', 5.00, 1, 1000.0, ['semester_id' => $this->semester2->id]);

        $result = $this->weightedFinal();

        $this->assertSame(1.0, $result['categories']['tugas']['weight']);
        $this->assertSame(2.0, $result['categories']['uts']['weight']);
        $this->assertEquals(66.67, $result['weighted_final_score']);
    }

    public function test_deterministic(): void
    {
        $this->assessment('tugas', 80.00, 1, 1.0);
        $this->assessment('uts', 60.00, 1, 2.0);
        $this->assessment('uas', 90.00, 1, 1.0);

        $first = $this->weightedFinal();
        $second = $this->weightedFinal();

        $this->assertSame($first, $second);
    }

    public function test_calculation_never_mutates_anything(): void
    {
        $this->gradeRow('tugas', 10.00);
        $this->assessment('tugas', 80.00, 1, 2.0);

        $gradesBefore = Grade::query()->orderBy('id')->get()->toArray();
        $assessmentsBefore = GradeAssessment::query()->orderBy('id')->get()->toArray();

        $this->weightedFinal();

        $this->assertSame($gradesBefore, Grade::query()->orderBy('id')->get()->toArray(), 'Grade rows unchanged');
        $this->assertSame($assessmentsBefore, GradeAssessment::query()->orderBy('id')->get()->toArray(), 'grade_assessments unchanged');
    }

    public function test_aggregate_regression_unchanged(): void
    {
        $this->assessment('tugas', 80.00, 1, 5.0);
        $this->assessment('tugas', 90.00, 2, 1.0);
        $this->assessment('uts', 60.00, 1, null);

        $this->weightedFinal();

        $aggregate = $this->service->aggregate(
            $this->student1->id,
            $this->subject1->id,
            $this->class1->id,
            $this->ay->id,
            $this->semester1->id,
        );

        $this->assertSame([
            'tugas' => ['score' => 85.0, 'assessment_count' => 2],
            'uts' => ['score' => 60.0, 'assessment_count' => 1],
        ], $aggregate, 'aggregate() output shape/values unchanged by weighted calculation');
    }

    public function test_negative_weight_is_invalid(): void
    {
        GradeAssessment::where('id', $this->assessment('tugas', 80.00, 1, -5.0)->id)->update(['weight' => -5.0]);

        $this->expectException(GradeAggregationException::class);

        $this->weightedFinal();
    }

    private function gradeRow(string $type, float $score): Grade
    {
        return Grade::create([
            'student_id' => $this->student1->id,
            'subject_id' => $this->subject1->id,
            'class_id' => $this->class1->id,
            'type' => $type,
            'score' => $score,
            'semester' => '1',
            'academic_year' => '2026/2027',
            'semester_id' => $this->semester1->id,
            'academic_year_id' => $this->ay->id,
        ]);
    }
}
