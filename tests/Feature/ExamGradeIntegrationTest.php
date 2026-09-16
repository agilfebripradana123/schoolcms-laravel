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
use App\Models\Examination\ExamAttemptQuestion;
use App\Models\Examination\ExamParticipant;
use App\Models\Examination\ExamQuestion;
use App\Models\Examination\ExamResult;
use App\Models\Examination\QuestionBank;
use App\Models\Examination\QuestionOption;
use App\Models\Staff\Teacher;
use App\Models\Staff\TeacherAssignment;
use App\Models\Students\Student;
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
}