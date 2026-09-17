<?php

namespace Tests\Feature\Students;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassSubject;
use App\Models\Academic\GradeAssessment;
use App\Models\Academic\ReportCard;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\System\Setting;
use App\Services\Students\AcademicOutcomeEligibilityService;
use App\Services\Students\AcademicOutcomePolicyProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 2M-2H — outcome policy provider + advisory eligibility evaluator.
 *
 * Hermetic suite on sqlite :memory:. No school threshold values are asserted
 * as normative; policies are seeded per-test to exercise behavior.
 */
class AcademicOutcomeEligibilityTest extends TestCase
{
    private int $studentId;

    private int $classId;

    private int $yearId;

    private int $semesterId;

    private AcademicOutcomePolicyProvider $provider;

    private AcademicOutcomeEligibilityService $eligibility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->seedFixture();
        $this->provider = app(AcademicOutcomePolicyProvider::class);
        $this->eligibility = app(AcademicOutcomeEligibilityService::class);
    }

    private function buildSchema(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('group', 50)->nullable();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->string('type', 20)->default('string');
            $t->text('description')->nullable();
            $t->boolean('is_encrypted')->default(false);
            $t->boolean('is_public')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
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

        $mtk = Subject::create(['code' => 'MTK', 'name' => 'Matematika', 'type' => 'wajib']);
        $bin = Subject::create(['code' => 'BIN', 'name' => 'Bahasa Indonesia', 'type' => 'wajib']);
        $ing = Subject::create(['code' => 'ING', 'name' => 'Bahasa Inggris', 'type' => 'pilihan']);
        Subject::create(['code' => 'SJH', 'name' => 'Sejarah', 'type' => 'wajib']);

        foreach ([$mtk, $bin, $ing] as $subject) {
            ClassSubject::create(['class_id' => $class->id, 'subject_id' => $subject->id]);
        }

        $this->studentId = 42;
        $this->classId = $class->id;
        $this->yearId = $ay->id;
        $this->semesterId = $semester->id;
    }

    private function policyRow(string $key, string $type, ?string $value): Setting
    {
        return Setting::create([
            'group' => 'academic_outcome',
            'key' => $key,
            'type' => $type,
            'value' => $value,
            'is_public' => false,
        ]);
    }

    private function assessment(int $subjectId, float $score, string $category = 'tugas', int $sequence = 1): void
    {
        GradeAssessment::create([
            'student_id' => $this->studentId,
            'subject_id' => $subjectId,
            'class_id' => $this->classId,
            'academic_year_id' => $this->yearId,
            'semester_id' => $this->semesterId,
            'assessment_category' => $category,
            'assessment_sequence' => $sequence,
            'score' => $score,
            'max_score' => 100.00,
            'weight' => null,
        ]);
    }

    private function card(string $status = 'published'): void
    {
        ReportCard::create([
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $this->yearId,
            'semester_id' => $this->semesterId,
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    private function subjectId(string $code): int
    {
        return Subject::where('code', $code)->firstOrFail()->id;
    }

    private function evaluate(array $policyOverrides = [], string $scope = 'promotion'): array
    {
        return $this->eligibility->evaluate(
            $this->studentId,
            $this->classId,
            $this->yearId,
            $this->semesterId,
            $scope,
        );
    }

    // ------------------------- PROVIDER -------------------------

    public function test_provider_missing_scalar_defaults(): void
    {
        $policy = $this->provider->get();

        $this->assertFalse($policy['score_enabled']);
        $this->assertNull($policy['min_final_score']);
        $this->assertNull($policy['min_completeness_pct']);
        $this->assertNull($policy['required_subjects']);
        $this->assertSame([], $this->provider->configurationErrors());
    }

    public function test_provider_boolean_coercion(): void
    {
        $this->policyRow('score_enabled', 'boolean', 'true');

        $policy = $this->provider->get();

        $this->assertTrue($policy['score_enabled']);
    }

    public function test_provider_integer_coercion(): void
    {
        $this->policyRow('min_final_score', 'integer', '75');

        $policy = $this->provider->get();

        $this->assertSame(75, $policy['min_final_score']);
    }

    public function test_provider_invalid_integer_reported(): void
    {
        $this->policyRow('min_final_score', 'integer', 'abc');

        $policy = $this->provider->get();

        $this->assertNull($policy['min_final_score']);
        $this->assertContains('min_final_score', $this->provider->configurationErrors());
    }

    public function test_provider_required_subjects_valid_json(): void
    {
        $this->policyRow('required_subjects', 'string', json_encode(['1', '2']));

        $policy = $this->provider->get();

        $this->assertSame([1, 2], $policy['required_subjects']);
    }

    public function test_provider_required_subjects_malformed_json(): void
    {
        $this->policyRow('required_subjects', 'string', '{not-json');

        $policy = $this->provider->get();

        $this->assertNull($policy['required_subjects']);
    }

    public function test_provider_required_subjects_invalid_ids(): void
    {
        $this->policyRow('required_subjects', 'string', json_encode(['1', 'x', 3.5]));

        $this->assertNull($this->provider->get()['required_subjects']);
    }

    public function test_provider_settings_are_non_public(): void
    {
        $this->policyRow('score_enabled', 'boolean', 'true');

        $row = Setting::where('key', 'score_enabled')->first();
        $this->assertFalse($row->is_public);
        $this->assertSame('academic_outcome', $row->group);
    }

    // ------------------------- EVALUATOR -------------------------

    public function test_evaluator_all_policy_disabled(): void
    {
        $result = $this->evaluate();

        $this->assertFalse($result['eligible']);
        $this->assertNull($result['outcome']);
        $this->assertContains('policy_not_configured', array_column($result['reasons'], 'code'), 'policy_not_configured must appear for all disabled policies');
    }

    public function test_threshold_pass(): void
    {
        $this->policyRow('score_enabled', 'boolean', 'true');
        $this->policyRow('min_final_score', 'integer', '70');
        $this->assessment($this->subjectId('MTK'), 80.0);

        $result = $this->evaluate();

        $this->assertTrue($result['eligible']);
    }

    public function test_threshold_fail(): void
    {
        $this->policyRow('score_enabled', 'boolean', 'true');
        $this->policyRow('min_final_score', 'integer', '70');
        $this->assessment($this->subjectId('MTK'), 60.0);

        $result = $this->evaluate();

        $this->assertFalse($result['eligible']);
        $this->assertContains('score_below_minimum', array_column($result['reasons'], 'code'));
    }

    public function test_threshold_boundary_equal_passes(): void
    {
        $this->policyRow('score_enabled', 'boolean', 'true');
        $this->policyRow('min_final_score', 'integer', '80');
        $this->assessment($this->subjectId('MTK'), 80.0);

        $this->assertTrue($this->evaluate()['eligible']);
    }

    public function test_null_final_is_not_failure_by_default(): void
    {
        $this->policyRow('score_enabled', 'boolean', 'true');
        $this->policyRow('min_final_score', 'integer', '70');
        $this->assessment($this->subjectId('MTK'), 80.0);
        // BIN has no final -> neutral, not failure.

        $result = $this->evaluate();

        $this->assertTrue($result['eligible']);
        $this->assertNotContains('missing_final', array_column($result['reasons'], 'code'));
    }

    public function test_zero_final_fails_positive_threshold(): void
    {
        $this->policyRow('score_enabled', 'boolean', 'true');
        $this->policyRow('min_final_score', 'integer', '70');
        $this->assessment($this->subjectId('MTK'), 0.0);

        $result = $this->evaluate();

        $this->assertFalse($result['eligible']);
        $this->assertContains('score_below_minimum', array_column($result['reasons'], 'code'));
    }

    public function test_zero_not_valid_failure(): void
    {
        $this->policyRow('score_enabled', 'boolean', 'true');
        $this->policyRow('min_final_score', 'integer', '0');
        $this->policyRow('zero_is_valid', 'boolean', 'false');
        $this->assessment($this->subjectId('MTK'), 0.0);

        $result = $this->evaluate();

        $this->assertFalse($result['eligible']);
        $this->assertContains('zero_not_valid', array_column($result['reasons'], 'code'));
    }

    public function test_completeness_pass(): void
    {
        $this->policyRow('min_completeness_pct', 'integer', '100');
        foreach (['MTK', 'BIN'] as $code) {
            $this->assessment($this->subjectId($code), 80.0);
        }

        $this->assertTrue($this->evaluate()['eligible']);
    }

    public function test_completeness_fail(): void
    {
        $this->policyRow('min_completeness_pct', 'integer', '100');
        $this->assessment($this->subjectId('MTK'), 80.0);

        $result = $this->evaluate();

        $this->assertFalse($result['eligible']);
        $this->assertContains('completeness_below_minimum', array_column($result['reasons'], 'code'));
    }

    public function test_required_subject_present(): void
    {
        $this->policyRow('required_subjects', 'string', json_encode([$this->subjectId('MTK')]));
        $this->assessment($this->subjectId('MTK'), 80.0);

        $this->assertTrue($this->evaluate()['eligible']);
    }

    public function test_required_subject_missing(): void
    {
        $this->policyRow('required_subjects', 'string', json_encode([999]));
        $this->assessment($this->subjectId('MTK'), 80.0);

        $result = $this->evaluate();

        $this->assertFalse($result['eligible']);
        $this->assertContains('required_subject_missing', array_column($result['reasons'], 'code'));
    }

    public function test_required_subject_below_minimum(): void
    {
        $this->policyRow('score_enabled', 'boolean', 'true');
        $this->policyRow('min_final_score', 'integer', '70');
        $this->policyRow('required_subjects', 'string', json_encode([$this->subjectId('MTK')]));
        $this->assessment($this->subjectId('MTK'), 60.0);

        $result = $this->evaluate();

        $this->assertContains('required_subject_below_minimum', array_column($result['reasons'], 'code'));
    }

    public function test_pilihan_exclusion_and_reason(): void
    {
        $this->policyRow('min_completeness_pct', 'integer', '100');
        // wajib MTK + BIN have finals; pilihan ING has none.
        $this->assessment($this->subjectId('MTK'), 80.0);
        $this->assessment($this->subjectId('BIN'), 80.0);

        $result = $this->evaluate();

        $this->assertTrue($result['eligible'], 'pilihan excluded from wajib-only completeness');
        $this->assertContains('pilihan_excluded', array_column($result['reasons'], 'code'));
    }

    public function test_report_card_unpublished_fails_when_required(): void
    {
        $this->policyRow('report_card_required', 'boolean', 'true');
        $this->card('draft');

        $result = $this->evaluate();

        $this->assertFalse($result['eligible']);
        $this->assertContains('report_card_not_published', array_column($result['reasons'], 'code'));
    }

    public function test_report_card_published_passes_when_required(): void
    {
        $this->policyRow('report_card_required', 'boolean', 'true');
        $this->card('published');

        $this->assertTrue($this->evaluate()['eligible']);
    }

    public function test_report_card_optional_when_disabled(): void
    {
        $this->policyRow('score_enabled', 'boolean', 'true');
        $this->policyRow('min_final_score', 'integer', '70');
        $this->assessment($this->subjectId('MTK'), 80.0);
        // draft card present; report_card_required not enabled.

        $this->assertTrue($this->evaluate()['eligible']);
    }

    public function test_scope_isolation_no_accidental_graduation(): void
    {
        $this->policyRow('score_enabled', 'boolean', 'true');
        $this->policyRow('min_final_score', 'integer', '70');
        $this->assessment($this->subjectId('MTK'), 80.0);
        $this->assessment($this->subjectId('BIN'), 80.0);

        $promotion = $this->evaluate([], 'promotion');
        $graduation = $this->evaluate([], 'graduation');

        $this->assertNull($promotion['outcome'], 'promotion never infers outcome');
        $this->assertNull($graduation['outcome'], 'graduation never auto-decides lulus');
        $this->assertSame($promotion['eligible'], $graduation['eligible']);
    }

    public function test_multiple_reasons_and_stable_ordering(): void
    {
        $this->policyRow('score_enabled', 'boolean', 'true');
        $this->policyRow('min_final_score', 'integer', '70');
        $this->policyRow('min_completeness_pct', 'integer', '100');
        $this->policyRow('report_card_required', 'boolean', 'true');
        $this->assessment($this->subjectId('MTK'), 60.0);

        $first = $this->evaluate();
        $second = $this->evaluate();

        $codes = array_column($second['reasons'], 'code');
        $expected = $codes;
        sort($expected);
        $this->assertSame($expected, $codes, 'reasons sorted by code');
        $this->assertGreaterThanOrEqual(2, count($codes));
        $this->assertSame($first, $second, 'deterministic across runs');
    }

    public function test_malformed_policy_never_permissive(): void
    {
        $this->policyRow('min_final_score', 'integer', 'abc');

        $result = $this->evaluate();

        $codes = array_column($result['reasons'], 'code');
        $this->assertFalse($result['eligible']);
        $this->assertContains('policy_invalid', $codes);
        $this->assertContains('policy_not_configured', $codes, 'score rule disabled by malformed value, nothing auto-passes');
    }

    public function test_remadial_advisory_only(): void
    {
        $this->policyRow('score_enabled', 'boolean', 'true');
        $this->policyRow('min_final_score', 'integer', '70');
        $this->assessment($this->subjectId('MTK'), 80.0, 'remedial', 1);
        $this->assessment($this->subjectId('MTK'), 75.0, 'tugas', 2);

        $result = $this->evaluate();

        $this->assertContains('remedial_not_allowed', array_column($result['reasons'], 'code'), 'advisory only');
        $this->assertTrue($result['eligible'], 'remedial never fails by itself');
    }

    public function test_no_mutation(): void
    {
        $this->policyRow('report_card_required', 'boolean', 'true');
        $this->card('draft');
        $before = [
            DB::table('grade_assessments')->count(),
            DB::table('report_cards')->count(),
            DB::table('settings')->count(),
        ];

        $this->evaluate();

        $after = [
            DB::table('grade_assessments')->count(),
            DB::table('report_cards')->count(),
            DB::table('settings')->count(),
        ];

        $this->assertSame($before, $after);
    }

    public function test_evidence_reused_no_duplicate_aggregation(): void
    {
        $this->policyRow('score_enabled', 'boolean', 'true');
        $this->policyRow('min_final_score', 'integer', '70');
        $this->assessment($this->subjectId('MTK'), 80.0);

        DB::enableQueryLog();
        $this->evaluate();
        $small = $this->assessmentQueries();
        DB::flushQueryLog();

        $class = SchoolClass::create(['name' => '7B']);
        $subjects = [];
        foreach (['MTK', 'BIN', 'ING', 'SJH'] as $code) {
            $subject = Subject::where('code', $code)->firstOrFail();
            ClassSubject::create(['class_id' => $class->id, 'subject_id' => $subject->id]);
            $subjects[] = $subject;
        }
        foreach ($subjects as $i => $subject) {
            GradeAssessment::create([
                'student_id' => 43,
                'subject_id' => $subject->id,
                'class_id' => $class->id,
                'academic_year_id' => $this->yearId,
                'semester_id' => $this->semesterId,
                'assessment_category' => 'tugas',
                'assessment_sequence' => 1,
                'score' => 60.0 + $i,
                'max_score' => 100.00,
                'weight' => null,
            ]);
        }

        DB::enableQueryLog();
        $this->eligibility->evaluate(43, $class->id, $this->yearId, $this->semesterId);
        $large = $this->assessmentQueries();
        DB::flushQueryLog();

        $this->assertLessThanOrEqual(2, $small);
        $this->assertSame($small, $large, 'evaluator adds no aggregation beyond the evidence layer');
    }

    public function test_zero_expected_subjects_deterministic(): void
    {
        $class = SchoolClass::create(['name' => '7X']);
        $this->policyRow('min_completeness_pct', 'integer', '100');

        // Note: completeness uses the policy class; build distinct identity.
        $result = $this->eligibility->evaluate($this->studentId, $class->id, $this->yearId, $this->semesterId);

        $this->assertFalse($result['eligible']);
        $this->assertContains('completeness_not_computable', array_column($result['reasons'], 'code'));
    }

    public function test_invalid_scope_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->evaluate([], 'invalid-scope');
    }

    private function assessmentQueries(): int
    {
        return collect(DB::getQueryLog())
            ->filter(function (array $entry) {
                $sql = strtolower(ltrim((string) $entry['query']));

                return str_starts_with($sql, 'select') && str_contains($sql, 'grade_assessments');
            })
            ->count();
    }
}
