<?php

namespace Tests\Feature;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassSubject;
use App\Models\Academic\Grade;
use App\Models\Academic\GradeAssessment;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamAnswer;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamAttemptQuestion;
use App\Models\Examination\ExamParticipant;
use App\Models\Examination\ExamQuestion;
use App\Models\Examination\ExamResult;
use App\Models\Examination\QuestionBank;
use App\Models\Examination\QuestionOption;
use App\Models\Staff\Teacher;
use App\Models\Staff\TeacherAssignment;
use App\Models\Students\Student;
use App\Models\System\AuditLog;
use App\Models\System\Role;
use App\Models\System\User;
use App\Services\Examination\ExamScoringService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B20 Stage B — Exam result finalization (B20-F1).
 *
 * Hermetic suite; builds its own schema on the default (sqlite :memory:)
 * connection in setUp — never touches live MySQL.
 *
 * Verifies the finalization contract:
 *   - finalize endpoint marks a result final (idempotent, stamped)
 *   - finalized results are immutable: recompute/delete/re-score rejected
 *   - teacher essay regrade of a finalized result is denied
 *   - effective result prefers a finalized result over newer submissions
 *   - finalized results remain syncable to academic grades
 *   - bounded `exam_result_finalized` audit, no mutation audit on rejection
 */
class ExamResultFinalizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->seedFixture();
    }

    private function buildSchema(): void
    {
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
        Schema::create('academic_years', function (Blueprint $t) {
            $t->id();
            $t->string('name');
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
        Schema::create('classes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('level')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('class_subjects', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('academic_year_id')->nullable();
            $t->timestamps();
        });
        Schema::create('teachers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('full_name')->nullable();
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
            $t->text('option_text');
            $t->boolean('is_correct')->default(false);
            $t->timestamps();
        });
        Schema::create('exams', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('academic_year_id')->nullable();
            $t->unsignedBigInteger('semester_id')->nullable();
            $t->string('exam_type')->nullable();
            $t->string('title');
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
            $t->unsignedBigInteger('blueprint_item_id')->nullable();
            $t->unsignedInteger('position')->default(0);
            $t->unsignedInteger('points')->default(1);
            $t->timestamps();
        });
        Schema::create('exam_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('start_time');
            $t->string('end_time');
            $t->timestamps();
        });
        Schema::create('exam_schedules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_id');
            $t->unsignedBigInteger('room_id');
            $t->unsignedBigInteger('session_id');
            $t->date('exam_date');
            $t->unsignedBigInteger('supervisor_id')->nullable();
            $t->dateTime('start_datetime')->nullable();
            $t->dateTime('end_datetime')->nullable();
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
        Schema::create('grades', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('class_id');
            $t->string('type');
            $t->decimal('score', 5, 2)->default(0);
            $t->string('semester', 10)->nullable();
            $t->string('academic_year', 20)->nullable();
            $t->unsignedBigInteger('semester_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->boolean('is_final')->default(false);
            $t->dateTime('finalized_at')->nullable();
            $t->unsignedBigInteger('finalized_by')->nullable();
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
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@b20.test', 'username' => 'admin', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $teacherAUser = User::create(['name' => 'Guru MTK', 'email' => 'ta@b20.test', 'username' => 'ta', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $userA = User::create(['name' => 'Siswa A', 'email' => 'a@b20.test', 'username' => 'a', 'password' => 'x', 'role_id' => $roleSiswa->id]);

        $this->teacherA = $teacherAUser;
        $this->studentA = Student::create(['user_id' => $userA->id, 'name' => 'Siswa A', 'nis' => 'A01']);

        $this->ay = AcademicYear::create(['name' => '2025/2026', 'is_active' => true]);
        $this->semester = Semester::create(['academic_year_id' => $this->ay->id, 'name' => '1', 'is_active' => true]);
        $this->class1 = SchoolClass::create(['name' => '7A', 'level' => '7']);
        $mtk = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);

        ClassSubject::create(['class_id' => $this->class1->id, 'subject_id' => $mtk->id, 'academic_year_id' => $this->ay->id]);
        $this->studentA->update(['class_id' => $this->class1->id]);

        $teacherARec = Teacher::create(['user_id' => $teacherAUser->id, 'full_name' => 'Guru MTK']);
        TeacherAssignment::create(['teacher_id' => $teacherARec->id, 'class_id' => $this->class1->id, 'subject_id' => $mtk->id, 'academic_year_id' => $this->ay->id]);

        $this->qMC = $this->makeQuestion($mtk, 'MC?', 10);
        $this->qEssay = $this->makeQuestion($mtk, 'Essay?', 20, 'essay');

        $this->examMC = $this->makeExam($mtk, 'MC UTS', 'uts', $this->qMC->id, 10, 2);
        $this->examEssay = $this->makeExam($mtk, 'Essay UTS', 'uts', $this->qEssay->id, 20);

        $this->participantMC = ExamParticipant::create(['exam_id' => $this->examMC->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-MC', 'status' => 'registered', 'login_allowed' => true]);
        $this->participantEssay = ExamParticipant::create(['exam_id' => $this->examEssay->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-ES', 'status' => 'registered', 'login_allowed' => true]);
    }

    private function makeQuestion(Subject $subject, string $text, int $points, string $type = 'multiple_choice'): QuestionBank
    {
        $q = QuestionBank::create(['subject_id' => $subject->id, 'question_text' => $text, 'type' => $type, 'difficulty' => 'medium', 'points' => $points, 'status' => 'approved']);
        if ($type !== 'essay') {
            QuestionOption::create(['question_id' => $q->id, 'option_text' => 'A', 'is_correct' => true]);
            QuestionOption::create(['question_id' => $q->id, 'option_text' => 'B', 'is_correct' => false]);
        }

        return $q;
    }

    private function makeExam(Subject $subject, string $title, string $type, int $questionId, int $points, int $maxAttempts = 1): Exam
    {
        $exam = Exam::create([
            'subject_id' => $subject->id,
            'class_id' => $this->class1->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $this->semester->id,
            'exam_type' => $type,
            'title' => $title,
            'duration_minutes' => 30,
            'max_attempts' => $maxAttempts,
            'status' => 'published',
        ]);
        ExamQuestion::create(['exam_id' => $exam->id, 'question_id' => $questionId, 'position' => 1, 'points' => $points]);

        return $exam;
    }

    private function startAsA(int $examId): int
    {
        Sanctum::actingAs($this->studentA->user);
        $res = $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $examId]);
        $res->assertStatus(200);

        return (int) $res->json('data.id');
    }

    private function submitAnsweredMC(int $examId, int $participantId, int $sourceQid): int
    {
        $attemptId = $this->startAsA($examId);
        $aq = ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->where('source_question_id', $sourceQid)->with('options')->firstOrFail();
        $correct = $aq->options->firstWhere('is_correct', true)->id;
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$aq->id}", ['selected_option_id' => $correct])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertStatus(200);

        return (int) ExamResult::where('participant_id', $participantId)->latest('id')->value('id');
    }

    private function adminFinalize(int $resultId)
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/exam-results/{$resultId}/finalize");
    }

    private function adminSync(int $resultId)
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/exam-results/{$resultId}/grade-sync");
    }

    // -----------------------------------------------------------------
    // Finalize endpoint
    // -----------------------------------------------------------------

    public function test_finalize_marks_result_final_with_stamps(): void
    {
        $resultId = $this->submitAnsweredMC($this->examMC->id, $this->participantMC->id, $this->qMC->id);

        $res = $this->adminFinalize($resultId);
        $res->assertStatus(200)->assertJsonPath('data.is_final', true);
        $data = $res->json('data');
        $this->assertNotNull($data['finalized_at']);
        $this->assertSame(1, AuditLog::where('action', 'exam_result_finalized')->where('user_id', $this->admin->id)->where('model_id', $resultId)->count());

        $result = ExamResult::find($resultId);
        $this->assertTrue((bool) $result->is_final);
        $this->assertNotNull($result->finalized_at);
        $this->assertTrue($result->finalized_at->between(now()->subMinute(), now()->addMinute()), 'finalized_at must be stamped at finalize time');
        $this->assertSame(1, AuditLog::where('action', 'exam_result_finalized')->where('user_id', $this->admin->id)->where('model_id', $resultId)->count(), 'actor identity rides on the audit user_id');
    }

    public function test_finalize_is_idempotent_and_preserves_original_stamps(): void
    {
        $resultId = $this->submitAnsweredMC($this->examMC->id, $this->participantMC->id, $this->qMC->id);

        $this->adminFinalize($resultId)->assertStatus(200);
        $first = ExamResult::find($resultId)->only(['finalized_at']);

        $this->adminFinalize($resultId)->assertStatus(200)->assertJsonPath('message', 'Exam result is already finalized.');

        $after = ExamResult::find($resultId)->only(['finalized_at']);
        $this->assertSame($first['finalized_at']?->toIso8601String(), $after['finalized_at']?->toIso8601String(), 'idempotent finalize must not rewrite the timestamp');
        $this->assertSame(2, AuditLog::where('action', 'exam_result_finalized')->where('model_id', $resultId)->where('user_id', $this->admin->id)->count(), 'every finalize audit row must carry the actor identity on user_id');
    }

    public function test_finalized_result_is_readable_as_finalized(): void
    {
        $resultId = $this->submitAnsweredMC($this->examMC->id, $this->participantMC->id, $this->qMC->id);
        $this->adminFinalize($resultId)->assertStatus(200);

        Sanctum::actingAs($this->admin);
        $res = $this->getJson("/api/exam-results/{$resultId}")->assertStatus(200);
        $this->assertTrue($res->json('data.is_final'));
        $this->assertNotNull($res->json('data.finalized_at'));
        $this->assertSame(1, AuditLog::where('action', 'exam_result_finalized')->where('user_id', $this->admin->id)->where('model_id', $resultId)->count());
    }

    public function test_finalize_requires_admin(): void
    {
        $resultId = $this->submitAnsweredMC($this->examMC->id, $this->participantMC->id, $this->qMC->id);

        Sanctum::actingAs($this->studentA->user);
        $this->postJson("/api/exam-results/{$resultId}/finalize")->assertStatus(403);

        Sanctum::actingAs($this->teacherA);
        $this->postJson("/api/exam-results/{$resultId}/finalize")->assertStatus(403);

        $this->assertFalse((bool) ExamResult::find($resultId)->is_final, 'non-admin finalize must not touch the row');
    }

    public function test_finalize_missing_result_returns_404(): void
    {
        $this->adminFinalize(999)->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Immutability
    // -----------------------------------------------------------------

    public function test_recompute_finalized_result_rejected_and_unaudited(): void
    {
        $resultId = $this->submitAnsweredMC($this->examMC->id, $this->participantMC->id, $this->qMC->id);
        $this->adminFinalize($resultId)->assertStatus(200);

        $result = ExamResult::find($resultId);
        $snapshot = $result->only(['total_score', 'percentage', 'grade', 'status', 'is_final', 'finalized_at', 'updated_at']);
        $snapshot['finalized_at'] = $snapshot['finalized_at']?->toIso8601String();
        $snapshot['updated_at'] = $snapshot['updated_at']?->toIso8601String();

        Sanctum::actingAs($this->admin);
        $res = $this->putJson("/api/exam-results/{$resultId}", []);
        $res->assertStatus(422)->assertJsonPath('message', 'Result is finalized and cannot be modified.');

        $after = ExamResult::find($resultId)->only(['total_score', 'percentage', 'grade', 'status', 'is_final', 'finalized_at', 'updated_at']);
        $after['finalized_at'] = $after['finalized_at']?->toIso8601String();
        $after['updated_at'] = $after['updated_at']?->toIso8601String();
        $this->assertSame($snapshot, $after, 'rejected recompute must not mutate the result row');

        $this->assertSame(0, AuditLog::where('action', 'exam_result_recomputed')->count(), 'rejected recompute must not write a recompute audit');
    }

    public function test_recompute_non_finalized_result_still_allowed(): void
    {
        $resultId = $this->submitAnsweredMC($this->examMC->id, $this->participantMC->id, $this->qMC->id);

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/exam-results/{$resultId}", [])->assertStatus(200);

        $result = ExamResult::find($resultId);
        $this->assertFalse((bool) $result->is_final);
        $this->assertNull($result->finalized_at);
        $this->assertSame(1, AuditLog::where('action', 'exam_result_recomputed')->count(), 'non-finalized recompute keeps its audit event');
    }

    public function test_destroy_finalized_result_rejected(): void
    {
        $resultId = $this->submitAnsweredMC($this->examMC->id, $this->participantMC->id, $this->qMC->id);
        $this->adminFinalize($resultId)->assertStatus(200);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/exam-results/{$resultId}")->assertStatus(422)->assertJsonPath('message', 'Result is finalized and cannot be deleted.');

        $this->assertNotNull(ExamResult::find($resultId), 'finalized result must not be deleted');
        $this->assertTrue((bool) ExamResult::find($resultId)->is_final);
        $this->assertSame(0, Grade::count(), 'no grade row is touched by a rejected delete');
    }

    public function test_score_attempt_does_not_overwrite_finalized_result(): void
    {
        $resultId = $this->submitAnsweredMC($this->examMC->id, $this->participantMC->id, $this->qMC->id);
        $attemptId = ExamResult::find($resultId)->exam_attempt_id;
        $this->adminFinalize($resultId)->assertStatus(200);

        // Simulate a late change to the answer set; scoring must not react.
        $aq = ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->where('source_question_id', $this->qMC->id)->with('options')->firstOrFail();
        $answer = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aq->id)->firstOrFail();
        $wrong = $aq->options->firstWhere('is_correct', false)->id;
        $answer->selected_attempt_option_id = $wrong;
        $answer->is_correct = false;
        $answer->save();

        $snapshot = ExamResult::find($resultId)->toArray();

        $payload = app(ExamScoringService::class)->scoreAttempt(ExamAttempt::find($attemptId));
        $this->assertSame(100.0, (float) $payload['percentage'], 'scoreAttempt must return the existing finalized value');
        $this->assertSame(1, ExamResult::where('exam_attempt_id', $attemptId)->count(), 'scoring must not create a duplicate result row');

        $after = ExamResult::find($resultId)->toArray();
        $this->assertSame($snapshot['total_score'], $after['total_score'], 'score must stay immutable under re-score');
        $this->assertSame($snapshot['percentage'], $after['percentage'], 'percentage must stay immutable under re-score');
        $this->assertSame($snapshot['is_final'], $after['is_final']);
        $this->assertSame($snapshot['finalized_at'], $after['finalized_at'], 'finalization metadata must stay immutable under re-score');

        $answer->refresh();
        $this->assertFalse((bool) $answer->is_correct, 'scoring must not recompute answers for a finalized result');
    }

    public function test_teacher_essay_regrade_of_finalized_result_rejected(): void
    {
        $attemptId = $this->startAsA($this->examEssay->id);
        $aq = ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->where('question_type', 'essay')->firstOrFail();
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$aq->id}", ['essay_answer' => 'jawaban'])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertStatus(200);

        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->value('id');
        $resultId = ExamResult::where('exam_attempt_id', $attemptId)->value('id');

        Sanctum::actingAs($this->teacherA);
        $this->putJson("/api/teacher/exam-grading/answers/{$answerId}", ['score' => 16])->assertStatus(200);
        $this->assertSame(80.0, (float) ExamResult::find($resultId)->percentage, '16/20 grades to 80%');

        $this->adminFinalize($resultId)->assertStatus(200);

        Sanctum::actingAs($this->teacherA);
        $res = $this->putJson("/api/teacher/exam-grading/answers/{$answerId}", ['score' => 12]);
        $res->assertStatus(422)->assertJsonPath('message', 'Result is finalized and cannot be modified.');

        $answer = ExamAnswer::find($answerId);
        $this->assertSame(16, (int) $answer->score, 'rejected regrade must not change the essay score');
        $this->assertSame('manually_graded', $answer->grade_status);

        $result = ExamResult::find($resultId);
        $this->assertSame(80.0, (float) $result->percentage, 'result must stay at the finalized value');
        $this->assertTrue((bool) $result->is_final);

        $this->assertSame(1, AuditLog::where('action', 'exam_essay_graded')->count(), 'rejected regrade must not write a grading audit');
    }

    // -----------------------------------------------------------------
    // Effective result semantics
    // -----------------------------------------------------------------

    public function test_effective_result_prefers_finalized_over_newer_submitted(): void
    {
        $result1 = $this->submitAnsweredMC($this->examMC->id, $this->participantMC->id, $this->qMC->id);
        $this->assertSame(1, ExamAttempt::where('exam_participant_id', $this->participantMC->id)->count());
        $this->adminFinalize($result1)->assertStatus(200);

        // Second attempt, submitted AFTER finalization, must not displace the final.
        $attempt2 = $this->startAsA($this->examMC->id);
        $aq = ExamAttemptQuestion::where('exam_attempt_id', $attempt2)->where('source_question_id', $this->qMC->id)->with('options')->firstOrFail();
        $correct = $aq->options->firstWhere('is_correct', true)->id;
        $this->putJson("/api/student/exam-attempts/{$attempt2}/answers/{$aq->id}", ['selected_option_id' => $correct])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attempt2}/submit")->assertStatus(200);

        $result2 = ExamResult::where('participant_id', $this->participantMC->id)->latest('id')->value('id');
        $this->assertNotSame($result1, $result2, 'second submission must produce its own result row');
        $this->assertFalse((bool) ExamResult::find($result2)->is_final);

        $scoring = app(ExamScoringService::class);
        $effective = $scoring->effectiveResult($this->participantMC->id);
        $this->assertNotNull($effective);
        $this->assertSame($result1, $effective->id, 'finalized result wins even over a newer submitted attempt');

        $this->assertTrue($scoring->isEffectiveResult(ExamResult::find($result1)), 'the finalized result is the effective one');
        $this->assertFalse($scoring->isEffectiveResult(ExamResult::find($result2)), 'the newer non-finalized result is not effective');
    }

    public function test_effective_result_non_finalized_keeps_latest_submitted_semantics(): void
    {
        $result1 = $this->submitAnsweredMC($this->examMC->id, $this->participantMC->id, $this->qMC->id);
        $this->assertFalse((bool) ExamResult::find($result1)->is_final);

        $attempt2 = $this->startAsA($this->examMC->id);
        $aq = ExamAttemptQuestion::where('exam_attempt_id', $attempt2)->where('source_question_id', $this->qMC->id)->with('options')->firstOrFail();
        $correct = $aq->options->firstWhere('is_correct', true)->id;
        $this->putJson("/api/student/exam-attempts/{$attempt2}/answers/{$aq->id}", ['selected_option_id' => $correct])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attempt2}/submit")->assertStatus(200);

        $result2 = ExamResult::where('participant_id', $this->participantMC->id)->latest('id')->value('id');

        $scoring = app(ExamScoringService::class);
        $this->assertSame($result2, $scoring->effectiveResult($this->participantMC->id)->id, 'without finalization the latest submitted attempt stays effective');
    }

    // -----------------------------------------------------------------
    // Grade integration + audit
    // -----------------------------------------------------------------

    public function test_finalized_result_remains_grade_syncable(): void
    {
        $resultId = $this->submitAnsweredMC($this->examMC->id, $this->participantMC->id, $this->qMC->id);
        $this->adminFinalize($resultId)->assertStatus(200);

        $this->adminSync($resultId)->assertStatus(200);

        $grade = Grade::where('student_id', $this->studentA->id)->where('type', 'uts')->firstOrFail();
        $this->assertSame(100.0, (float) $grade->score);
        $this->assertFalse((bool) $grade->is_final, 'sync must not finalize the academic grade itself');

        $assessment = GradeAssessment::where('source_type', 'exam_result')->where('source_id', $resultId)->firstOrFail();
        $this->assertSame($resultId, $assessment->source_id);
    }

    public function test_finalize_writes_bounded_audit_event(): void
    {
        $resultId = $this->submitAnsweredMC($this->examMC->id, $this->participantMC->id, $this->qMC->id);

        $this->adminFinalize($resultId)->assertStatus(200);
        $this->adminFinalize($resultId)->assertStatus(200);

        $events = AuditLog::where('action', 'exam_result_finalized')->where('model_id', $resultId)->orderBy('id')->get();
        $this->assertCount(2, $events);

        $first = json_decode($events->get(0)->description, true);
        $this->assertSame($this->admin->id, $events->get(0)->user_id);
        $this->assertSame($resultId, $first['result_id']);
        $this->assertTrue($first['was_already_final'] === false);
        $this->assertNotContains('essay', array_keys($first));
        $this->assertNotContains('feedback', array_keys($first));
        $this->assertNotContains('is_correct', array_keys($first));
        $this->assertNotContains('token', array_keys($first));
        $this->assertStringNotContainsString('essay_answer', $events->get(0)->description);
        $this->assertStringNotContainsString('password', $events->get(0)->description);

        $second = json_decode($events->get(1)->description, true);
        $this->assertTrue($second['was_already_final'] === true, 'idempotent finalize is still recorded as an explicit admin action');
    }

    public function test_rejected_delete_creates_no_mutation_audit(): void
    {
        $resultId = $this->submitAnsweredMC($this->examMC->id, $this->participantMC->id, $this->qMC->id);
        $this->adminFinalize($resultId)->assertStatus(200);

        $auditBefore = AuditLog::count();

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/exam-results/{$resultId}")->assertStatus(422);

        $this->assertSame($auditBefore, AuditLog::count(), 'rejected delete must not write any audit event');
        $this->assertNotNull(ExamResult::find($resultId));
        $this->assertSame(0, Grade::count());
    }
}
