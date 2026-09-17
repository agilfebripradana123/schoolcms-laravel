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
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsGradeTestSchema;
use Tests\TestCase;

/**
 * Phase 2L-3 Stage 3D — explicit admin single-identity aggregation trigger.
 *
 * Hermetic suite; builds its own schema on the default (sqlite :memory:)
 * connection in setUp — never touches live MySQL.
 *
 * Covers request validation, authorization, endpoint behavior, and delegation
 * to GradeAggregationService::synchronizeScore().
 */
class GradeAggregationTriggerTest extends TestCase
{
    use BuildsGradeTestSchema;

    private User $admin;

    private User $administrator;

    private User $guru;

    private User $siswa;

    private Student $student1;

    private Student $student2;

    private Subject $subject1;

    private Subject $subject2;

    private SchoolClass $class1;

    private SchoolClass $class2;

    private AcademicYear $ay;

    private AcademicYear $otherYear;

    private Semester $semester1;

    private Semester $otherYearSemester;

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

        $this->admin = User::where('email', 'admin.base@test.local')->firstOrFail();
        $this->administrator = User::where('email', 'administrator.base@test.local')->firstOrFail();
        $this->guru = User::where('email', 'guru.base@test.local')->firstOrFail();
        $this->siswa = User::where('email', 'siswa.base@test.local')->firstOrFail();

        $this->class1 = SchoolClass::where('name', '10A')->firstOrFail();
        $this->class2 = SchoolClass::where('name', '10B')->firstOrFail();
        $this->subject1 = Subject::where('code', 'MTK')->firstOrFail();
        $this->subject2 = Subject::where('code', 'PAI')->firstOrFail();
        $this->ay = AcademicYear::where('name', '2026/2027')->firstOrFail();
        $this->otherYear = AcademicYear::where('name', '2025/2026')->firstOrFail();
        $this->semester1 = Semester::where('academic_year_id', $this->ay->id)->where('name', '1')->firstOrFail();
        $this->otherYearSemester = Semester::where('academic_year_id', $this->otherYear->id)->where('name', '1')->firstOrFail();

        $this->student1 = Student::create(['name' => 'Student 1', 'class_id' => $this->class1->id]);
        $this->student2 = Student::create(['name' => 'Student 2', 'class_id' => $this->class1->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'student_id' => $this->student1->id,
            'subject_id' => $this->subject1->id,
            'class_id' => $this->class1->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $this->semester1->id,
        ], $overrides);
    }

    private function postAggregate(array $body = [])
    {
        return $this->postJson('/api/grade-assessments/aggregate', $body !== [] ? $body : $this->payload());
    }

    private function assessment(string $category, float $score, int $sequence = 1, array $identity = [], array $extra = []): GradeAssessment
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
            'max_score' => 100.00,
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

        foreach ($overrides as $key => $value) {
            $grade->setAttribute($key, $value);
        }
        $grade->save();

        return $grade;
    }

    // ─── A. AUTHENTICATION / AUTHORIZATION ─────────────────────────

    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/grade-assessments/aggregate', $this->payload())->assertStatus(401);
    }

    public function test_guru_is_forbidden(): void
    {
        Sanctum::actingAs($this->guru);
        $this->postAggregate()->assertStatus(403);
    }

    public function test_student_is_forbidden(): void
    {
        Sanctum::actingAs($this->siswa);
        $this->postAggregate()->assertStatus(403);
    }

    public function test_admin_can_aggregate(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postAggregate()->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_administrator_can_aggregate(): void
    {
        Sanctum::actingAs($this->administrator);
        $this->postAggregate()->assertStatus(200)->assertJsonPath('success', true);
    }

    // ─── B. SUCCESSFUL AGGREGATION ─────────────────────────────────

    public function test_valid_identity_aggregates(): void
    {
        $this->grade('tugas', 10.00);
        $this->assessment('tugas', 80.00);

        Sanctum::actingAs($this->admin);
        $response = $this->postAggregate();

        $response->assertStatus(200);
        $this->assertSame(80.0, (float) $response->json('data.derived.tugas.score'));
        $this->assertSame('80.00', (string) Grade::where('type', 'tugas')->first()->score);
    }

    public function test_multiple_categories_derive_correct_buckets(): void
    {
        $this->grade('tugas', 0.00);
        $this->grade('uts', 0.00);

        $this->assessment('tugas', 80.00, 1);
        $this->assessment('PH', 90.00, 1);
        $this->assessment('PTS', 70.00, 1);
        $this->assessment('uts', 60.00, 1);

        Sanctum::actingAs($this->admin);
        $response = $this->postAggregate();

        $response->assertStatus(200);
        $this->assertSame(80.0, (float) $response->json('data.derived.tugas.score'), 'tugas + PH + PTS fold into the tugas bucket');
        $this->assertSame(3, (int) $response->json('data.derived.tugas.assessment_count'));
        $this->assertSame(60.0, (float) $response->json('data.derived.uts.score'));
        $this->assertArrayNotHasKey('uas', $response->json('data.derived'));
    }

    public function test_derived_scores_are_persisted(): void
    {
        $this->grade('tugas', 0.00);
        $this->assessment('tugas', 88.00);

        Sanctum::actingAs($this->admin);
        $this->postAggregate()->assertStatus(200);

        $this->assertSame('88.00', (string) Grade::where('type', 'tugas')->first()->score);
    }

    public function test_response_contains_derived_bucket_data(): void
    {
        $this->grade('tugas', 0.00);
        $this->assessment('tugas', 75.00);
        $this->assessment('tugas', 85.00, 2);

        Sanctum::actingAs($this->admin);
        $response = $this->postAggregate();

        $response->assertStatus(200);
        $this->assertSame(80.0, (float) $response->json('data.derived.tugas.score'));
        $this->assertSame(2, (int) $response->json('data.derived.tugas.assessment_count'));
    }

    public function test_response_contains_updated_count(): void
    {
        $this->grade('tugas', 0.00);
        $this->grade('uts', 0.00);
        $this->assessment('tugas', 80.00);
        $this->assessment('uts', 60.00);

        Sanctum::actingAs($this->admin);
        $response = $this->postAggregate();

        $response->assertStatus(200);
        $this->assertSame(2, (int) $response->json('data.grades_updated'));
    }

    public function test_response_contains_skipped_count(): void
    {
        $this->grade('tugas', 0.00);
        $this->assessment('tugas', 80.00);
        $this->assessment('uts', 60.00);

        Sanctum::actingAs($this->admin);
        $response = $this->postAggregate();

        $response->assertStatus(200);
        $this->assertSame(1, (int) $response->json('data.grades_updated'));
        $this->assertSame(1, (int) $response->json('data.grades_skipped'), 'uts bucket has no Grade row -> skipped');
    }

    // ─── C. VALIDATION ─────────────────────────────────────────────

    public function test_missing_student_id_returns_422(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postAggregate(array_merge($this->payload(), ['student_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);
    }

    public function test_missing_subject_id_returns_422(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postAggregate(array_merge($this->payload(), ['subject_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject_id']);
    }

    public function test_missing_class_id_returns_422(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postAggregate(array_merge($this->payload(), ['class_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['class_id']);
    }

    public function test_missing_academic_year_id_returns_422(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postAggregate(array_merge($this->payload(), ['academic_year_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['academic_year_id']);
    }

    public function test_missing_semester_id_returns_422(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postAggregate(array_merge($this->payload(), ['semester_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['semester_id']);
    }

    public function test_unknown_identity_returns_422(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postAggregate($this->payload(['student_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);
    }

    public function test_student_not_in_class_returns_422(): void
    {
        $foreign = Student::create(['name' => 'Foreign', 'class_id' => $this->class2->id]);

        Sanctum::actingAs($this->admin);
        $this->postAggregate($this->payload(['student_id' => $foreign->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);
    }

    public function test_subject_not_assigned_to_class_returns_422(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postAggregate($this->payload(['subject_id' => $this->subject2->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject_id']);
    }

    public function test_invalid_year_semester_pairing_returns_422(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postAggregate($this->payload(['semester_id' => $this->otherYearSemester->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['semester_id']);
    }

    // ─── D. EMPTY / MISSING BEHAVIOR ───────────────────────────────

    public function test_no_assessments_returns_empty_derived(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->postAggregate();

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.derived'));
        $this->assertSame(0, (int) $response->json('data.grades_updated'));
        $this->assertSame(0, (int) $response->json('data.grades_skipped'));
        $this->assertSame(0, Grade::count(), 'no Grade row is created');
    }

    public function test_missing_grade_row_is_skipped(): void
    {
        $this->assertSame(0, Grade::count());
        $this->assessment('tugas', 70.00);

        Sanctum::actingAs($this->admin);
        $response = $this->postAggregate();

        $response->assertStatus(200);
        $this->assertSame(0, (int) $response->json('data.grades_updated'));
        $this->assertSame(1, (int) $response->json('data.grades_skipped'));
        $this->assertSame(0, Grade::count(), 'missing Grade row is never created');
    }

    public function test_only_present_bucket_is_updated(): void
    {
        $this->grade('tugas', 10.00);
        $this->grade('uts', 90.00);
        $this->grade('uas', 95.00);
        $this->assessment('tugas', 65.00);

        Sanctum::actingAs($this->admin);
        $this->postAggregate()->assertStatus(200);

        $this->assertSame('65.00', (string) Grade::where('type', 'tugas')->first()->score);
        $this->assertSame('90.00', (string) Grade::where('type', 'uts')->first()->score, 'uts untouched');
        $this->assertSame('95.00', (string) Grade::where('type', 'uas')->first()->score, 'uas untouched');
    }

    // ─── E. LOCK BEHAVIOR ──────────────────────────────────────────

    public function test_finalized_target_returns_422(): void
    {
        $this->grade('tugas', 50.00, ['is_final' => true]);
        $this->assessment('tugas', 90.00);

        Sanctum::actingAs($this->admin);
        $response = $this->postAggregate();

        $response->assertStatus(422);
        $this->assertSame('50.00', (string) Grade::where('type', 'tugas')->first()->score, 'no score change on lock');
    }

    public function test_published_report_card_target_returns_422(): void
    {
        $this->grade('tugas', 30.00);
        $this->assessment('tugas', 95.00);

        ReportCard::create([
            'student_id' => $this->student1->id,
            'class_id' => $this->class1->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $this->semester1->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->postAggregate();

        $response->assertStatus(422);
        $this->assertSame('30.00', (string) Grade::where('type', 'tugas')->first()->score, 'no score change on lock');
    }

    // ─── F. IDEMPOTENCY / ISOLATION ────────────────────────────────

    public function test_invoking_twice_is_idempotent(): void
    {
        $this->grade('tugas', 0.00);
        $this->grade('uts', 0.00);
        $this->assessment('tugas', 82.00);
        $this->assessment('uts', 64.00);

        Sanctum::actingAs($this->admin);
        $this->postAggregate()->assertStatus(200);
        $this->postAggregate()->assertStatus(200);

        $this->assertSame('82.00', (string) Grade::where('type', 'tugas')->first()->score);
        $this->assertSame('64.00', (string) Grade::where('type', 'uts')->first()->score);
    }

    public function test_foreign_records_are_ignored(): void
    {
        $this->grade('tugas', 0.00);
        $this->assessment('tugas', 90.00);

        $this->assessment('tugas', 5.00, 1, ['student_id' => $this->student2->id]);
        $this->assessment('tugas', 5.00, 1, ['subject_id' => $this->subject2->id]);
        $this->assessment('tugas', 5.00, 1, ['class_id' => $this->class2->id]);
        $this->assessment('tugas', 5.00, 1, ['semester_id' => $this->otherYearSemester->id]);

        Sanctum::actingAs($this->admin);
        $this->postAggregate()->assertStatus(200);

        $this->assertSame('90.00', (string) Grade::where('type', 'tugas')->first()->score);
    }

    public function test_assessments_remain_unchanged(): void
    {
        $this->grade('tugas', 0.00);
        $this->assessment('tugas', 72.00);
        $this->assessment('uts', 61.00);

        $before = GradeAssessment::query()->orderBy('id')->get()->toArray();

        Sanctum::actingAs($this->admin);
        $this->postAggregate()->assertStatus(200);

        $after = GradeAssessment::query()->orderBy('id')->get()->toArray();
        $this->assertSame($before, $after, 'the endpoint never mutates grade_assessments');
    }

    public function test_grade_identity_and_finalization_fields_unchanged(): void
    {
        $tugas = $this->grade('tugas', 11.00);

        $this->assessment('tugas', 77.00);

        $identityFields = [
            'student_id', 'subject_id', 'class_id', 'type',
            'academic_year_id', 'semester_id', 'semester', 'academic_year', 'is_final',
        ];
        $before = Grade::findOrFail($tugas->id)->only($identityFields);

        Sanctum::actingAs($this->admin);
        $this->postAggregate()->assertStatus(200);

        $tugas->refresh();
        $this->assertSame($before, $tugas->only($identityFields));
        $this->assertSame('77.00', (string) $tugas->score, 'only score is written back');
    }
}
