<?php

namespace Tests\Feature;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassSubject;
use App\Models\Academic\Grade;
use App\Models\Academic\GradeAssessment;
use App\Models\Academic\ReportCard;
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
use App\Models\Examination\ExamSchedule;
use App\Models\Examination\ExamSession;
use App\Models\Examination\QuestionBank;
use App\Models\Examination\QuestionOption;
use App\Models\Staff\Teacher;
use App\Models\Staff\TeacherAssignment;
use App\Models\Students\Student;
use App\Models\Students\StudentHistory;
use App\Models\System\AuditLog;
use App\Models\System\Permission;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2I — ExamResult -> Academic Grade integration.
 *
 * Hermetic suite; builds its own schema on the default (sqlite :memory:)
 * connection in setUp — never touches live MySQL.
 */
class ExamGradeIntegrationTest extends TestCase
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
        Schema::create('student_histories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->string('status')->default('naik');
            $t->text('notes')->nullable();
            $t->boolean('is_final')->default(false);
            $t->dateTime('finalized_at')->nullable();
            $t->unsignedBigInteger('finalized_by')->nullable();
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
    }

    private function seedFixture(): void
    {
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@2i.test', 'username' => 'admin', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $teacherAUser = User::create(['name' => 'Guru MTK', 'email' => 'ta@2i.test', 'username' => 'ta', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $teacherBUser = User::create(['name' => 'Guru IPA', 'email' => 'tb@2i.test', 'username' => 'tb', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $userA = User::create(['name' => 'Siswa A', 'email' => 'a@2i.test', 'username' => 'a', 'password' => 'x', 'role_id' => $roleSiswa->id]);

        $this->teacherA = $teacherAUser;
        $this->teacherB = $teacherBUser;
        $this->studentA = Student::create(['user_id' => $userA->id, 'name' => 'Siswa A', 'nis' => 'A01']);

        $this->ay = AcademicYear::create(['name' => '2025/2026', 'is_active' => true]);
        $this->semester = Semester::create(['academic_year_id' => $this->ay->id, 'name' => '1', 'is_active' => true]);
        $this->class1 = SchoolClass::create(['name' => '7A', 'level' => '7']);
        $mtk = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        $ipa = Subject::create(['code' => 'IPA', 'name' => 'IPA']);

        ClassSubject::create(['class_id' => $this->class1->id, 'subject_id' => $mtk->id, 'academic_year_id' => $this->ay->id]);
        $this->studentA->update(['class_id' => $this->class1->id]);

        $teacherARec = Teacher::create(['user_id' => $teacherAUser->id, 'full_name' => 'Guru MTK']);
        $teacherBRec = Teacher::create(['user_id' => $teacherBUser->id, 'full_name' => 'Guru IPA']);
        TeacherAssignment::create(['teacher_id' => $teacherARec->id, 'class_id' => $this->class1->id, 'subject_id' => $mtk->id, 'academic_year_id' => $this->ay->id]);
        TeacherAssignment::create(['teacher_id' => $teacherBRec->id, 'class_id' => $this->class1->id, 'subject_id' => $ipa->id, 'academic_year_id' => $this->ay->id]);

        $this->qMC = $this->makeQuestion($mtk, 'MC?', 10);
        $this->qEssay = $this->makeQuestion($mtk, 'Essay?', 20, 'essay');

        $this->examMC = $this->makeExam($mtk, 'MC UTS', 'uts', $this->qMC->id, 10);
        $this->examEssay = $this->makeExam($mtk, 'Essay UTS', 'uts', $this->qEssay->id, 20);
        $this->examSumatif = $this->makeExam($mtk, 'Sumatif', 'sumatif', $this->qMC->id, 10);
        $this->examLegacy = Exam::create(['subject_id' => $mtk->id, 'title' => 'Legacy', 'duration_minutes' => 30, 'status' => 'published']); // no period

        $this->participantMC = ExamParticipant::create(['exam_id' => $this->examMC->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-MC', 'status' => 'registered', 'login_allowed' => true]);
        $this->participantEssay = ExamParticipant::create(['exam_id' => $this->examEssay->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-ES', 'status' => 'registered', 'login_allowed' => true]);
        $this->participantSumatif = ExamParticipant::create(['exam_id' => $this->examSumatif->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-SM', 'status' => 'registered', 'login_allowed' => true]);
        $this->participantLegacy = ExamParticipant::create(['exam_id' => $this->examLegacy->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-LG', 'status' => 'registered', 'login_allowed' => true]);
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

    private function makeExam(Subject $subject, string $title, string $type, int $questionId, int $points): Exam
    {
        $exam = Exam::create([
            'subject_id' => $subject->id,
            'class_id' => $this->class1->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $this->semester->id,
            'exam_type' => $type,
            'title' => $title,
            'duration_minutes' => 30,
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

    private function correctOption(int $attemptId, int $sourceQid): array
    {
        $aq = ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->where('source_question_id', $sourceQid)->with('options')->firstOrFail();

        return ['aq' => $aq->id, 'correct' => $aq->options->firstWhere('is_correct', true)->id];
    }

    private function adminSync(int $resultId, array $body = [])
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/exam-results/{$resultId}/grade-sync", $body);
    }

    // -----------------------------------------------------------------
    // Result integrity
    // -----------------------------------------------------------------

    public function test_one_authoritative_result_per_attempt(): void
    {
        // Answer + submit MC exam.
        $attemptId = $this->startAsA($this->examMC->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertStatus(200); // idempotent re-submit

        $this->assertSame(1, ExamResult::where('participant_id', $this->participantMC->id)->count(), 'repeated scoring never duplicates the result');
        $result = ExamResult::where('participant_id', $this->participantMC->id)->first();
        $this->assertSame($attemptId, $result->exam_attempt_id);
        $this->assertSame($this->participantMC->id, $result->participant_id);
    }

    // -----------------------------------------------------------------
    // Grade synchronization
    // -----------------------------------------------------------------

    public function test_eligible_result_creates_academic_grade(): void
    {
        $attemptId = $this->startAsA($this->examMC->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $res = $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $resultId = ExamResult::where('participant_id', $this->participantMC->id)->value('id');

        $adminResponse = $this->adminSync($resultId);
        $adminResponse->assertStatus(200);

        $grade = Grade::where('student_id', $this->studentA->id)->where('type', 'uts')->first();
        $this->assertNotNull($grade);
        $this->assertSame($this->studentA->id, $grade->student_id);
        $this->assertSame($this->examMC->subject_id, $grade->subject_id);
        $this->assertSame($this->class1->id, $grade->class_id);
        $this->assertSame('uts', $grade->type);
        $this->assertSame($this->semester->id, $grade->semester_id);
        $this->assertSame($this->ay->id, $grade->academic_year_id);
        $this->assertSame(100.0, (float) $grade->score, 'score = result percentage (server-derived)');

        // Phase 2K source tracing lives on the assessment.
        $assessment = GradeAssessment::where('source_type', 'exam_result')->where('source_id', $resultId)->first();
        $this->assertNotNull($assessment);
        $this->assertSame($this->studentA->id, $assessment->student_id);
        $this->assertSame($this->examMC->subject_id, $assessment->subject_id);
        $this->assertSame($this->class1->id, $assessment->class_id);
        $this->assertSame('uts', $assessment->assessment_category);
        $this->assertSame(1, $assessment->assessment_sequence);
        $this->assertSame($this->semester->id, $assessment->semester_id);
        $this->assertSame($this->ay->id, $assessment->academic_year_id);
        $this->assertSame(100.0, (float) $assessment->score);
        $this->assertSame($resultId, $assessment->source_id);
    }

    public function test_sync_rejected_when_published_report_card_without_grade(): void
    {
        $attemptId = $this->startAsA($this->examMC->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $resultId = ExamResult::where('participant_id', $this->participantMC->id)->value('id');

        ReportCard::create([
            'student_id' => $this->studentA->id,
            'class_id' => $this->class1->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $this->semester->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->adminSync($resultId)->assertStatus(422)->assertJson(['success' => false, 'data' => null]);

        $this->assertSame(0, Grade::count(), 'no grade row created into a published-card slot');
        $this->assertSame(0, GradeAssessment::count(), 'no assessment evidence written into a published-card slot');
    }

    public function test_sync_allowed_when_published_report_card_is_other_class(): void
    {
        $attemptId = $this->startAsA($this->examMC->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $resultId = ExamResult::where('participant_id', $this->participantMC->id)->value('id');

        $otherClass = SchoolClass::create(['name' => 'Other', 'level' => '7']);

        ReportCard::create([
            'student_id' => $this->studentA->id,
            'class_id' => $otherClass->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $this->semester->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->adminSync($resultId)->assertStatus(200);
        $this->assertSame(1, Grade::count());
    }

    public function test_repeated_sync_is_idempotent(): void
    {
        $attemptId = $this->startAsA($this->examMC->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $resultId = ExamResult::where('participant_id', $this->participantMC->id)->value('id');

        $this->adminSync($resultId)->assertStatus(200);
        $this->adminSync($resultId)->assertStatus(200);
        $this->adminSync($resultId)->assertStatus(200);

        $this->assertSame(1, Grade::count(), 'repeated sync never duplicates academic grades');
        $this->assertSame(1, GradeAssessment::count(), 'repeated sync never duplicates assessments');
        $assessment = GradeAssessment::first();
        $this->assertSame($resultId, $assessment->source_id);
    }

    public function test_client_cannot_inject_academic_identity(): void
    {
        $attemptId = $this->startAsA($this->examMC->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $resultId = ExamResult::where('participant_id', $this->participantMC->id)->value('id');

        // A malicious body must be ignored entirely; all identity is server-derived.
        $this->adminSync($resultId, [
            'period_id' => 999,
            'student_id' => 999,
            'subject_id' => 999,
            'academic_year_id' => 999,
            'semester_id' => 999,
            'class_id' => 999,
        ])->assertStatus(200);

        $grade = Grade::first();
        $this->assertSame($this->studentA->id, $grade->student_id);
        $this->assertSame($this->examMC->subject_id, $grade->subject_id);
        $this->assertSame($this->semester->id, $grade->semester_id);
        $this->assertSame($this->ay->id, $grade->academic_year_id);
    }

    // -----------------------------------------------------------------
    // Eligibility rejections
    // -----------------------------------------------------------------

    public function test_pending_result_cannot_become_grade(): void
    {
        $attemptId = $this->startAsA($this->examEssay->id);
        $aq = ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->where('question_type', 'essay')->first();
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$aq->id}", ['essay_answer' => 'jawaban'])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $resultId = ExamResult::where('participant_id', $this->participantEssay->id)->value('id');

        $this->adminSync($resultId)->assertStatus(422)->assertJsonPath('message', 'Result is not fully graded (pending manual essay grading).');
        $this->assertSame(0, Grade::count());
    }

    public function test_non_syncable_exam_type_rejected(): void
    {
        $attemptId = $this->startAsA($this->examSumatif->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $resultId = ExamResult::where('participant_id', $this->participantSumatif->id)->value('id');

        $this->adminSync($resultId)->assertStatus(422)->assertJsonPath('message', 'Exam type "sumatif" has no supported academic grade representation.');
        $this->assertSame(0, Grade::count());
    }

    public function test_legacy_exam_without_period_context_rejected(): void
    {
        $attemptId = $this->startAsA($this->examLegacy->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $resultId = ExamResult::where('participant_id', $this->participantLegacy->id)->value('id');

        // Legacy exams carry no academic context and are deliberately rejected
        // (either missing type or missing period — both are ineligible).
        $this->adminSync($resultId)->assertStatus(422);
        $this->assertSame(0, Grade::count());
    }

    public function test_legacy_result_without_attempt_rejected(): void
    {
        $result = ExamResult::create(['participant_id' => $this->participantMC->id, 'total_score' => 50, 'percentage' => 50, 'status' => 'graded']);
        $this->adminSync($result->id)->assertStatus(422);
        $this->assertSame(0, Grade::count());
    }

    // -----------------------------------------------------------------
    // Regrading policy
    // -----------------------------------------------------------------

    public function test_regrade_propagates_to_academic_grade(): void
    {
        // Essay exam, teacher grades -> result graded -> explicit sync creates grade.
        $attemptId = $this->startAsA($this->examEssay->id);
        $aq = ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->where('question_type', 'essay')->first();
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$aq->id}", ['essay_answer' => 'jawaban'])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aq->id)->value('id');
        $resultId = ExamResult::where('participant_id', $this->participantEssay->id)->value('id');

        // Teacher grades essay (16/20 = 80%).
        Sanctum::actingAs($this->teacherA);
        $this->putJson("/api/teacher/exam-grading/answers/{$answerId}", ['score' => 16])->assertStatus(200);
        // Teacher syncs to grade.
        Sanctum::actingAs($this->teacherA);
        $this->postJson("/api/teacher/exam-grading/results/{$resultId}/grade-sync")->assertStatus(200);

        $assessment = GradeAssessment::where('source_type', 'exam_result')->where('source_id', $resultId)->first();
        $this->assertNotNull($assessment);
        $grade = Grade::where('student_id', $assessment->student_id)
            ->where('subject_id', $assessment->subject_id)
            ->where('class_id', $assessment->class_id)
            ->where('type', $assessment->assessment_category)
            ->first();
        $this->assertNotNull($grade);
        $this->assertSame(80.0, (float) $grade->score);

        // Teacher regrades essay down to 12/20 = 60% -> automatic re-sync, no stale value.
        Sanctum::actingAs($this->teacherA);
        $this->putJson("/api/teacher/exam-grading/answers/{$answerId}", ['score' => 12])->assertStatus(200);

        $grade->refresh();
        $this->assertSame(60.0, (float) $grade->score, 'regrade must propagate, never leave stale academic data');
        $this->assertSame(1, Grade::count());

        // Result reflects the new score too.
        $this->assertSame(60.0, (float) ExamResult::find($resultId)->percentage);
    }

    // -----------------------------------------------------------------
    // Security
    // -----------------------------------------------------------------

    public function test_unauthenticated_rejected(): void
    {
        $this->postJson('/api/exam-results/1/grade-sync')->assertStatus(401);
        $this->postJson('/api/teacher/exam-grading/results/1/grade-sync')->assertStatus(401);
    }

    public function test_student_rejected(): void
    {
        Sanctum::actingAs($this->studentA->user);
        $this->postJson('/api/exam-results/1/grade-sync')->assertStatus(403);
        $this->postJson('/api/teacher/exam-grading/results/1/grade-sync')->assertStatus(403);
    }

    public function test_unrelated_teacher_rejected_by_404(): void
    {
        $attemptId = $this->startAsA($this->examMC->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $resultId = ExamResult::where('participant_id', $this->participantMC->id)->value('id');

        // Teacher B (IPA) cannot sync an MTK result.
        Sanctum::actingAs($this->teacherB);
        $this->postJson("/api/teacher/exam-grading/results/{$resultId}/grade-sync")->assertStatus(404);
        $this->assertSame(0, Grade::count());
    }

    public function test_authorized_teacher_syncs(): void
    {
        $attemptId = $this->startAsA($this->examMC->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $resultId = ExamResult::where('participant_id', $this->participantMC->id)->value('id');

        Sanctum::actingAs($this->teacherA);
        $this->postJson("/api/teacher/exam-grading/results/{$resultId}/grade-sync")->assertStatus(200);
        $this->assertSame(1, Grade::count());
    }

    public function test_exam_grade_sync_writes_audit(): void
    {
        $attemptId = $this->startAsA($this->examMC->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $resultId = ExamResult::where('participant_id', $this->participantMC->id)->value('id');

        Sanctum::actingAs($this->teacherA);
        $res = $this->postJson("/api/teacher/exam-grading/results/{$resultId}/grade-sync");
        $res->assertStatus(200);
        $gradeId = $res->json('data.grade_id');

        $audit = AuditLog::where('action', 'exam_grade_synced')->where('model_id', $resultId)->firstOrFail();
        $this->assertSame($this->teacherA->id, $audit->user_id, 'actor is the syncing teacher');
        $desc = json_decode($audit->description, true);
        $this->assertSame($resultId, $desc['result_id']);
        $this->assertSame($gradeId, $desc['grade_id']);
    }

    public function test_rejected_grade_sync_creates_no_audit(): void
    {
        $result = ExamResult::create(['participant_id' => $this->participantMC->id, 'total_score' => 50, 'percentage' => 50, 'status' => 'graded']);
        $this->adminSync($result->id)->assertStatus(422);

        $this->assertSame(0, Grade::count());
        $this->assertSame(0, AuditLog::where('action', 'exam_grade_synced')->count(), 'rejected sync must not leave an audit event');
    }

    // -----------------------------------------------------------------
    // Finalization semantics
    // -----------------------------------------------------------------

    public function test_non_eligible_result_never_becomes_a_grade(): void
    {
        // A pending (essay-yet-ungraded) result cannot produce an academic grade,
        // and a result with zero percentage is still a valid graded value.
        $attemptId = $this->startAsA($this->examMC->id);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $resultId = ExamResult::where('participant_id', $this->participantMC->id)->value('id');
        $this->assertSame('graded', ExamResult::find($resultId)->status); // no answers -> graded, 0%

        $this->adminSync($resultId)->assertStatus(200);
        $this->assertSame(0.0, (float) Grade::first()->score, 'a legitimately graded 0% is a valid academic value');
    }

    // -----------------------------------------------------------------
    // B20-F2 Stage B — locked-slot atomicity + classless-exam mapping
    // -----------------------------------------------------------------

    public function test_sync_locked_slot_writes_no_partial_assessment(): void
    {
        $attemptId = $this->startAsA($this->examMC->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertStatus(200);
        $resultId = ExamResult::where('participant_id', $this->participantMC->id)->value('id');

        $this->adminSync($resultId)->assertStatus(200);
        $grade = Grade::where('type', 'uts')->firstOrFail();
        $this->assertSame(100.0, (float) $grade->score);
        $grade->forceFill(['is_final' => true])->save();

        // With the slot locked, sync refuses atomically: no grade overwrite, no
        // new assessment row, no new audit (B20-F2-F6).
        $assessmentsBefore = GradeAssessment::count();
        $auditBefore = AuditLog::count();

        $this->adminSync($resultId)->assertStatus(422);

        $grade->refresh();
        $this->assertSame(100.0, (float) $grade->score, 'locked slot value untouched');
        $this->assertTrue((bool) $grade->is_final);
        $this->assertSame($assessmentsBefore, GradeAssessment::count(), 'no partial assessment row');
        $this->assertSame($auditBefore, AuditLog::count(), 'rejected sync writes no audit');
    }

    private function classlessExamResult(int $academicYearId, int $semesterId): int
    {
        $mtkId = QuestionBank::find($this->qMC->id)->subject_id;
        $exam = \App\Models\Examination\Exam::create([
            'subject_id' => $mtkId,
            'academic_year_id' => $academicYearId,
            'semester_id' => $semesterId,
            'exam_type' => 'uts',
            'class_id' => null,
            'title' => 'UTH lintas kelas',
            'duration_minutes' => 30,
            'status' => 'published',
        ]);
        \App\Models\Examination\ExamQuestion::create(['exam_id' => $exam->id, 'question_id' => $this->qMC->id, 'position' => 1, 'points' => 10]);
        $participant = \App\Models\Examination\ExamParticipant::create([
            'exam_id' => $exam->id,
            'student_id' => $this->studentA->id,
            'exam_card_number' => 'CARD-NOCLS',
            'status' => 'registered',
            'login_allowed' => true,
        ]);

        $attemptId = $this->startAsA($exam->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertStatus(200);

        return ExamResult::where('participant_id', $participant->id)->value('id');
    }

    public function test_sync_classless_exam_uses_historical_academic_year_class(): void
    {
        // Classless UTS/UAS resolves the grade slot from the student's academic-
        // year class history, not from the current home class.
        StudentHistory::create(['student_id' => $this->studentA->id, 'class_id' => $this->class1->id, 'academic_year_id' => $this->ay->id, 'status' => 'naik']);
        $resultId = $this->classlessExamResult($this->ay->id, $this->semester->id);

        $this->adminSync($resultId)->assertStatus(200);

        $grade = Grade::where('student_id', $this->studentA->id)->where('type', 'uts')->firstOrFail();
        $this->assertSame($this->class1->id, (int) $grade->class_id, 'historical academic-year class is used');
        $this->assertSame(100.0, (float) $grade->score);
        $assessment = GradeAssessment::where('source_type', 'exam_result')->where('source_id', $resultId)->firstOrFail();
        $this->assertSame($this->class1->id, (int) $assessment->class_id);
        $this->assertSame($resultId, (int) $assessment->source_id);
    }

    public function test_sync_classless_exam_uses_historical_class_not_current_home_class(): void
    {
        // Primary class-drift regression: the student currently sits in class1
        // but was in class2 during the exam's academic year — the grade must go
        // to class2 and never to the current home class.
        $mtkId = QuestionBank::find($this->qMC->id)->subject_id;
        $class2 = SchoolClass::create(['name' => '8A', 'level' => '8']);
        ClassSubject::create(['class_id' => $class2->id, 'subject_id' => $mtkId, 'academic_year_id' => $this->ay->id]);
        StudentHistory::create(['student_id' => $this->studentA->id, 'class_id' => $class2->id, 'academic_year_id' => $this->ay->id, 'status' => 'naik']);
        $this->assertNotSame($this->class1->id, (int) $class2->id);

        $resultId = $this->classlessExamResult($this->ay->id, $this->semester->id);
        $this->adminSync($resultId)->assertStatus(200);

        $grade = Grade::where('student_id', $this->studentA->id)->where('type', 'uts')->firstOrFail();
        $this->assertSame($class2->id, (int) $grade->class_id, 'target is the historical academic-year class');
        $this->assertNotSame((int) $this->studentA->class_id, (int) $grade->class_id, 'current students.class_id must never be the fallback');
        $assessment = GradeAssessment::where('source_type', 'exam_result')->where('source_id', $resultId)->firstOrFail();
        $this->assertSame($class2->id, (int) $assessment->class_id);
        $this->assertSame(0, Grade::where('class_id', $this->class1->id)->where('type', 'uts')->count(), 'no grade leaked into the current class');
    }

    public function test_sync_classless_exam_rejected_when_no_class_history(): void
    {
        // A classless exam whose academic year has no student class history is
        // rejected outright — nothing is guessed, nothing is written.
        $ay2 = AcademicYear::create(['name' => '2026/2027', 'is_active' => false]);
        $sem2 = Semester::create(['academic_year_id' => $ay2->id, 'name' => '1', 'is_active' => false]);
        $resultId = $this->classlessExamResult($ay2->id, $sem2->id);

        $this->assertSame(0, StudentHistory::where('student_id', $this->studentA->id)->where('academic_year_id', $ay2->id)->count());

        $res = $this->adminSync($resultId);
        $res->assertStatus(422)->assertJsonPath('message', 'Student has no class history for the exam academic year.');

        $this->assertSame(0, Grade::count(), 'no grade is written without a resolvable history');
        $this->assertSame(0, GradeAssessment::count(), 'no assessment is written without a resolvable history');
        $this->assertSame(0, AuditLog::where('action', 'exam_grade_synced')->count());
    }

    public function test_sync_explicit_class_exam_mismatch_rejected(): void
    {
        // Explicit-class exams keep the existing mismatch validation: a grade can
        // never land in an exam class that differs from the student's class.
        $mtkId = QuestionBank::find($this->qMC->id)->subject_id;
        $class2 = SchoolClass::create(['name' => '8A', 'level' => '8']);
        $mismatchExam = \App\Models\Examination\Exam::create([
            'subject_id' => $mtkId,
            'class_id' => $class2->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $this->semester->id,
            'exam_type' => 'uts',
            'title' => 'UTH mismatch',
            'duration_minutes' => 30,
            'status' => 'published',
        ]);
        \App\Models\Examination\ExamQuestion::create(['exam_id' => $mismatchExam->id, 'question_id' => $this->qMC->id, 'position' => 1, 'points' => 10]);
        $participant = \App\Models\Examination\ExamParticipant::create([
            'exam_id' => $mismatchExam->id,
            'student_id' => $this->studentA->id,
            'exam_card_number' => 'CARD-MM',
            'status' => 'registered',
            'login_allowed' => true,
        ]);

        $attemptId = $this->startAsA($mismatchExam->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertStatus(200);
        $resultId = ExamResult::where('participant_id', $participant->id)->value('id');

        $res = $this->adminSync($resultId);
        $res->assertStatus(422)->assertJsonPath('message', 'Exam class does not match the student home class.');

        $this->assertSame(0, Grade::count());
        $this->assertSame(0, GradeAssessment::count());
    }

    // -----------------------------------------------------------------
    // B20-F4 — exam delete / lifecycle protection
    // -----------------------------------------------------------------

    private function makeRawExam(string $status): Exam
    {
        return Exam::create([
            'subject_id' => QuestionBank::find($this->qMC->id)->subject_id,
            'title' => 'Raw '.$status,
            'duration_minutes' => 30,
            'status' => $status,
            'class_id' => $this->class1->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $this->semester->id,
            'exam_type' => 'uts',
        ]);
    }

    public function test_draft_exam_without_dependencies_can_be_soft_deleted(): void
    {
        Sanctum::actingAs($this->admin);
        $exam = $this->makeRawExam('draft');

        $this->deleteJson("/api/exams/{$exam->id}")->assertStatus(200);

        $this->assertNull(Exam::find($exam->id), 'exam no longer visible');
        $trashed = Exam::withTrashed()->find($exam->id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at, 'soft delete, not hard delete');
        $this->assertSame(0, ExamSchedule::where('exam_id', $exam->id)->count(), 'no dependent data deleted');
        $this->assertSame(0, ExamParticipant::where('exam_id', $exam->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'exam_deleted')->where('model_id', $exam->id)->count());
    }

    public function test_draft_exam_with_participant_delete_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $exam = $this->makeRawExam('draft');
        ExamParticipant::create(['exam_id' => $exam->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-D1', 'status' => 'registered']);

        $res = $this->deleteJson("/api/exams/{$exam->id}");
        $res->assertStatus(422)->assertJsonPath('message', 'Exam cannot be deleted because it has operational data.');

        $this->assertNotNull(Exam::find($exam->id), 'exam not deleted');
        $this->assertSame(1, ExamParticipant::where('exam_id', $exam->id)->count(), 'participant remains');
    }

    public function test_draft_exam_with_attempt_and_result_delete_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $exam = $this->makeRawExam('draft');
        $participant = ExamParticipant::create(['exam_id' => $exam->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-D2', 'status' => 'registered']);
        $attempt = ExamAttempt::create(['exam_participant_id' => $participant->id, 'exam_id' => $exam->id, 'attempt_number' => 1, 'status' => 'submitted', 'submitted_at' => now()]);
        ExamResult::create(['participant_id' => $participant->id, 'exam_attempt_id' => $attempt->id, 'total_score' => 100, 'percentage' => 100, 'status' => 'graded']);

        $this->deleteJson("/api/exams/{$exam->id}")->assertStatus(422);

        $this->assertNotNull(Exam::find($exam->id), 'exam not deleted');
        $this->assertSame(1, ExamAttempt::where('exam_id', $exam->id)->count(), 'attempt history remains');
        $this->assertSame(1, ExamResult::where('participant_id', $participant->id)->count(), 'result history remains');
    }

    public function test_published_exam_delete_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/exams/{$this->examMC->id}")->assertStatus(422)
            ->assertJsonPath('message', 'Operational exam cannot be deleted; archive it instead.');
        $this->assertNotNull(Exam::find($this->examMC->id), 'published exam remains visible');
    }

    public function test_ongoing_exam_delete_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $exam = $this->makeRawExam('ongoing');
        $this->deleteJson("/api/exams/{$exam->id}")->assertStatus(422)
            ->assertJsonPath('message', 'Operational exam cannot be deleted; archive it instead.');
        $this->assertNotNull(Exam::find($exam->id));
    }

    public function test_completed_exam_delete_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $exam = $this->makeRawExam('completed');
        $this->deleteJson("/api/exams/{$exam->id}")->assertStatus(422)
            ->assertJsonPath('message', 'Operational exam cannot be deleted; archive it instead.');
        $this->assertNotNull(Exam::find($exam->id));
    }

    public function test_archived_exam_delete_is_soft_never_hard_cascade(): void
    {
        Sanctum::actingAs($this->admin);
        $exam = $this->makeRawExam('archived');
        ExamParticipant::create(['exam_id' => $exam->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-ARC', 'status' => 'registered']);

        $this->deleteJson("/api/exams/{$exam->id}")->assertStatus(200);

        $trashed = Exam::withTrashed()->find($exam->id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(1, ExamParticipant::where('exam_id', $exam->id)->count(), 'soft delete never cascades participants');
    }

    public function test_published_exam_identity_subject_locked(): void
    {
        Sanctum::actingAs($this->admin);
        $newSubject = Subject::create(['code' => 'BIN2', 'name' => 'Bingo']);

        $this->putJson("/api/exams/{$this->examMC->id}", ['subject_id' => $newSubject->id])->assertStatus(422)
            ->assertJsonPath('message', 'Exam identity fields cannot be modified when the exam is not in draft status.');
        $this->assertSame($this->examMC->subject_id, Exam::find($this->examMC->id)->subject_id, 'subject unchanged');
    }

    public function test_published_exam_identity_class_locked(): void
    {
        Sanctum::actingAs($this->admin);
        $class2 = SchoolClass::create(['name' => '8A', 'level' => '8']);

        $this->putJson("/api/exams/{$this->examMC->id}", ['class_id' => $class2->id])->assertStatus(422);
        $this->assertSame($this->class1->id, (int) Exam::find($this->examMC->id)->class_id, 'class unchanged');
    }

    public function test_published_exam_identity_academic_year_locked(): void
    {
        Sanctum::actingAs($this->admin);
        $ay2 = AcademicYear::create(['name' => '2026/2027', 'is_active' => false]);

        $this->putJson("/api/exams/{$this->examMC->id}", ['academic_year_id' => $ay2->id])->assertStatus(422);
        $this->assertSame($this->ay->id, (int) Exam::find($this->examMC->id)->academic_year_id, 'academic year unchanged');
    }

    public function test_published_exam_identity_semester_locked(): void
    {
        Sanctum::actingAs($this->admin);
        $sem2 = Semester::create(['academic_year_id' => $this->ay->id, 'name' => '2', 'is_active' => false]);

        $this->putJson("/api/exams/{$this->examMC->id}", ['semester_id' => $sem2->id])->assertStatus(422);
        $this->assertSame($this->semester->id, (int) Exam::find($this->examMC->id)->semester_id, 'semester unchanged');
    }

    public function test_published_exam_identity_exam_type_locked(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/exams/{$this->examMC->id}", ['exam_type' => 'uas'])->assertStatus(422);
        $this->assertSame('uts', Exam::find($this->examMC->id)->exam_type, 'exam type unchanged');
    }

    public function test_published_exam_unrelated_field_still_editable(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/exams/{$this->examMC->id}", ['title' => 'Judul Baru UTS'])->assertStatus(200)
            ->assertJsonPath('data.title', 'Judul Baru UTS');
        $this->assertSame(1, AuditLog::where('action', 'exam_updated')->where('model_id', $this->examMC->id)->count(), 'unrelated edit still audited');
    }

    public function test_rejected_identity_update_is_atomic_and_unaudited(): void
    {
        Sanctum::actingAs($this->admin);
        $newSubject = Subject::create(['code' => 'BIN3', 'name' => 'Bango']);

        $res = $this->putJson("/api/exams/{$this->examMC->id}", ['subject_id' => $newSubject->id, 'title' => 'Boleh Berubah?']);
        $res->assertStatus(422)->assertJsonPath('message', 'Exam identity fields cannot be modified when the exam is not in draft status.');

        $exam = Exam::find($this->examMC->id);
        $this->assertSame($this->examMC->subject_id, $exam->subject_id, 'no partial identity update');
        $this->assertSame($this->examMC->title, $exam->title, 'no partial unrelated update');
        $this->assertSame(0, AuditLog::where('action', 'exam_updated')->where('model_id', $this->examMC->id)->count(), 'rejected identity change writes no update audit');
    }

    public function test_session_with_referenced_schedule_delete_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $session = ExamSession::create(['name' => 'Sesi Terpakai', 'start_time' => '08:00', 'end_time' => '10:00']);
        ExamSchedule::create(['exam_id' => $this->examMC->id, 'room_id' => 1, 'session_id' => $session->id, 'exam_date' => now()->toDateString()]);

        $this->deleteJson("/api/exam-sessions/{$session->id}")->assertStatus(422)
            ->assertJsonPath('message', 'Exam session is referenced by an exam schedule and cannot be deleted.');
        $this->assertNotNull(ExamSession::find($session->id), 'session remains');
        $this->assertSame(1, ExamSchedule::where('session_id', $session->id)->count(), 'schedule remains (no cascade)');
    }

    public function test_session_without_referenced_schedule_delete_succeeds(): void
    {
        Sanctum::actingAs($this->admin);
        $session = ExamSession::create(['name' => 'Sesi Bebas', 'start_time' => '08:00', 'end_time' => '10:00']);

        $this->deleteJson("/api/exam-sessions/{$session->id}")->assertStatus(200);
        $this->assertNull(ExamSession::find($session->id));
    }

    // -----------------------------------------------------------------
    // B20-F7 — manage-exam-results permission (teacher write)
    // -----------------------------------------------------------------

    private function syncedResultId(): int
    {
        $attemptId = $this->startAsA($this->examMC->id);
        $ref = $this->correctOption($attemptId, $this->qMC->id);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertStatus(200);

        return (int) ExamResult::where('participant_id', $this->participantMC->id)->value('id');
    }

    private function buildTeacherUser(string $roleName, ?string $permissionName): User
    {
        $role = Role::create(['name' => $roleName]);
        $mtkId = QuestionBank::find($this->qMC->id)->subject_id;
        $user = User::create(['name' => $roleName, 'email' => strtolower($roleName).'@b7.test', 'username' => strtolower($roleName), 'password' => 'x', 'role_id' => $role->id]);
        if ($permissionName !== null) {
            $permission = Permission::firstOrCreate(['name' => $permissionName]);
            $user->permissions()->attach($permission->id);
        }
        $teacher = Teacher::create(['user_id' => $user->id, 'full_name' => $roleName]);
        TeacherAssignment::create(['teacher_id' => $teacher->id, 'class_id' => $this->class1->id, 'subject_id' => $mtkId, 'academic_year_id' => $this->ay->id]);

        return $user;
    }

    public function test_grade_sync_accepts_manage_exam_results_permission(): void
    {
        $resultId = $this->syncedResultId();

        $manager = $this->buildTeacherUser('Wali Ujian', 'manage-exam-results');

        Sanctum::actingAs($manager);
        $this->postJson("/api/teacher/exam-grading/results/{$resultId}/grade-sync")->assertStatus(200)
            ->assertJsonPath('success', true);
        $this->assertSame(1, Grade::count());
    }

    public function test_grade_sync_denied_without_grading_permission(): void
    {
        $resultId = $this->syncedResultId();

        $staf = $this->buildTeacherUser('Staf', null);

        Sanctum::actingAs($staf);
        $this->postJson("/api/teacher/exam-grading/results/{$resultId}/grade-sync")->assertStatus(403);
        $this->assertSame(0, Grade::count());
    }
}
