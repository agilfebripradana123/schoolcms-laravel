<?php

namespace Tests\Feature\Students;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassSubject;
use App\Models\Academic\GradeAssessment;
use App\Models\Academic\ReportCard;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Students\Student;
use App\Models\Students\StudentHistory;
use App\Models\System\AuditLog;
use App\Models\System\Role;
use App\Models\System\Setting;
use App\Models\System\User;
use App\Services\Students\AcademicOutcomeEligibilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2M-2D — outcome finalization foundation.
 *
 * Hermetic suite: builds its own schema on sqlite :memory: and exercises the
 * /api/student-histories/{id}/finalize flow plus post-finalization immutability.
 */
class StudentHistoryFinalizeTest extends TestCase
{
    private User $admin;

    private User $guru;

    private int $studentId;

    private int $classId;

    private int $academicYearId;

    private int $semester1Id;

    private int $semester2Id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->seedFixture();
    }

    private function buildSchema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->nullable();
            $t->string('username')->nullable();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
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
        Schema::create('classes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->string('nisn')->nullable();
            $t->string('nis')->nullable();
            $t->string('name');
            $t->string('gender', 1)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('student_histories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->string('status');
            $t->text('notes')->nullable();
            $t->boolean('is_final')->default(false);
            $t->dateTime('finalized_at')->nullable();
            $t->unsignedInteger('finalized_by')->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'academic_year_id'], 'uniq_student_histories');
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
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('action');
            $t->string('model');
            $t->unsignedBigInteger('model_id');
            $t->text('description')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent')->nullable();
            $t->timestamp('created_at')->nullable();
        });
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
        // Side-effect placeholders — must stay 0 post-finalization.
        foreach (['grades', 'class_students', 'alumni', 'transfers'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
            });
        }
    }

    private function seedFixture(): void
    {
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleGuru = Role::create(['name' => 'Guru']);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@fz.test', 'username' => 'admin', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $this->guru = User::create(['name' => 'Guru', 'email' => 'guru@fz.test', 'username' => 'guru', 'password' => 'x', 'role_id' => $roleGuru->id]);

        $ay = AcademicYear::create(['name' => '2026/2027']);
        $this->semester1Id = Semester::create(['academic_year_id' => $ay->id, 'name' => '1'])->id;
        $this->semester2Id = Semester::create(['academic_year_id' => $ay->id, 'name' => '2'])->id;
        $this->academicYearId = $ay->id;

        $class = SchoolClass::create(['name' => '7A']);
        $this->classId = $class->id;

        $studentA = Student::create(['name' => 'Siswa A', 'nisn' => '111', 'nis' => '001', 'class_id' => $class->id]);
        $studentB = Student::create(['name' => 'Siswa B', 'nisn' => '222', 'nis' => '002', 'class_id' => $class->id]);
        $this->studentId = $studentA->id;

        Sanctum::actingAs($this->admin);
    }

    private function history(string $status = 'naik'): StudentHistory
    {
        return StudentHistory::create([
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
            'status' => $status,
            'notes' => null,
        ]);
    }

    private function card(string $status = 'published', ?int $studentId = null, ?int $classId = null, ?int $yearId = null, ?int $semesterId = null): ReportCard
    {
        return ReportCard::create([
            'student_id' => $studentId ?? $this->studentId,
            'class_id' => $classId ?? $this->classId,
            'academic_year_id' => $yearId ?? $this->academicYearId,
            'semester_id' => $semesterId ?? $this->semester2Id,
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    private function assertLocked422($response): void
    {
        $response->assertStatus(422)
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonStructure(['success', 'message', 'errors', 'data']);
    }

    public function test_draft_history_finalizes_with_published_terminal_report_card(): void
    {
        $this->card();
        $history = $this->history();

        $this->postJson("/api/student-histories/{$history->id}/finalize")
            ->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['status' => 'naik']]);
    }

    public function test_finalization_sets_metadata(): void
    {
        $this->card();
        $history = $this->history();

        $this->postJson("/api/student-histories/{$history->id}/finalize")->assertStatus(200);

        $row = StudentHistory::find($history->id);
        $this->assertTrue($row->is_final);
        $this->assertNotNull($row->finalized_at);
        $this->assertSame($this->admin->id, $row->finalized_by);
    }

    public function test_missing_semester_2_rejects(): void
    {
        $this->card();

        $ay = AcademicYear::create(['name' => '2025/2026']);
        Semester::create(['academic_year_id' => $ay->id, 'name' => '1']);

        $history = StudentHistory::create([
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $ay->id,
            'status' => 'naik',
        ]);

        $this->assertLocked422($this->postJson("/api/student-histories/{$history->id}/finalize"));
        $this->assertFalse(StudentHistory::find($history->id)->is_final);
    }

    public function test_duplicate_semester_2_rejects(): void
    {
        $ay = AcademicYear::create(['name' => '2025/2026']);
        Semester::create(['academic_year_id' => $ay->id, 'name' => '1']);
        Semester::create(['academic_year_id' => $ay->id, 'name' => '2']);
        Semester::create(['academic_year_id' => $ay->id, 'name' => '2']);

        ReportCard::create([
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $ay->id,
            'semester_id' => Semester::where('academic_year_id', $ay->id)->where('name', '2')->first()->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $history = StudentHistory::create([
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $ay->id,
            'status' => 'naik',
        ]);

        $this->assertLocked422($this->postJson("/api/student-histories/{$history->id}/finalize"));
        $this->assertFalse(StudentHistory::find($history->id)->is_final);
    }

    public function test_draft_report_card_rejects(): void
    {
        $this->card('draft');
        $history = $this->history();

        $this->assertLocked422($this->postJson("/api/student-histories/{$history->id}/finalize"));
    }

    public function test_wrong_class_rejects(): void
    {
        $otherClass = SchoolClass::create(['name' => '7B']);
        $this->card('published', null, $otherClass->id);
        $history = $this->history();

        $this->assertLocked422($this->postJson("/api/student-histories/{$history->id}/finalize"));
    }

    public function test_wrong_academic_year_rejects(): void
    {
        $otherYear = AcademicYear::create(['name' => '2025/2026']);
        $sem = Semester::create(['academic_year_id' => $otherYear->id, 'name' => '2']);

        ReportCard::create([
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $otherYear->id,
            'semester_id' => $sem->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $history = $this->history();

        $this->assertLocked422($this->postJson("/api/student-histories/{$history->id}/finalize"));
    }

    public function test_wrong_student_rejects(): void
    {
        $otherStudent = Student::where('name', 'Siswa B')->firstOrFail();
        $history = $this->history();
        $this->card('published', $otherStudent->id);

        $this->assertLocked422($this->postJson("/api/student-histories/{$history->id}/finalize"));
    }

    public function test_already_finalized_cannot_finalize_again(): void
    {
        $this->card();
        $history = $this->history();

        $this->postJson("/api/student-histories/{$history->id}/finalize")->assertStatus(200);
        $stamp = StudentHistory::find($history->id)->finalized_at;

        $this->assertLocked422($this->postJson("/api/student-histories/{$history->id}/finalize"));
        $this->assertSame($stamp->timestamp, StudentHistory::find($history->id)->finalized_at->timestamp, 'idempotent-by-state: second finalize preserves original stamp');
    }

    public function test_finalized_history_cannot_be_updated(): void
    {
        $this->card();
        $history = $this->history();
        $this->postJson("/api/student-histories/{$history->id}/finalize")->assertStatus(200);

        $this->assertLocked422($this->putJson("/api/student-histories/{$history->id}", ['status' => 'tinggal']));
        $this->assertSame('naik', StudentHistory::find($history->id)->status);
    }

    public function test_finalized_history_cannot_be_deleted(): void
    {
        $this->card();
        $history = $this->history();
        $this->postJson("/api/student-histories/{$history->id}/finalize")->assertStatus(200);

        $this->assertLocked422($this->deleteJson("/api/student-histories/{$history->id}"));
        $this->assertDatabaseHas('student_histories', ['id' => $history->id]);
    }

    public function test_finalized_identity_and_status_immutable(): void
    {
        $this->card();
        $history = $this->history();
        $this->postJson("/api/student-histories/{$history->id}/finalize")->assertStatus(200);
        $before = StudentHistory::find($history->id)->toArray();

        $otherClass = SchoolClass::create(['name' => '7B']);
        $otherYear = AcademicYear::create(['name' => '2025/2026']);

        foreach ([
            'status' => 'tinggal',
            'class_id' => $otherClass->id,
            'academic_year_id' => $otherYear->id,
        ] as $field => $value) {
            $this->assertLocked422($this->putJson("/api/student-histories/{$history->id}", [$field => $value]));
            $this->assertSame($before[$field], StudentHistory::find($history->id)->{$field}, "{$field} must stay immutable");
        }
    }

    public function test_unauthorized_role_receives_403(): void
    {
        Sanctum::actingAs($this->guru);

        $history = StudentHistory::create([
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
            'status' => 'naik',
        ]);

        $this->postJson("/api/student-histories/{$history->id}/finalize")->assertStatus(403);
        $this->assertFalse(StudentHistory::find($history->id)->is_final);
    }

    public function test_finalization_has_no_side_effects(): void
    {
        $this->card();
        $history = $this->history();

        $this->postJson("/api/student-histories/{$history->id}/finalize")->assertStatus(200);

        foreach (['grades', 'grade_assessments', 'class_students', 'alumni', 'transfers'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        $this->assertSame(1, ReportCard::count(), 'report cards untouched');
        $this->assertSame($this->classId, Student::find($this->studentId)->class_id, 'student class untouched');
    }

    public function test_annual_uniqueness_enforced(): void
    {
        $this->postJson('/api/student-histories', [
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
            'status' => 'naik',
        ])->assertStatus(201);

        $this->postJson('/api/student-histories', [
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
            'status' => 'tinggal',
        ])->assertStatus(422);
    }

    // ─── PHASE 2M-2I ADDITIONS ────────────────────────────────────

    public function test_successful_finalization_creates_exactly_one_audit_log(): void
    {
        $this->card();
        $history = $this->history();

        $this->assertDatabaseCount('audit_logs', 0);
        $this->postJson("/api/student-histories/{$history->id}/finalize")->assertStatus(200);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_audit_records_actor_and_identity(): void
    {
        $this->card();
        $history = $this->history();
        $this->postJson("/api/student-histories/{$history->id}/finalize")->assertStatus(200);

        $log = AuditLog::first();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('student_history_finalized', $log->action);
        $this->assertSame('StudentHistory', $log->model);
        $this->assertSame($history->id, $log->model_id);
        $this->assertStringContainsString($history->status, $log->description);
    }

    public function test_decision_reason_is_recorded_in_history_and_audit(): void
    {
        $this->card();
        $history = $this->history();

        $this->postJson("/api/student-histories/{$history->id}/finalize", [
            'notes' => 'Approved after review.',
        ])->assertStatus(200);

        $row = StudentHistory::find($history->id);
        $this->assertSame('Approved after review.', $row->notes);
        $this->assertStringContainsString('Approved after review.', AuditLog::first()->description);
    }

    public function test_failed_finalization_does_not_create_audit(): void
    {
        $this->card('draft');
        $history = $this->history();

        $this->assertLocked422($this->postJson("/api/student-histories/{$history->id}/finalize"));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_already_finalized_attempt_does_not_duplicate_audit(): void
    {
        $this->card();
        $history = $this->history();
        $this->postJson("/api/student-histories/{$history->id}/finalize")->assertStatus(200);
        $this->assertDatabaseCount('audit_logs', 1);

        $this->assertLocked422($this->postJson("/api/student-histories/{$history->id}/finalize"));
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_advisory_ineligible_does_not_block_explicit_finalization(): void
    {
        $this->card();
        $history = $this->history();

        $eligibility = app(AcademicOutcomeEligibilityService::class);
        $result = $eligibility->evaluate($this->studentId, $this->classId, $this->academicYearId, $this->semester2Id);
        $this->assertFalse($result['eligible'], 'precondition: evaluator reports ineligible');

        $this->postJson("/api/student-histories/{$history->id}/finalize")->assertStatus(200);
        $this->assertTrue(StudentHistory::find($history->id)->is_final);
    }

    public function test_advisory_eligible_does_not_auto_finalize(): void
    {
        $mtk = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        ClassSubject::create(['class_id' => $this->classId, 'subject_id' => $mtk->id]);
        GradeAssessment::create([
            'student_id' => $this->studentId,
            'subject_id' => $mtk->id,
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semester2Id,
            'assessment_category' => 'tugas',
            'assessment_sequence' => 1,
            'score' => 80.00,
            'max_score' => 100.00,
            'weight' => null,
        ]);
        $this->policyRow('min_completeness_pct', 'integer', '50');
        $history = $this->history();

        $result = app(AcademicOutcomeEligibilityService::class)
            ->evaluate($this->studentId, $this->classId, $this->academicYearId, $this->semester2Id);
        $this->assertTrue($result['eligible'], 'precondition: evaluator reports eligible');

        $this->assertFalse(StudentHistory::find($history->id)->is_final, 'evaluator must not auto-finalize');
    }

    public function test_outcome_selection_remains_explicit_admin_decision(): void
    {
        $this->card();
        $history = $this->history();

        $result = app(AcademicOutcomeEligibilityService::class)
            ->evaluate($this->studentId, $this->classId, $this->academicYearId, $this->semester2Id);
        $this->assertNull($result['outcome']);

        $this->assertSame('naik', StudentHistory::find($history->id)->status, 'admin selected status persists');
        $this->postJson("/api/student-histories/{$history->id}/finalize")->assertStatus(200);
        $this->assertTrue(StudentHistory::find($history->id)->is_final);
    }

    private function policyRow(string $key, string $type, ?string $value): void
    {
        Setting::create([
            'group' => 'academic_outcome',
            'key' => $key,
            'type' => $type,
            'value' => $value,
            'is_public' => false,
        ]);
    }
}
