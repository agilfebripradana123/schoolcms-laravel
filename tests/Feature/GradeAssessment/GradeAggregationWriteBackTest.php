<?php

namespace Tests\Feature\GradeAssessment;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Grade;
use App\Models\Academic\GradeAssessment;
use App\Models\Academic\ReportCard;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Students\Student;
use App\Services\Academic\GradeAggregationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsGradeTestSchema;
use Tests\TestCase;

/**
 * Phase 2L-3 Stage 3C — grades.score write-back.
 *
 * Hermetic suite; builds its own schema on the default (sqlite :memory:)
 * connection in setUp — never touches live MySQL.
 *
 * Covers the atomic lock contract: all present buckets are guarded BEFORE any
 * write, so a single finalized/published-card bucket rejects the whole
 * operation with zero partial updates.
 */
class GradeAggregationWriteBackTest extends TestCase
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

    private function assessment(string $category, float $score, float $maxScore = 100.00, int $sequence = 1, array $identity = [], array $extra = []): GradeAssessment
    {
        return GradeAssessment::create(array_merge([
            'student_id' => $identity['student_id'] ?? $this->student1->id,
            'subject_id' => $identity['subject_id'] ?? $this->subject1->id,
            'class_id' => $identity['class_id'] ?? $this->class1->id,
            'academic_year_id' => $identity['academic_year_id'] ?? $this->ay->id,
            'semester_id' => $identity['semester_id'] ?? $this->semester1->id,
            'assessment_category' => $category,
            'assessment_sequence' => $sequence,
            'score' => $score,
            'max_score' => $maxScore,
        ], $extra));
    }

    private function grade(string $type, float $score, array $overrides = []): Grade
    {
        $grade = Grade::create([
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

        // is_final/finalized fields are not mass-assignable; set them directly.
        foreach ($overrides as $key => $value) {
            $grade->setAttribute($key, $value);
        }
        $grade->save();

        return $grade;
    }

    /** @return array<string, array{score: float, assessment_count: int}> */
    private function sync(): array
    {
        return $this->service->synchronizeScore(
            $this->student1->id,
            $this->subject1->id,
            $this->class1->id,
            $this->ay->id,
            $this->semester1->id,
        );
    }

    private function assertSyncRejects(): HttpResponseException
    {
        try {
            $this->sync();
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());

            return $e;
        }

        $this->fail('Expected HttpResponseException (422) was not thrown.');
    }

    public function test_present_buckets_update_grade_score(): void
    {
        $this->grade('tugas', 70.00);
        $this->grade('uts', 80.00);
        $this->grade('uas', 90.00);

        $this->assessment('tugas', 80.00);
        $this->assessment('uts', 60.00);
        $this->assessment('uas', 90.00);

        $result = $this->sync();

        $this->assertSame(80.0, $result['tugas']['score']);
        $this->assertSame('80.00', (string) Grade::where('type', 'tugas')->first()->score);
        $this->assertSame('60.00', (string) Grade::where('type', 'uts')->first()->score);
        $this->assertSame('90.00', (string) Grade::where('type', 'uas')->first()->score);
    }

    public function test_two_decimal_persistence(): void
    {
        $this->grade('tugas', 0.00);
        $this->assessment('tugas', 70.00, 100.00, 1);
        $this->assessment('tugas', 80.00, 100.00, 2);
        $this->assessment('tugas', 81.00, 100.00, 3);

        $this->sync();

        $this->assertSame('77.00', (string) Grade::where('type', 'tugas')->first()->score);
    }

    public function test_missing_buckets_keep_previous_values(): void
    {
        $this->grade('tugas', 40.00);
        $this->grade('uts', 80.00);
        $this->grade('uas', 90.00);

        $this->assessment('tugas', 65.00);

        $this->sync();

        $this->assertSame('65.00', (string) Grade::where('type', 'tugas')->first()->score, 'tugas has assessments -> updated');
        $this->assertSame('80.00', (string) Grade::where('type', 'uts')->first()->score, 'no uts assessments -> untouched');
        $this->assertSame('90.00', (string) Grade::where('type', 'uas')->first()->score, 'no uas assessments -> untouched');
    }

    public function test_missing_grade_row_is_skipped_without_creation(): void
    {
        $this->assessment('tugas', 75.00);

        $this->assertSame(0, Grade::count());

        $result = $this->sync();

        $this->assertSame(75.0, $result['tugas']['score']);
        $this->assertSame(0, Grade::count(), 'a missing Grade row is never created');
    }

    public function test_finalized_grade_rejects_whole_sync(): void
    {
        $this->grade('tugas', 10.00);
        $this->grade('uts', 20.00, ['is_final' => true]);

        $this->assessment('tugas', 90.00);
        $this->assessment('uts', 90.00);

        $this->assertSyncRejects();

        $this->assertSame('10.00', (string) Grade::where('type', 'tugas')->first()->score, 'unlocked bucket writes zero partial updates');
        $this->assertSame('20.00', (string) Grade::where('type', 'uts')->first()->score, 'finalized bucket untouched');
    }

    public function test_published_report_card_rejects_whole_sync(): void
    {
        $this->grade('tugas', 30.00);
        $this->grade('uas', 30.00);

        $this->assessment('tugas', 95.00);
        $this->assessment('uas', 95.00);

        ReportCard::create([
            'student_id' => $this->student1->id,
            'class_id' => $this->class1->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $this->semester1->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->assertSyncRejects();

        $this->assertSame('30.00', (string) Grade::where('type', 'tugas')->first()->score);
        $this->assertSame('30.00', (string) Grade::where('type', 'uas')->first()->score);
    }

    public function test_locks_are_checked_before_any_write(): void
    {
        // First bucket (tugas) in the iteration order is the one that would be
        // written FIRST — lock it, and prove the guard rejects before any row
        // changes (the uts row, although early in iteration, also stays intact).
        $this->grade('tugas', 10.00, ['is_final' => true]);
        $this->grade('uts', 20.00);

        $this->assessment('tugas', 88.00);
        $this->assessment('uts', 88.00);

        $this->assertSyncRejects();

        $this->assertSame('10.00', (string) Grade::where('type', 'tugas')->first()->score);
        $this->assertSame('20.00', (string) Grade::where('type', 'uts')->first()->score, 'zero partial writes');
    }

    public function test_unfinalized_grade_synchronizes(): void
    {
        $tugas = $this->grade('tugas', 50.00, ['is_final' => true]);
        $this->assessment('tugas', 85.00);

        $this->assertSyncRejects();

        $tugas->forceFill(['is_final' => false, 'finalized_at' => null, 'finalized_by' => null])->save();

        $this->sync();

        $tugas->refresh();
        $this->assertSame('85.00', (string) $tugas->score);
        $this->assertFalse($tugas->is_final);
    }

    public function test_idempotency(): void
    {
        $this->grade('tugas', 1.00);
        $this->grade('uts', 2.00);

        $this->assessment('tugas', 82.00);
        $this->assessment('uts', 64.00);

        $this->sync();
        $this->sync();

        $this->assertSame('82.00', (string) Grade::where('type', 'tugas')->first()->score);
        $this->assertSame('64.00', (string) Grade::where('type', 'uts')->first()->score);
    }

    public function test_identity_isolation(): void
    {
        $this->grade('tugas', 10.00);
        $this->assessment('tugas', 90.00);

        // Same values for a foreign identity must not leak into the sync target.
        $this->assessment('tugas', 5.00, 100.00, 1, ['student_id' => $this->student2->id]);
        $this->assessment('tugas', 5.00, 100.00, 1, ['subject_id' => $this->subject2->id]);
        $this->assessment('tugas', 5.00, 100.00, 1, ['class_id' => $this->class2->id]);
        $this->assessment('tugas', 5.00, 100.00, 1, ['semester_id' => $this->semester2->id]);
        $otherYear = AcademicYear::where('name', '2025/2026')->firstOrFail();
        $otherYearSemester = Semester::where('academic_year_id', $otherYear->id)->where('name', '1')->firstOrFail();
        $this->assessment('tugas', 5.00, 100.00, 1, ['academic_year_id' => $otherYear->id, 'semester_id' => $otherYearSemester->id]);

        $this->sync();

        $this->assertSame('90.00', (string) Grade::where('type', 'tugas')->first()->score, 'only the exact identity is synchronized');
        $this->assertSame(1, Grade::count());
    }

    public function test_exam_sourced_assessment_synchronizes(): void
    {
        $this->grade('uts', 55.00);
        $this->grade('uas', 55.00);

        $this->assessment('uts', 70.00, 100.00, 1, [], ['source_type' => 'exam_result', 'source_id' => 42]);
        $this->assessment('uas', 80.00, 100.00, 1, [], ['source_type' => 'exam_result', 'source_id' => 43]);

        $this->sync();

        $this->assertSame('70.00', (string) Grade::where('type', 'uts')->first()->score);
        $this->assertSame('80.00', (string) Grade::where('type', 'uas')->first()->score);
    }

    public function test_exam_convergence(): void
    {
        // Mirror what ExamGradeIntegrationService::sync writes: Grade.score =
        // result percentage AND the exam assessment (the only bucket member).
        $this->grade('uts', 75.00);
        $this->assessment('uts', 75.00, 100.00, 1, [], ['source_type' => 'exam_result', 'source_id' => 7]);

        $this->sync();

        $this->assertSame('75.00', (string) Grade::where('type', 'uts')->first()->score, 'single exam assessment converges to the same score');
        $this->assertSame(1, Grade::count());
    }

    public function test_stale_legacy_values_unaffected(): void
    {
        $this->grade('tugas', 40.00);
        $this->grade('uas', 90.00);

        // Only tugas is assessed; uas keeps its stale legacy value.
        $this->assessment('tugas', 68.00);

        $this->sync();

        $this->assertSame('68.00', (string) Grade::where('type', 'tugas')->first()->score);
        $this->assertSame('90.00', (string) Grade::where('type', 'uas')->first()->score);
    }

    public function test_assessments_never_mutated_by_sync(): void
    {
        $this->grade('tugas', 0.00);
        $this->assessment('tugas', 72.00);
        $this->assessment('uts', 61.00);

        $before = GradeAssessment::query()->orderBy('id')->get()->toArray();

        $this->sync();

        $after = GradeAssessment::query()->orderBy('id')->get()->toArray();
        $this->assertSame($before, $after, 'grade_assessments are a read-only source for write-back');
    }
}
