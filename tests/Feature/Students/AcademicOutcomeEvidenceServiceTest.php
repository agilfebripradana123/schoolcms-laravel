<?php

namespace Tests\Feature\Students;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassSubject;
use App\Models\Academic\GradeAssessment;
use App\Models\Academic\ReportCard;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Services\Students\AcademicOutcomeEvidenceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 2M-2F — AcademicOutcomeEvidenceService.
 *
 * Hermetic suite: builds its own minimal schema on sqlite :memory: and
 * exercises the read-only evidence service end to end.
 */
class AcademicOutcomeEvidenceServiceTest extends TestCase
{
    private int $studentId;

    private int $classId;

    private int $yearId;

    private int $semesterId;

    private AcademicOutcomeEvidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->seedFixture();
        $this->service = app(AcademicOutcomeEvidenceService::class);
    }

    private function buildSchema(): void
    {
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
        Schema::create('classes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->string('type')->default('wajib');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('class_subjects', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('teacher_id')->nullable();
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
        Schema::create('report_cards', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->unsignedBigInteger('semester_id');
            $t->text('teacher_notes')->nullable();
            $t->string('status')->default('draft');
            $t->dateTime('published_at')->nullable();
            $t->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $ay = AcademicYear::create(['name' => '2026/2027']);
        $semester = Semester::create(['academic_year_id' => $ay->id, 'name' => '2']);
        $class = SchoolClass::create(['name' => '7A']);

        $mntk = Subject::create(['code' => 'MTK', 'name' => 'Matematika', 'type' => 'wajib']);
        $indo = Subject::create(['code' => 'BIN', 'name' => 'Bahasa Indonesia', 'type' => 'wajib']);
        $ing = Subject::create(['code' => 'ING', 'name' => 'Bahasa Inggris', 'type' => 'pilihan']);
        $sjh = Subject::create(['code' => 'SJH', 'name' => 'Sejarah', 'type' => 'wajib']);

        $this->classId = $class->id;
        $this->yearId = $ay->id;
        $this->semesterId = $semester->id;

        foreach ([$mntk, $indo, $ing] as $subject) {
            ClassSubject::create(['class_id' => $class->id, 'subject_id' => $subject->id]);
        }
        // Sejarah: NOT in class_subjects — orphan grade source later.
        ClassSubject::create(['class_id' => $class->id, 'subject_id' => $sjh->id]);

        $this->studentId = 42;
    }

    private function assessment(int $subjectId, float $score, string $category = 'tugas', int $sequence = 1, ?int $studentId = null, ?int $classId = null): GradeAssessment
    {
        return GradeAssessment::create([
            'student_id' => $studentId ?? $this->studentId,
            'subject_id' => $subjectId,
            'class_id' => $classId ?? $this->classId,
            'academic_year_id' => $this->yearId,
            'semester_id' => $this->semesterId,
            'assessment_category' => $category,
            'assessment_sequence' => $sequence,
            'score' => $score,
            'max_score' => 100.00,
            'weight' => null,
        ]);
    }

    private function card(string $status = 'published', ?int $studentId = null, ?int $classId = null, ?int $yearId = null, ?int $semesterId = null): ReportCard
    {
        return ReportCard::create([
            'student_id' => $studentId ?? $this->studentId,
            'class_id' => $classId ?? $this->classId,
            'academic_year_id' => $yearId ?? $this->yearId,
            'semester_id' => $semesterId ?? $this->semesterId,
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    private function evidence(): array
    {
        return $this->service->evidence($this->studentId, $this->classId, $this->yearId, $this->semesterId);
    }

    public function test_returns_identity(): void
    {
        $this->card();

        $this->assertSame([
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $this->yearId,
            'semester_id' => $this->semesterId,
        ], $this->evidence()['identity']);
    }

    public function test_published_report_card_true(): void
    {
        $this->card('published');

        $this->assertTrue($this->evidence()['report_card']['published']);
    }

    public function test_draft_report_card_false(): void
    {
        $this->card('draft');

        $this->assertFalse($this->evidence()['report_card']['published']);
    }

    public function test_wrong_student_false(): void
    {
        $this->card('published', 999);

        $this->assertFalse($this->evidence()['report_card']['published']);
    }

    public function test_wrong_class_false(): void
    {
        $this->card('published', null, 999);

        $this->assertFalse($this->evidence()['report_card']['published']);
    }

    public function test_wrong_academic_year_false(): void
    {
        $this->card('published', null, null, 999);

        $this->assertFalse($this->evidence()['report_card']['published']);
    }

    public function test_wrong_semester_false(): void
    {
        $this->card('published', null, null, null, 999);

        $this->assertFalse($this->evidence()['report_card']['published']);
    }

    public function test_numeric_final_returned(): void
    {
        $mtk = Subject::where('code', 'MTK')->firstOrFail();
        $this->assessment($mtk->id, 80.00);

        $subject = collect($this->evidence()['subjects'])->firstWhere('subject_id', $mtk->id);

        $this->assertSame(80.0, $subject['final_score']);
        $this->assertTrue($subject['has_final']);
    }

    public function test_zero_final_is_valid(): void
    {
        $mtk = Subject::where('code', 'MTK')->firstOrFail();
        $this->assessment($mtk->id, 0.00);

        $subject = collect($this->evidence()['subjects'])->firstWhere('subject_id', $mtk->id);

        $this->assertSame(0.0, $subject['final_score'], 'zero is a valid score');
        $this->assertTrue($subject['has_final'], 'zero must count as a final');
    }

    public function test_missing_final_is_null(): void
    {
        $subject = collect($this->evidence()['subjects'])->firstWhere('subject_id', Subject::where('code', 'BIN')->firstOrFail()->id);

        $this->assertNull($subject['final_score']);
        $this->assertFalse($subject['has_final']);
    }

    public function test_expected_subject_without_grade_remains(): void
    {
        $data = $this->evidence();

        $this->assertContains('Bahasa Indonesia', collect($data['subjects'])->pluck('subject_name')->all());
    }

    public function test_expected_subject_without_assessment_has_zero_evidence(): void
    {
        $subject = collect($this->evidence()['subjects'])->firstWhere('subject_id', Subject::where('code', 'BIN')->firstOrFail()->id);

        $this->assertSame(0, $subject['assessment_count']);
        $this->assertSame([], $subject['categories_present']);
    }

    public function test_assessment_count_correct(): void
    {
        $mtk = Subject::where('code', 'MTK')->firstOrFail();
        $this->assessment($mtk->id, 80.00);
        $this->assessment($mtk->id, 90.00, 'uts', 1);
        $this->assessment($mtk->id, 70.00, 'tugas', 2);

        $subject = collect($this->evidence()['subjects'])->firstWhere('subject_id', $mtk->id);

        $this->assertSame(3, $subject['assessment_count']);
    }

    public function test_categories_present_distinct(): void
    {
        $mtk = Subject::where('code', 'MTK')->firstOrFail();
        $this->assessment($mtk->id, 80.00, 'tugas', 1);
        $this->assessment($mtk->id, 70.00, 'tugas', 2);
        $this->assessment($mtk->id, 90.00, 'uts', 1);

        $subject = collect($this->evidence()['subjects'])->firstWhere('subject_id', $mtk->id);

        $this->assertEqualsCanonicalizing(['tugas', 'uts'], $subject['categories_present']);
    }

    public function test_multiple_subjects_map_correctly(): void
    {
        $mtk = Subject::where('code', 'MTK')->firstOrFail();
        $indo = Subject::where('code', 'BIN')->firstOrFail();

        $this->assessment($mtk->id, 80.00);
        $this->assessment($indo->id, 60.00);

        $data = $this->evidence();

        $this->assertCount(4, $data['subjects']);
        $this->assertSame(80.0, collect($data['subjects'])->firstWhere('subject_id', $mtk->id)['final_score']);
        $this->assertSame(60.0, collect($data['subjects'])->firstWhere('subject_id', $indo->id)['final_score']);
    }

    public function test_subject_type_preserved(): void
    {
        $ing = Subject::where('code', 'ING')->firstOrFail();
        $subject = collect($this->evidence()['subjects'])->firstWhere('subject_id', $ing->id);

        $this->assertSame('pilihan', $subject['type']);
    }

    public function test_orphan_grade_does_not_create_extra_subject(): void
    {
        // Sejarah is a class_subject too, so use a fully-orphan subject.
        $unespected = Subject::create(['code' => 'ORPH', 'name' => 'Orphan Subject', 'type' => 'wajib']);

        $this->assessment($unespected->id, 100.00);
        $this->assessment(Subject::where('code', 'MTK')->firstOrFail()->id, 80.00);

        $data = $this->evidence();

        $this->assertCount(4, $data['subjects'], 'orphan assessments must not add subject entries');
        $this->assertNotContains('Orphan Subject', collect($data['subjects'])->pluck('subject_name')->all());
    }

    public function test_summary_counts_correct(): void
    {
        $mtk = Subject::where('code', 'MTK')->firstOrFail();
        $indo = Subject::where('code', 'BIN')->firstOrFail();
        $ing = Subject::where('code', 'ING')->firstOrFail();

        $this->assessment($mtk->id, 80.00);
        $this->assessment($mtk->id, 90.00, 'uts', 1);
        $this->assessment($indo->id, 0.00);
        $this->assessment($ing->id, 75.00);

        $data = $this->evidence();

        $this->assertSame(4, $data['summary']['expected_subject_count']);
        $this->assertSame(3, $data['summary']['final_score_count'], 'mtk + incl-zero indo + ing');
        $this->assertSame(1, $data['summary']['missing_final_count'], 'sejarah has no assessment');
        $this->assertSame(4, $data['summary']['assessment_count']);
    }

    public function test_summary_invariant(): void
    {
        $mtk = Subject::where('code', 'MTK')->firstOrFail();
        $this->assessment($mtk->id, 0.00);

        $summary = $this->evidence()['summary'];

        $this->assertSame(
            $summary['final_score_count'] + $summary['missing_final_count'],
            $summary['expected_subject_count']
        );
    }

    public function test_no_per_subject_query_growth(): void
    {
        // Small population fixture.
        $mtk = Subject::where('code', 'MTK')->firstOrFail();
        $this->assessment($mtk->id, 80.00);
        DB::enableQueryLog();
        $this->evidence();
        $small = $this->assessmentQueryCount();
        DB::flushQueryLog();

        // Larger population fixture (fresh class/student).
        $class = SchoolClass::create(['name' => '7B']);
        $subjects = [];
        foreach (['MTK', 'BIN', 'ING', 'SJH'] as $code) {
            $subject = Subject::where('code', $code)->firstOrFail();
            ClassSubject::create(['class_id' => $class->id, 'subject_id' => $subject->id]);
            $subjects[] = $subject;
        }

        foreach ($subjects as $i => $subject) {
            $this->assessment($subject->id, 60.0 + $i, 'tugas', 1, 43, $class->id);
        }

        DB::enableQueryLog();
        $this->service->evidence(43, $class->id, $this->yearId, $this->semesterId);
        $large = $this->assessmentQueryCount();
        DB::flushQueryLog();

        $this->assertLessThanOrEqual(2, $small, 'assessment reads are constant');
        $this->assertSame($small, $large, 'assessment query count must not grow with subject count');
    }

    private function assessmentQueryCount(): int
    {
        return collect(DB::getQueryLog())
            ->filter(function (array $entry) {
                $sql = strtolower(ltrim((string) $entry['query']));

                return str_starts_with($sql, 'select') && str_contains($sql, 'grade_assessments');
            })
            ->count();
    }
}
