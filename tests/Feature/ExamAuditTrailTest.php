<?php

namespace Tests\Feature;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Subject;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamAnswer;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamAttemptQuestion;
use App\Models\Examination\ExamResult;
use App\Models\Examination\QuestionBank;
use App\Models\Staff\Teacher;
use App\Models\Staff\TeacherAssignment;
use App\Models\Students\Student;
use App\Models\System\AuditLog;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase B19 — examination audit logging.
 *
 * Hermetic suite; verifies audit events for exam lifecycle, participant,
 * result generate/recompute, essay grading, and privacy (no answer text,
 * feedback text, correct-option identity, or tokens in payloads).
 */
class ExamAuditTrailTest extends TestCase
{
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
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
            $t->primary(['permission_id', 'role_id']);
        });
        Schema::create('permission_user', function (Blueprint $t) {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('user_id');
            $t->primary(['permission_id', 'user_id']);
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->nullable();
            $t->string('username')->nullable();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('teachers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('full_name')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('academic_years', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('classes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('teacher_assignments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('teacher_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->timestamps();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->string('nis')->nullable();
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('question_banks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('subject_id');
            $t->string('code')->nullable();
            $t->text('question_text');
            $t->string('type');
            $t->string('difficulty')->default('medium');
            $t->unsignedInteger('points')->default(1);
            $t->string('status')->default('approved');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('question_options', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('question_id');
            $t->string('option_text');
            $t->boolean('is_correct')->default(false);
            $t->timestamps();
        });
        Schema::create('exams', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('subject_id');
            $t->string('title');
            $t->text('description')->nullable();
            $t->unsignedInteger('duration_minutes');
            $t->unsignedInteger('total_questions')->default(0);
            $t->unsignedInteger('passing_score')->default(0);
            $t->unsignedInteger('max_attempts')->default(1);
            $t->boolean('shuffle_questions')->default(false);
            $t->boolean('shuffle_options')->default(false);
            $t->boolean('show_result')->default(true);
            $t->string('status')->default('draft');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('exam_questions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_id');
            $t->unsignedBigInteger('question_id');
            $t->unsignedInteger('position')->default(0);
            $t->unsignedInteger('points')->default(1);
            $t->timestamps();
        });
        Schema::create('exam_participants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('schedule_id')->nullable();
            $t->string('exam_card_number');
            $t->string('status')->default('registered');
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->boolean('is_blocked')->default(false);
            $t->boolean('login_allowed')->default(true);
            $t->timestamps();
        });
        Schema::create('exam_attempts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_participant_id');
            $t->unsignedBigInteger('exam_id');
            $t->unsignedInteger('attempt_number');
            $t->string('status')->default('active');
            $t->dateTime('started_at')->nullable();
            $t->dateTime('expires_at')->nullable();
            $t->dateTime('submitted_at')->nullable();
            $t->json('question_order')->nullable();
            $t->json('option_order')->nullable();
            $t->string('token', 64)->nullable();
            $t->timestamps();
        });
        Schema::create('exam_attempt_questions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_attempt_id');
            $t->unsignedInteger('source_question_id')->nullable();
            $t->string('question_code', 50)->nullable();
            $t->text('question_text');
            $t->string('question_type', 50);
            $t->unsignedInteger('points')->default(1);
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();
        });
        Schema::create('exam_attempt_question_options', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('attempt_question_id');
            $t->unsignedInteger('source_option_id')->nullable();
            $t->text('option_text');
            $t->string('option_image', 500)->nullable();
            $t->unsignedInteger('position')->default(0);
            $t->boolean('is_correct')->default(false);
            $t->timestamps();
        });
        Schema::create('exam_answers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_attempt_id')->nullable();
            $t->unsignedBigInteger('attempt_question_id')->nullable();
            $t->unsignedBigInteger('participant_id');
            $t->unsignedBigInteger('question_id');
            $t->unsignedBigInteger('selected_option_id')->nullable();
            $t->unsignedBigInteger('selected_attempt_option_id')->nullable();
            $t->text('essay_answer')->nullable();
            $t->boolean('is_correct')->nullable();
            $t->decimal('score', 10, 2)->nullable();
            $t->string('grade_status', 20)->nullable();
            $t->unsignedBigInteger('graded_by')->nullable();
            $t->dateTime('graded_at')->nullable();
            $t->text('feedback')->nullable();
            $t->dateTime('answered_at');
            $t->timestamps();
        });
        Schema::create('exam_results', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('participant_id');
            $t->unsignedBigInteger('exam_attempt_id')->nullable();
            $t->decimal('total_score', 10, 2)->default(0);
            $t->unsignedInteger('correct_count')->default(0);
            $t->unsignedInteger('wrong_count')->default(0);
            $t->unsignedInteger('unanswered_count')->default(0);
            $t->decimal('percentage', 5, 2)->nullable();
            $t->string('grade', 5)->nullable();
            $t->string('status')->default('pending');
            $t->dateTime('graded_at')->nullable();
            $t->boolean('is_final')->default(false);
            $t->dateTime('finalized_at')->nullable();
            $t->timestamps();
        });
        Schema::create('grade_assessments', function (Blueprint $t) {
            $t->id();
            $t->string('source_type', 50)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->timestamps();
            $t->index(['source_type', 'source_id']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('user_id')->nullable();
            $t->string('action', 50);
            $t->string('model', 100)->nullable();
            $t->unsignedInteger('model_id')->nullable();
            $t->text('description');
            $t->string('ip_address', 45);
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    private function seedFixture(): void
    {
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'a@audit.test', 'username' => 'admin', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $tAUser = User::create(['name' => 'Guru MTK', 'email' => 'ta@audit.test', 'username' => 'ta', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $s1User = User::create(['name' => 'Siswa A', 'email' => 's1@audit.test', 'username' => 's1', 'password' => 'x', 'role_id' => $roleSiswa->id]);

        $this->teacherA = $tAUser;
        $this->studentAUser = $s1User;
        $this->studentA = Student::create(['user_id' => $s1User->id, 'name' => 'Siswa A', 'nis' => 'AUD-01']);
        $this->studentB = Student::create(['name' => 'Siswa B', 'nis' => 'AUD-02']);

        $mtk = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        $teacherRec = Teacher::create(['user_id' => $tAUser->id, 'full_name' => 'Guru MTK']);
        $year = AcademicYear::create(['name' => '2025/2026']);
        $classId = \Illuminate\Support\Facades\DB::table('classes')->insertGetId(['name' => 'X-1', 'created_at' => now(), 'updated_at' => now()]);
        TeacherAssignment::create(['teacher_id' => $teacherRec->id, 'class_id' => $classId, 'subject_id' => $mtk->id, 'academic_year_id' => $year->id]);

        $this->qMC = QuestionBank::create(['subject_id' => $mtk->id, 'question_text' => 'MC?', 'type' => 'multiple_choice', 'points' => 10, 'status' => 'approved']);
        $this->qEssay = QuestionBank::create(['subject_id' => $mtk->id, 'question_text' => 'ESAI?', 'type' => 'essay', 'points' => 20, 'status' => 'approved']);

        $this->exam = Exam::create(['subject_id' => $mtk->id, 'title' => 'Ujian Awal', 'duration_minutes' => 60, 'status' => 'draft']);
        \App\Models\Examination\ExamQuestion::create(['exam_id' => $this->exam->id, 'question_id' => $this->qMC->id, 'position' => 1, 'points' => 10]);
        \App\Models\Examination\ExamQuestion::create(['exam_id' => $this->exam->id, 'question_id' => $this->qEssay->id, 'position' => 2, 'points' => 20]);

        $this->participant = \App\Models\Examination\ExamParticipant::create([
            'exam_id' => $this->exam->id,
            'student_id' => $this->studentA->id,
            'exam_card_number' => 'AUD-CARD',
        ]);
    }

    private function makeAttempt(string $status, ?Carbon $submittedAt, bool $withEssayAnswer): int
    {
        $participant = \App\Models\Examination\ExamParticipant::where('student_id', $this->studentA->id)->first();
        $number = ExamAttempt::where('exam_participant_id', $participant->id)->count() + 1;
        $attempt = ExamAttempt::create([
            'exam_participant_id' => $participant->id,
            'exam_id' => $this->exam->id,
            'attempt_number' => $number,
            'status' => $status,
            'started_at' => now()->subMinutes(30),
            'expires_at' => now()->addMinutes(30),
            'submitted_at' => $submittedAt,
        ]);

        $aq = ExamAttemptQuestion::create([
            'exam_attempt_id' => $attempt->id,
            'source_question_id' => $this->qEssay->id,
            'question_text' => 'ESAI?',
            'question_type' => 'essay',
            'points' => 20,
            'position' => 1,
        ]);

        if ($withEssayAnswer) {
            ExamAnswer::create([
                'exam_attempt_id' => $attempt->id,
                'attempt_question_id' => $aq->id,
                'participant_id' => $participant->id,
                'question_id' => $this->qEssay->id,
                'essay_answer' => 'RAHASIA JAWABAN',
                'grade_status' => 'pending_manual',
                'answered_at' => now(),
            ]);
        }

        return $attempt->id;
    }

    // -----------------------------------------------------------------
    // Exam lifecycle
    // -----------------------------------------------------------------

    public function test_exam_updated_title_change_creates_audit(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/exams/{$this->exam->id}", ['title' => 'Ujian Sesudah'])->assertStatus(200);

        $audit = AuditLog::where('action', 'exam_updated')->where('model_id', $this->exam->id)->firstOrFail();
        $this->assertSame($this->admin->id, $audit->user_id);
        $desc = json_decode($audit->description, true);
        $this->assertContains('title', $desc['changed_fields']);
    }

    public function test_exam_publish_creates_audit_with_status_change(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/exams/{$this->exam->id}", ['status' => 'published'])->assertStatus(200);

        $audit = AuditLog::where('action', 'exam_updated')->where('model_id', $this->exam->id)->firstOrFail();
        $desc = json_decode($audit->description, true);
        $this->assertSame('draft', $desc['status_before']);
        $this->assertSame('published', $desc['status_after']);
        $this->assertContains('status', $desc['changed_fields']);
    }

    // -----------------------------------------------------------------
    // Participants
    // -----------------------------------------------------------------

    public function test_participant_create_update_delete_creates_audit(): void
    {
        Sanctum::actingAs($this->admin);

        $created = $this->postJson('/api/exam-participants', [
            'exam_id' => $this->exam->id,
            'student_id' => $this->studentB->id,
            'status' => 'registered',
            'exam_card_number' => 'AUD-CARD-2',
        ])->assertStatus(201)->json('data.id');

        $auditCreated = AuditLog::where('action', 'exam_participant_created')->where('model_id', $created)->firstOrFail();
        $this->assertSame($this->admin->id, $auditCreated->user_id);
        $this->assertSame($this->exam->id, json_decode($auditCreated->description, true)['exam_id']);

        $this->putJson("/api/exam-participants/{$created}", ['exam_card_number' => 'AUD-CARD-UPDATED'])->assertStatus(200);
        $auditUpdated = AuditLog::where('action', 'exam_participant_updated')->where('model_id', $created)->firstOrFail();
        $changes = json_decode($auditUpdated->description, true)['changes'];
        $this->assertContains('exam_card_number', array_column($changes, 'field'));

        $this->deleteJson("/api/exam-participants/{$created}")->assertStatus(200);
        $auditDeleted = AuditLog::where('action', 'exam_participant_deleted')->where('model_id', $created)->firstOrFail();
        $this->assertSame($this->exam->id, json_decode($auditDeleted->description, true)['exam_id']);
    }

    public function test_participant_with_attempt_delete_rejected_leaves_no_audit(): void
    {
        $this->makeAttempt('submitted', now(), false);
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/exam-participants/{$this->participant->id}")->assertStatus(422);
        $this->assertSame(0, AuditLog::where('action', 'exam_participant_deleted')->count(), 'rejected delete must not create an audit event');
    }

    public function test_non_admin_participant_delete_is_forbidden_and_unaudited(): void
    {
        Sanctum::actingAs($this->studentAUser);
        $this->deleteJson("/api/exam-participants/{$this->participant->id}")->assertStatus(403);
        $this->assertSame(0, AuditLog::where('action', 'exam_participant_deleted')->count());
    }

    // -----------------------------------------------------------------
    // Results (generate + recompute)
    // -----------------------------------------------------------------

    public function test_admin_result_generated_and_recomputed_create_audit(): void
    {
        $attemptId = $this->makeAttempt('submitted', now(), false);
        Sanctum::actingAs($this->admin);

        $created = $this->postJson('/api/exam-results', ['exam_attempt_id' => $attemptId])->assertStatus(201);
        $resultId = $created->json('data.id');

        $auditGenerated = AuditLog::where('action', 'exam_result_generated')->where('model_id', $resultId)->firstOrFail();
        $desc = json_decode($auditGenerated->description, true);
        $this->assertSame($attemptId, $desc['exam_attempt_id']);
        $this->assertTrue(array_key_exists('percentage', $desc));

        $this->putJson("/api/exam-results/{$resultId}", [])->assertStatus(200);
        $auditRecomputed = AuditLog::where('action', 'exam_result_recomputed')->where('model_id', $resultId)->firstOrFail();
        $stats = json_decode($auditRecomputed->description, true);
        $this->assertArrayHasKey('score_before', $stats);
        $this->assertArrayHasKey('score_after', $stats);
    }

    // -----------------------------------------------------------------
    // Essay grading + privacy
    // -----------------------------------------------------------------

    public function test_essay_grading_and_regrade_audit_before_after_without_sensitive_text(): void
    {
        $attemptId = $this->makeAttempt('submitted', now(), true);
        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->value('id');

        Sanctum::actingAs($this->teacherA);
        $this->putJson("/api/teacher/exam-grading/answers/{$answerId}", ['score' => 12])->assertStatus(200);
        $this->putJson("/api/teacher/exam-grading/answers/{$answerId}", ['score' => 8, 'feedback' => 'BAGUS SEKALI'])->assertStatus(200);

        $audits = AuditLog::where('action', 'exam_essay_graded')->where('model_id', $answerId)->orderBy('id')->get();
        $this->assertCount(2, $audits);

        $first = json_decode($audits[0]->description, true);
        $this->assertNull($first['score_before']);
        $this->assertSame(12.0, (float) $first['score_after']);
        $this->assertFalse($first['feedback_present']);

        $second = json_decode($audits[1]->description, true);
        $this->assertSame(12.0, (float) $second['score_before']);
        $this->assertSame(8.0, (float) $second['score_after']);
        $this->assertTrue($second['feedback_present']);

        // Privacy: no answer text, no feedback text, no answer-key flag.
        $encoded = json_encode($audits->pluck('description'));
        $this->assertStringNotContainsString('RAHASA', $encoded);
        $this->assertStringNotContainsString('RAHASIA', $encoded);
        $this->assertStringNotContainsString('BAGUS SEKALI', $encoded);
        $this->assertStringNotContainsString('"is_correct"', json_encode(AuditLog::all()->pluck('description')));
        $this->assertStringNotContainsString('password', json_encode(AuditLog::all()->pluck('description')));
    }
}