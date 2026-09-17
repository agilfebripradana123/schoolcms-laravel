<?php

namespace Tests\Feature\Students;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Grade;
use App\Models\Academic\GradeAssessment;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsGradeTestSchema;
use Tests\TestCase;

/**
 * Phase 2L-5 — Student final_score migrated to the assessment-derived weighted
 * final (GradeAggregationService::weightedFinalScore()).
 *
 * Hermetic suite; builds its own schema on the default (sqlite :memory:)
 * connection in setUp — never touches live MySQL. Uses the real student route
 * + EnsureStudentProfile middleware so identity scoping is exercised end to end.
 */
class StudentGradeWeightedFinalTest extends TestCase
{
    use BuildsGradeTestSchema;

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

        $this->class1 = SchoolClass::where('name', '10A')->firstOrFail();
        $this->class2 = SchoolClass::where('name', '10B')->firstOrFail();
        $this->subject1 = Subject::where('code', 'MTK')->firstOrFail();
        $this->subject2 = Subject::where('code', 'PAI')->firstOrFail();
        $this->ay = AcademicYear::where('name', '2026/2027')->firstOrFail();
        $this->semester1 = Semester::where('academic_year_id', $this->ay->id)->where('name', '1')->firstOrFail();
        $this->semester2 = Semester::where('academic_year_id', $this->ay->id)->where('name', '2')->firstOrFail();

        $siswaRole = Role::where('name', 'Siswa')->firstOrFail();

        $this->student1 = $this->createStudentUser($siswaRole, 'Student 1');
        $this->student2 = $this->createStudentUser($siswaRole, 'Student 2');

        Sanctum::actingAs($this->student1->user);
    }

    private function createStudentUser(Role $siswaRole, string $name): Student
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'.'.mt_rand(100000, 999999).'@spw.test',
            'username' => 'spw_'.mt_rand(100000, 999999),
            'password' => 'password',
            'is_active' => true,
            'role_id' => $siswaRole->id,
        ]);

        return Student::create([
            'user_id' => $user->id,
            'class_id' => $this->class1->id,
            'name' => $name,
            'gender' => 'L',
        ]);
    }

    private function gradeFor(Student $student, string $type, float $score, ?Semester $semester = null): Grade
    {
        $semester = $semester ?? $this->semester1;

        return Grade::create([
            'student_id' => $student->id,
            'subject_id' => $this->subject1->id,
            'class_id' => $this->class1->id,
            'type' => $type,
            'score' => $score,
            'semester' => $semester->name,
            'academic_year' => $this->ay->name,
            'semester_id' => $semester->id,
            'academic_year_id' => $this->ay->id,
        ]);
    }

    private function assessmentFor(Student $student, string $category, float $score, ?float $weight = null, ?Semester $semester = null, array $identity = []): GradeAssessment
    {
        $semester = $semester ?? $this->semester1;

        return GradeAssessment::create([
            'student_id' => $identity['student_id'] ?? $student->id,
            'subject_id' => $identity['subject_id'] ?? $this->subject1->id,
            'class_id' => $identity['class_id'] ?? $this->class1->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $identity['semester_id'] ?? $semester->id,
            'assessment_category' => $category,
            'assessment_sequence' => 1,
            'score' => $score,
            'max_score' => 100.00,
            'weight' => $weight,
        ]);
    }

    public function test_all_null_weights_equal_old_mean(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->gradeFor($this->student1, 'uts', 60.0);
        $this->gradeFor($this->student1, 'uas', 90.0);

        $this->assessmentFor($this->student1, 'tugas', 80.0);
        $this->assessmentFor($this->student1, 'uts', 60.0);
        $this->assessmentFor($this->student1, 'uas', 90.0);

        $response = $this->getJson('/api/student/grades')->assertOk();
        $row = $response->json('data.0');

        $this->assertEquals(76.67, $row['final_score'], 'assessment-derived equal-weight mean matches the old final_score');
    }

    public function test_weighted_categories_override_grade_mean(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->gradeFor($this->student1, 'uts', 60.0);
        $this->gradeFor($this->student1, 'uas', 90.0);

        $this->assessmentFor($this->student1, 'tugas', 80.0, 1.0);
        $this->assessmentFor($this->student1, 'uts', 60.0, 2.0);
        $this->assessmentFor($this->student1, 'uas', 90.0, 1.0);

        $response = $this->getJson('/api/student/grades')->assertOk();
        $row = $response->json('data.0');

        // (80*1 + 60*2 + 90*1) / 4 = 72.5  (grades-backed mean would be 76.67)
        $this->assertEquals(72.5, $row['final_score']);
    }

    public function test_partial_categories_average_present_only(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->gradeFor($this->student1, 'uts', 60.0);

        $this->assessmentFor($this->student1, 'tugas', 80.0);
        $this->assessmentFor($this->student1, 'uts', 60.0);

        $response = $this->getJson('/api/student/grades')->assertOk();
        $row = $response->json('data.0');

        $this->assertEquals(70.0, $row['final_score'], '(80 + 60) / 2 = 70');
        $this->assertNull($row['uas']);
    }

    public function test_no_assessments_yields_null_final(): void
    {
        $this->gradeFor($this->student1, 'tugas', 90.0);

        $response = $this->getJson('/api/student/grades')->assertOk();
        $row = $response->json('data.0');

        $this->assertEquals(90.0, $row['tugas'], 'grades-backed bucket value is exposed');
        $this->assertNull($row['uts']);
        $this->assertNull($row['uas']);
        $this->assertNull($row['final_score'], 'no assessments -> no derived final');
    }

    public function test_all_zero_weights_yield_null_final(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->gradeFor($this->student1, 'uts', 60.0);

        $this->assessmentFor($this->student1, 'tugas', 80.0, 0.0);
        $this->assessmentFor($this->student1, 'uts', 60.0, 0.0);

        $response = $this->getJson('/api/student/grades')->assertOk();
        $row = $response->json('data.0');

        $this->assertEquals(80.0, $row['tugas'], 'bucket still reported from grades.score');
        $this->assertEquals(60.0, $row['uts']);
        $this->assertNull($row['final_score'], 'no positive weight -> null final');
    }

    public function test_identity_and_period_isolation(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->gradeFor($this->student1, 'uts', 60.0);
        $this->gradeFor($this->student1, 'uas', 90.0);
        $this->assessmentFor($this->student1, 'tugas', 80.0);
        $this->assessmentFor($this->student1, 'uts', 60.0);
        $this->assessmentFor($this->student1, 'uas', 90.0);

        // Foreign identity rows with hostile weights/scores.
        $this->assessmentFor($this->student2, 'tugas', 5.0, 1000.0);
        $this->assessmentFor($this->student1, 'tugas', 5.0, 1000.0, null, ['subject_id' => $this->subject2->id]);
        $this->assessmentFor($this->student1, 'tugas', 5.0, 1000.0, null, ['class_id' => $this->class2->id]);
        $this->assessmentFor($this->student1, 'tugas', 5.0, 1000.0, $this->semester2);

        // Semester 2 grade row (same student) demonstrates no cross-period leak.
        $this->gradeFor($this->student1, 'tugas', 100.0, $this->semester2);

        // Filter to semester 1: only the semester-1 group is returned.
        $response = $this->getJson('/api/student/grades?semester_id='.$this->semester1->id)->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals(76.67, $data[0]['final_score'], 'foreign rows cannot affect the exact identity result');
    }

    public function test_stale_grade_score_diverges_from_fresh_final(): void
    {
        // Stale persisted bucket value (write-back not yet run after assessment change).
        $this->gradeFor($this->student1, 'tugas', 50.0);
        // Fresh assessment evidence.
        $this->assessmentFor($this->student1, 'tugas', 90.0);

        $response = $this->getJson('/api/student/grades')->assertOk();
        $row = $response->json('data.0');

        $this->assertEquals(50.0, $row['tugas'], 'bucket fields keep the persisted grades.score');
        $this->assertEquals(90.0, $row['final_score'], 'final_score exposes the fresh assessment-derived value');
    }

    public function test_response_contract_unchanged(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->assessmentFor($this->student1, 'tugas', 80.0);

        $response = $this->getJson('/api/student/grades')->assertOk();
        $row = $response->json('data.0');

        foreach (['id', 'subject_id', 'subject_name', 'class_name', 'semester', 'academic_year', 'tugas', 'uts', 'uas', 'final_score'] as $key) {
            $this->assertArrayHasKey($key, $row, "field {$key} must be present");
        }
        $this->assertTrue(is_int($row['final_score']) || is_float($row['final_score']) || is_null($row['final_score']));
    }

    public function test_summary_uses_weighted_finals(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->gradeFor($this->student1, 'uts', 60.0);
        $this->gradeFor($this->student1, 'uas', 90.0);

        $this->assessmentFor($this->student1, 'tugas', 80.0, 1.0);
        $this->assessmentFor($this->student1, 'uts', 60.0, 2.0);
        $this->assessmentFor($this->student1, 'uas', 90.0, 1.0);

        $summary = $this->getJson('/api/student/grades/summary')->assertOk();
        $data = $summary->json('data');

        $this->assertSame(1, $data['total_subjects']);
        $this->assertEquals(72.5, $data['average'], 'summary follows the canonical weighted final, not the grades mean');
        $this->assertEquals(72.5, $data['highest'], 'single subject -> highest equals its weighted final');
    }

    public function test_summary_ignores_stale_grade_score(): void
    {
        $this->gradeFor($this->student1, 'tugas', 50.0);
        $this->assessmentFor($this->student1, 'tugas', 90.0);

        $data = $this->getJson('/api/student/grades/summary')->assertOk()->json('data');

        $this->assertSame(1, $data['total_subjects']);
        $this->assertEquals(90.0, $data['average'], 'persisted stale bucket value must not shape the summary');
        $this->assertEquals(90.0, $data['highest']);
    }

    public function test_summary_all_null_weights_use_equal_share(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->gradeFor($this->student1, 'uts', 60.0);
        $this->gradeFor($this->student1, 'uas', 90.0);

        $this->assessmentFor($this->student1, 'tugas', 80.0);
        $this->assessmentFor($this->student1, 'uts', 60.0);
        $this->assessmentFor($this->student1, 'uas', 90.0);

        $data = $this->getJson('/api/student/grades/summary')->assertOk()->json('data');

        $this->assertSame(1, $data['total_subjects']);
        $this->assertEquals(76.67, $data['average']);
        $this->assertEquals(76.67, $data['highest']);
    }

    public function test_summary_uniform_weights_keep_equal_share(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->gradeFor($this->student1, 'uts', 60.0);
        $this->gradeFor($this->student1, 'uas', 90.0);

        $this->assessmentFor($this->student1, 'tugas', 80.0, 2.0);
        $this->assessmentFor($this->student1, 'uts', 60.0, 2.0);
        $this->assessmentFor($this->student1, 'uas', 90.0, 2.0);

        $data = $this->getJson('/api/student/grades/summary')->assertOk()->json('data');

        $this->assertEquals(76.67, $data['average']);
        $this->assertEquals(76.67, $data['highest']);
    }

    public function test_summary_zero_weight_category_is_excluded(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->gradeFor($this->student1, 'uts', 60.0);
        $this->gradeFor($this->student1, 'uas', 90.0);

        $this->assessmentFor($this->student1, 'tugas', 80.0, 1.0);
        $this->assessmentFor($this->student1, 'uts', 60.0, 0.0);
        $this->assessmentFor($this->student1, 'uas', 90.0, 1.0);

        $data = $this->getJson('/api/student/grades/summary')->assertOk()->json('data');

        $this->assertEquals(85.0, $data['average'], '(80 + 90) / 2, excluded zero-weight category');
        $this->assertEquals(85.0, $data['highest']);
    }

    public function test_summary_all_zero_weights_yield_no_eligible_subject(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->gradeFor($this->student1, 'uts', 60.0);

        $this->assessmentFor($this->student1, 'tugas', 80.0, 0.0);
        $this->assessmentFor($this->student1, 'uts', 60.0, 0.0);

        $data = $this->getJson('/api/student/grades/summary')->assertOk()->json('data');

        $this->assertSame(0, $data['total_subjects']);
        $this->assertSame(0, $data['average']);
        $this->assertSame(0, $data['highest']);
    }

    public function test_summary_missing_category_averages_present_only(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->gradeFor($this->student1, 'uts', 60.0);

        $this->assessmentFor($this->student1, 'tugas', 80.0);
        $this->assessmentFor($this->student1, 'uts', 60.0);

        $data = $this->getJson('/api/student/grades/summary')->assertOk()->json('data');

        $this->assertEquals(70.0, $data['average'], '(80 + 60) / 2 over present categories');
        $this->assertEquals(70.0, $data['highest']);
    }

    public function test_summary_no_assessments_is_empty(): void
    {
        $this->gradeFor($this->student1, 'tugas', 90.0);

        $data = $this->getJson('/api/student/grades/summary')->assertOk()->json('data');

        $this->assertSame(0, $data['total_subjects']);
        $this->assertSame(0, $data['average'], 'grades-backed buckets alone contribute nothing');
        $this->assertSame(0, $data['highest']);
    }

    public function test_summary_averages_across_subject_finals(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->gradeFor($this->student1, 'uts', 60.0);
        $this->gradeFor($this->student1, 'uas', 90.0);

        Grade::create([
            'student_id' => $this->student1->id,
            'subject_id' => $this->subject2->id,
            'class_id' => $this->class1->id,
            'type' => 'tugas',
            'score' => 70.0,
            'semester' => $this->semester1->name,
            'academic_year' => $this->ay->name,
            'semester_id' => $this->semester1->id,
            'academic_year_id' => $this->ay->id,
        ]);

        $this->assessmentFor($this->student1, 'tugas', 80.0, 1.0);
        $this->assessmentFor($this->student1, 'uts', 60.0, 2.0);
        $this->assessmentFor($this->student1, 'uas', 90.0, 1.0);
        $this->assessmentFor($this->student1, 'tugas', 70.0, 1.0, $this->semester1, ['subject_id' => $this->subject2->id]);
        $this->assessmentFor($this->student1, 'uts', 80.0, 1.0, $this->semester1, ['subject_id' => $this->subject2->id]);

        $data = $this->getJson('/api/student/grades/summary')->assertOk()->json('data');

        $this->assertSame(2, $data['total_subjects']);
        $this->assertEquals(73.75, $data['average'], '(72.5 + 75) / 2');
        $this->assertEquals(75.0, $data['highest']);
    }

    public function test_summary_split_class_identities_are_independent(): void
    {
        $this->gradeFor($this->student1, 'uts', 100.0);

        Grade::create([
            'student_id' => $this->student1->id,
            'subject_id' => $this->subject1->id,
            'class_id' => $this->class2->id,
            'type' => 'uts',
            'score' => 0.0,
            'semester' => $this->semester1->name,
            'academic_year' => $this->ay->name,
            'semester_id' => $this->semester1->id,
            'academic_year_id' => $this->ay->id,
        ]);

        $this->assessmentFor($this->student1, 'uts', 100.0);
        $this->assessmentFor($this->student1, 'uts', 0.0, null, $this->semester1, ['class_id' => $this->class2->id]);

        $data = $this->getJson('/api/student/grades/summary')->assertOk()->json('data');

        $this->assertSame(2, $data['total_subjects'], 'class identity is part of the canonical tuple');
        $this->assertEquals(50.0, $data['average'], '(100 + 0) / 2 across the two class identities');
        $this->assertEquals(100.0, $data['highest']);
    }

    public function test_summary_period_filter_isolates_identities(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0, $this->semester1);
        $this->gradeFor($this->student1, 'tugas', 100.0, $this->semester2);

        $this->assessmentFor($this->student1, 'tugas', 80.0, null, $this->semester1);
        $this->assessmentFor($this->student1, 'tugas', 100.0, null, $this->semester2);

        $first = $this->getJson('/api/student/grades/summary?semester_id='.$this->semester1->id)->assertOk()->json('data');
        $this->assertSame(1, $first['total_subjects']);
        $this->assertEquals(80.0, $first['average']);

        $second = $this->getJson('/api/student/grades/summary?semester_id='.$this->semester2->id)->assertOk()->json('data');
        $this->assertSame(1, $second['total_subjects']);
        $this->assertEquals(100.0, $second['average']);
    }

    public function test_summary_no_cross_period_mixing(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0, $this->semester1);
        $this->gradeFor($this->student1, 'uas', 90.0, $this->semester1);
        $this->gradeFor($this->student1, 'tugas', 100.0, $this->semester2);

        $this->assessmentFor($this->student1, 'tugas', 80.0, null, $this->semester1);
        $this->assessmentFor($this->student1, 'uas', 90.0, null, $this->semester1);
        $this->assessmentFor($this->student1, 'tugas', 100.0, null, $this->semester2);

        $unfiltered = $this->getJson('/api/student/grades/summary')->assertOk()->json('data');
        $this->assertSame(2, $unfiltered['total_subjects'], 'semester identities never merge');
        $this->assertEquals(92.5, $unfiltered['average'], '(85 + 100) / 2');
        $this->assertEquals(100.0, $unfiltered['highest']);
    }

    public function test_index_same_identity_stays_single_row(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->gradeFor($this->student1, 'uts', 60.0);
        $this->gradeFor($this->student1, 'uas', 90.0);

        $this->assessmentFor($this->student1, 'tugas', 80.0);
        $this->assessmentFor($this->student1, 'uts', 60.0);
        $this->assessmentFor($this->student1, 'uas', 90.0);

        $data = $this->getJson('/api/student/grades')->assertOk()->json('data');

        $this->assertCount(1, $data, 'one subject/class/period identity stays one display row');
        $this->assertEquals($this->class1->name, $data[0]['class_name']);
        $this->assertEquals(76.67, $data[0]['final_score']);
        $this->assertEquals(80.0, $data[0]['tugas']);
        $this->assertEquals(60.0, $data[0]['uts']);
        $this->assertEquals(90.0, $data[0]['uas']);
    }

    public function test_index_split_class_identities_render_separate_rows(): void
    {
        $this->gradeFor($this->student1, 'uts', 100.0);

        Grade::create([
            'student_id' => $this->student1->id,
            'subject_id' => $this->subject1->id,
            'class_id' => $this->class2->id,
            'type' => 'uts',
            'score' => 0.00,
            'semester' => $this->semester1->name,
            'academic_year' => $this->ay->name,
            'semester_id' => $this->semester1->id,
            'academic_year_id' => $this->ay->id,
        ]);

        $this->assessmentFor($this->student1, 'uts', 100.0);
        $this->assessmentFor($this->student1, 'uts', 0.0, null, $this->semester1, ['class_id' => $this->class2->id]);

        $data = $this->getJson('/api/student/grades')->assertOk()->json('data');

        $this->assertCount(2, $data, 'each class identity renders its own display row');

        $byClass = collect($data)->keyBy('class_name');

        $rowA = $byClass->get($this->class1->name);
        $rowB = $byClass->get($this->class2->name);

        $this->assertNotNull($rowA, 'class1 identity row present');
        $this->assertNotNull($rowB, 'class2 identity row present');

        $this->assertEquals(100.0, $rowA['uts'], 'bucket attached to its own class identity');
        $this->assertEquals(100.0, $rowA['final_score'], 'final_score belongs to class1 identity');

        $this->assertEquals(0.0, $rowB['uts']);
        $this->assertEquals(0.0, $rowB['final_score'], 'final_score belongs to class2 identity');
    }

    public function test_index_uses_single_bulk_assessment_query(): void
    {
        $this->gradeFor($this->student1, 'tugas', 80.0);
        $this->gradeFor($this->student1, 'uts', 60.0);
        $this->gradeFor($this->student1, 'uas', 90.0);

        Grade::create([
            'student_id' => $this->student1->id,
            'subject_id' => $this->subject2->id,
            'class_id' => $this->class1->id,
            'type' => 'tugas',
            'score' => 70.0,
            'semester' => $this->semester1->name,
            'academic_year' => $this->ay->name,
            'semester_id' => $this->semester1->id,
            'academic_year_id' => $this->ay->id,
        ]);

        $this->assessmentFor($this->student1, 'tugas', 80.0);
        $this->assessmentFor($this->student1, 'uts', 60.0);
        $this->assessmentFor($this->student1, 'uas', 90.0);
        $this->assessmentFor($this->student1, 'tugas', 70.0, null, $this->semester1, ['subject_id' => $this->subject2->id]);

        DB::enableQueryLog();

        $response = $this->getJson('/api/student/grades')->assertOk();

        $assessmentQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry) => str_contains($entry['query'], 'grade_assessments'))
            ->count();

        $this->assertSame(2, count($response->json('data')), 'two subject identities returned');
        $this->assertSame(1, $assessmentQueries, 'assessment evidence loaded in one bulk query, not per subject');
    }
}
