<?php

namespace Tests\Feature\GradeAssessment;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class GradeAssessmentPeriodReconciliationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->seedBase();
    }

    private function buildSchema(): void
    {
        Schema::dropIfExists('grade_assessments');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('semesters');
        Schema::dropIfExists('academic_years');

        Schema::create('academic_years', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_active')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('semesters', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('academic_year_id');
            $t->string('name');
            $t->boolean('is_active')->default(false);
            $t->timestamps();
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
            $t->unsignedBigInteger('academic_year_id');
            $t->unsignedBigInteger('semester_id');
            $t->boolean('is_final')->default(false);
            $t->timestamps();
        });

        Schema::create('grade_assessments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('academic_year_id')->nullable();
            $t->unsignedBigInteger('semester_id')->nullable();
            $t->string('assessment_category', 50);
            $t->unsignedInteger('assessment_sequence');
            $t->decimal('score', 5, 2);
            $t->decimal('max_score', 5, 2)->default(100);
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    private function seedBase(): void
    {
        DB::table('academic_years')->insert([
            ['id' => 1, 'name' => '2024/2025', 'is_active' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => '2025/2026', 'is_active' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => '2026/2027', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('semesters')->insert([
            ['id' => 105, 'academic_year_id' => 3, 'name' => '1', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Seed Grades matching assessment types exactly (3 students × 1 class, all 3 types).
        $gradeTypes = ['tugas', 'uts', 'uas'];
        foreach ([1, 2, 3] as $sid) {
            foreach ([1, 2] as $subid) {
                foreach ($gradeTypes as $type) {
                    DB::table('grades')->insert([
                        'student_id' => $sid, 'subject_id' => $subid, 'class_id' => 1,
                        'type' => $type, 'score' => 80.00, 'semester' => '1',
                        'academic_year' => '2026/2027', 'academic_year_id' => 3, 'semester_id' => 105,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        // Seed assessments — 1:1 with each Grade, all NULL periods.
        $assessments = [];
        foreach ([1, 2, 3] as $sid) {
            foreach ([1, 2] as $subid) {
                foreach ($gradeTypes as $cat) {
                    $assessments[] = [
                        'student_id' => $sid, 'subject_id' => $subid, 'class_id' => 1,
                        'academic_year_id' => null, 'semester_id' => null,
                        'assessment_category' => $cat, 'assessment_sequence' => 1,
                        'score' => 80.00, 'max_score' => 100.00,
                        'notes' => 'Migrated from legacy Grade table',
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }
        }
        DB::table('grade_assessments')->insert($assessments);
    }

    private function runReconciliation(): void
    {
        $migrationPath = __DIR__ . '/../../../database/migrations/2026_11_01_000001_reconcile_legacy_assessment_periods.php';
        $migration = require $migrationPath;
        $migration->up();
    }

    // ──────────────────────────────────────────────────────────────
    // Test 1: Legacy periods are reconciled
    // ──────────────────────────────────────────────────────────────
    public function test_legacy_periods_are_reconciled(): void
    {
        $expected = DB::table('grade_assessments')->count();

        $nullBefore = DB::table('grade_assessments')
            ->where(function ($q) { $q->whereNull('academic_year_id')->orWhereNull('semester_id'); })
            ->count();
        $this->assertSame($expected, $nullBefore);

        $this->runReconciliation();

        $nullAfter = DB::table('grade_assessments')
            ->where(function ($q) { $q->whereNull('academic_year_id')->orWhereNull('semester_id'); })
            ->count();
        $this->assertSame(0, $nullAfter);

        // All assessments must have been populated with period IDs
        $populated = DB::table('grade_assessments')
            ->where('academic_year_id', 3)
            ->where('semester_id', 105)
            ->count();
        $this->assertSame($expected, $populated);

        $sample = DB::table('grade_assessments')
            ->where('student_id', 1)->where('subject_id', 1)->where('class_id', 1)
            ->where('assessment_category', 'tugas')->where('assessment_sequence', 1)
            ->first();
        $this->assertSame(3, (int) $sample->academic_year_id);
        $this->assertSame(105, (int) $sample->semester_id);
    }

    // ──────────────────────────────────────────────────────────────
    // Test 2: Correct identity matching
    // ──────────────────────────────────────────────────────────────
    public function test_identity_matching_is_correct(): void
    {
        DB::table('grades')->insert([
            'student_id' => 4, 'subject_id' => 2, 'class_id' => 2, 'type' => 'uts',
            'score' => 70.00, 'semester' => '1', 'academic_year' => '2026/2027',
            'academic_year_id' => 3, 'semester_id' => 105,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('grade_assessments')->insert([
            'student_id' => 4, 'subject_id' => 2, 'class_id' => 2,
            'academic_year_id' => null, 'semester_id' => null,
            'assessment_category' => 'uts', 'assessment_sequence' => 1,
            'score' => 70.00, 'max_score' => 100.00, 'notes' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runReconciliation();

        $assessment = DB::table('grade_assessments')
            ->where('student_id', 4)->where('subject_id', 2)
            ->where('class_id', 2)->where('assessment_category', 'uts')
            ->where('assessment_sequence', 1)->first();

        $this->assertSame(3, (int) $assessment->academic_year_id);
        $this->assertSame(105, (int) $assessment->semester_id);
    }

    // ──────────────────────────────────────────────────────────────
    // Test 3: Existing periods are not overwritten
    // ──────────────────────────────────────────────────────────────
    public function test_existing_periods_not_overwritten(): void
    {
        $existingId = DB::table('grade_assessments')->insertGetId([
            'student_id' => 1, 'subject_id' => 1, 'class_id' => 1,
            'academic_year_id' => 1, 'semester_id' => 105,
            'assessment_category' => 'tugas', 'assessment_sequence' => 2,
            'score' => 99.00, 'max_score' => 100.00, 'notes' => 'already resolved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $before = DB::table('grade_assessments')->where('id', $existingId)->first();
        $this->assertSame(1, (int) $before->academic_year_id);

        $this->runReconciliation();

        $after = DB::table('grade_assessments')->where('id', $existingId)->first();
        // Must remain 1, not overwritten to 3 by the grade match.
        $this->assertSame(1, (int) $after->academic_year_id);
    }

    // ──────────────────────────────────────────────────────────────
    // Test 4: Unmatched assessment raises error
    // ──────────────────────────────────────────────────────────────
    public function test_unmatched_assessment_raises_error(): void
    {
        DB::table('grade_assessments')->insert([
            'student_id' => 99, 'subject_id' => 99, 'class_id' => 99,
            'academic_year_id' => null, 'semester_id' => null,
            'assessment_category' => 'other', 'assessment_sequence' => 1,
            'score' => 50.00, 'max_score' => 100.00, 'notes' => 'orphan',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The migration should throw RuntimeException when an unmatched row exists.
        try {
            $this->runReconciliation();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('has no matching Grade', $e->getMessage());
        }

        // Orphan must remain NULL.
        $orphan = DB::table('grade_assessments')
            ->where('student_id', 99)->where('assessment_category', 'other')->first();
        $this->assertNull($orphan->academic_year_id);
        $this->assertNull($orphan->semester_id);
    }

    // ──────────────────────────────────────────────────────────────
    // Test 5: Idempotency
    // ──────────────────────────────────────────────────────────────
    public function test_idempotent(): void
    {
        $this->runReconciliation();

        $total = DB::table('grade_assessments')->count();
        $resolved = DB::table('grade_assessments')
            ->where('academic_year_id', 3)->where('semester_id', 105)->count();
        $this->assertSame($total, $resolved);

        // Run again — no exception, same count.
        $this->runReconciliation();

        $resolvedAfter = DB::table('grade_assessments')
            ->where('academic_year_id', 3)->where('semester_id', 105)->count();
        $this->assertSame($total, $resolvedAfter);
    }

    // ──────────────────────────────────────────────────────────────
    // Test 6: Existing Grade data remains unchanged
    // ──────────────────────────────────────────────────────────────
    public function test_grade_data_unchanged(): void
    {
        $gradesBefore = DB::table('grades')
            ->select('student_id', 'subject_id', 'class_id', 'type', 'score', 'academic_year_id', 'semester_id')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();

        $this->runReconciliation();

        $gradesAfter = DB::table('grades')
            ->select('student_id', 'subject_id', 'class_id', 'type', 'score', 'academic_year_id', 'semester_id')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();

        $this->assertCount(count($gradesBefore), $gradesAfter);
        $this->assertSame($gradesBefore, $gradesAfter);
    }
}
