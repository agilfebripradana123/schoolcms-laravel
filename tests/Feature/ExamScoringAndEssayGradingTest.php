<?php

namespace Tests\Feature;

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
use App\Models\System\Permission;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2H — scoring & manual essay grading.
 *
 * Hermetic suite; builds its own schema on the default (sqlite :memory:)
 * connection in setUp — never touches live MySQL.
 */
class ExamScoringAndEssayGradingTest extends TestCase
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
        Schema::create('teachers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('full_name')->nullable();
            $t->string('name')->nullable();
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
        Schema::create('academic_years', function (Blueprint $t) {
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
            $t->unsignedBigInteger('student_id')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->string('type', 10)->nullable();
            $t->decimal('score', 5, 2)->nullable();
            $t->unsignedBigInteger('semester_id')->nullable();
            $t->unsignedBigInteger('academic_year_id')->nullable();
            $t->string('source_type', 50)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
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
            $t->decimal('max_score', 5, 2)->default(100.00);
            $t->decimal('weight', 5, 2)->nullable();
            $t->string('source_type', 50)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->date('assessed_date')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(
                ['student_id', 'subject_id', 'class_id', 'academic_year_id', 'semester_id', 'assessment_category', 'assessment_sequence'],
                'uq_grade_assessments_cat_seq'
            );
            $t->index(['source_type', 'source_id']);
        });
    }

    private function seedFixture(): void
    {
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@2h.test', 'username' => 'admin', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $teacherAUser = User::create(['name' => 'Guru MTK', 'email' => 'ta@2h.test', 'username' => 'ta', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $teacherBUser = User::create(['name' => 'Guru IPA', 'email' => 'tb@2h.test', 'username' => 'tb', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $userA = User::create(['name' => 'Siswa A', 'email' => 'a@2h.test', 'username' => 'a', 'password' => 'x', 'role_id' => $roleSiswa->id]);
        $userB = User::create(['name' => 'Siswa B', 'email' => 'b@2h.test', 'username' => 'b', 'password' => 'x', 'role_id' => $roleSiswa->id]);

        $this->teacherA = $teacherAUser;
        $this->teacherB = $teacherBUser;
        $this->studentA = Student::create(['user_id' => $userA->id, 'name' => 'Siswa A', 'nis' => 'A01']);
        $this->studentB = Student::create(['user_id' => $userB->id, 'name' => 'Siswa B', 'nis' => 'A02']);

        // B21-01: essay grading and grade-sync are write operations gated by the
        // manage-exam-results permission (Guru default grants view-exam-results only).
        $manageExamResults = Permission::create(['name' => 'manage-exam-results']);
        $teacherAUser->permissions()->attach($manageExamResults->id);
        $teacherBUser->permissions()->attach($manageExamResults->id);

        $mtk = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        $ipa = Subject::create(['code' => 'IPA', 'name' => 'IPA']);

        $teacherARec = Teacher::create(['user_id' => $teacherAUser->id, 'full_name' => 'Guru MTK']);
        $teacherBRec = Teacher::create(['user_id' => $teacherBUser->id, 'full_name' => 'Guru IPA']);
        $year = \App\Models\Academic\AcademicYear::create(['name' => '2025/2026']);
        TeacherAssignment::create(['teacher_id' => $teacherARec->id, 'class_id' => 1, 'subject_id' => $mtk->id, 'academic_year_id' => $year->id]);
        TeacherAssignment::create(['teacher_id' => $teacherBRec->id, 'class_id' => 1, 'subject_id' => $ipa->id, 'academic_year_id' => $year->id]);

        $this->qMC = $this->makeQuestion($mtk, 'MC?', 'multiple_choice', 10, [['A', true], ['B', false]]);
        $this->qTF = $this->makeQuestion($mtk, 'TF?', 'true_false', 5, [['Benar', true], ['Salah', false]]);
        $this->qEssay = $this->makeQuestion($mtk, 'Essay?', 'essay', 20, []);
        $this->qIpaEssay = $this->makeQuestion($ipa, 'IPA Essay?', 'essay', 30, []);

        $this->exam = Exam::create(['subject_id' => $mtk->id, 'title' => 'MTK Exam', 'duration_minutes' => 60, 'max_attempts' => 1, 'status' => 'published']);
        ExamQuestion::create(['exam_id' => $this->exam->id, 'question_id' => $this->qMC->id, 'position' => 1, 'points' => 10]);
        ExamQuestion::create(['exam_id' => $this->exam->id, 'question_id' => $this->qTF->id, 'position' => 2, 'points' => 5]);
        ExamQuestion::create(['exam_id' => $this->exam->id, 'question_id' => $this->qEssay->id, 'position' => 3, 'points' => 20]);
        $this->participantA = ExamParticipant::create(['exam_id' => $this->exam->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-A', 'status' => 'registered', 'login_allowed' => true]);
        ExamParticipant::create(['exam_id' => $this->exam->id, 'student_id' => $this->studentB->id, 'exam_card_number' => 'CARD-B', 'status' => 'registered', 'login_allowed' => true]);

        // Cross-exam scope fixture: IPA essay exam.
        $this->ipaExam = Exam::create(['subject_id' => $ipa->id, 'title' => 'IPA Exam', 'duration_minutes' => 30, 'max_attempts' => 1, 'status' => 'published']);
        ExamQuestion::create(['exam_id' => $this->ipaExam->id, 'question_id' => $this->qIpaEssay->id, 'position' => 1, 'points' => 30]);
        $this->participantIpaA = ExamParticipant::create(['exam_id' => $this->ipaExam->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-IPA', 'status' => 'registered', 'login_allowed' => true]);

        // Multi-attempt fixture (teacherA MTK scope) for grading answer-id isolation.
        $this->multiAttemptExam = Exam::create(['subject_id' => $mtk->id, 'title' => 'MTK Multi-Attempt', 'duration_minutes' => 60, 'max_attempts' => 2, 'status' => 'published']);
        ExamQuestion::create(['exam_id' => $this->multiAttemptExam->id, 'question_id' => $this->qEssay->id, 'position' => 1, 'points' => 20]);
        ExamParticipant::create(['exam_id' => $this->multiAttemptExam->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-MULTI', 'status' => 'registered', 'login_allowed' => true]);
    }

    private function makeQuestion(Subject $subject, string $text, string $type, int $points, array $options): QuestionBank
    {
        $q = QuestionBank::create([
            'subject_id' => $subject->id,
            'question_text' => $text,
            'type' => $type,
            'difficulty' => 'medium',
            'points' => $points,
            'status' => 'approved',
        ]);
        foreach ($options as [$textOpt, $correct]) {
            QuestionOption::create(['question_id' => $q->id, 'option_text' => $textOpt, 'is_correct' => $correct]);
        }

        return $q;
    }

    private function startAs(User $user, int $examId): int
    {
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $examId]);
        $res->assertStatus(200);

        return (int) $res->json('data.id');
    }

    private function aqFor(int $attemptId, int $sourceQid): ExamAttemptQuestion
    {
        return ExamAttemptQuestion::where('exam_attempt_id', $attemptId)
            ->where('source_question_id', $sourceQid)
            ->with('options')
            ->firstOrFail();
    }

    private function answer(int $attemptId, ExamAttemptQuestion $aq, ?int $optionId = null, ?string $essay = null): void
    {
        $payload = [];
        if ($optionId !== null) {
            $payload['selected_option_id'] = $optionId;
        }
        if ($essay !== null) {
            $payload['essay_answer'] = $essay;
        }
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$aq->id}", $payload)->assertStatus(200);
    }

    private function submit(int $attemptId)
    {
        return $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
    }

    private function gradeAs(User $teacher, int $examAnswerId, array $payload)
    {
        Sanctum::actingAs($teacher);

        return $this->putJson("/api/teacher/exam-grading/answers/{$examAnswerId}", $payload);
    }

    private function gradingShowAs(User $teacher, int $attemptId)
    {
        Sanctum::actingAs($teacher);

        return $this->getJson("/api/teacher/exam-grading/attempts/{$attemptId}");
    }

    private function makeGradingTeacher(string $label, QuestionBank $inSubject): User
    {
        $roleGuru = Role::where('name', 'Guru')->firstOrFail();
        $user = User::create(['name' => $label, 'email' => $label.'@h.test', 'username' => $label, 'password' => 'x', 'role_id' => $roleGuru->id]);
        $teacher = Teacher::create(['user_id' => $user->id, 'full_name' => $label]);
        $year = \App\Models\Academic\AcademicYear::firstOrFail();
        TeacherAssignment::create([
            'teacher_id' => $teacher->id,
            'class_id' => 1,
            'subject_id' => $inSubject->subject_id,
            'academic_year_id' => $year->id,
        ]);

        return $user;
    }

    // -----------------------------------------------------------------
    // Objective scoring
    // -----------------------------------------------------------------

    public function test_objective_mc_tf_scored_from_snapshot(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqMC = $this->aqFor($attemptId, $this->qMC->id);
        $aqTF = $this->aqFor($attemptId, $this->qTF->id);

        $this->answer($attemptId, $aqMC, $aqMC->options->firstWhere('is_correct', true)->id);
        $this->answer($attemptId, $aqTF, $aqTF->options->firstWhere('is_correct', false)->id);

        $res = $this->submit($attemptId);
        $res->assertStatus(200);
        $this->assertSame(10, (int) $res->json('data.result.total_score'), 'MC correct = 10, TF wrong = 0, essay unanswered = 0');
        $this->assertSame(1, $res->json('data.result.correct_count'));
        $this->assertSame(1, $res->json('data.result.wrong_count'));
        $this->assertSame(1, $res->json('data.result.unanswered_count'));
        $this->assertSame('graded', $res->json('data.result.status'), 'unanswered essay does not block grading');

        $storedMC = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aqMC->id)->first();
        $this->assertSame('auto', $storedMC->grade_status);
        $this->assertSame(10, (int) $storedMC->score);

        $this->assertSame(1, ExamResult::where('participant_id', $this->participantA->id)->count());
    }

    public function test_empty_attempt_scores_zero(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $res = $this->submit($attemptId)->assertStatus(200)->json('data.result');
        $this->assertSame(0, (int) $res['total_score']);
        $this->assertSame(3, $res['unanswered_count']);
        $this->assertSame(0.0, (float) $res['percentage']);
        $this->assertSame('graded', $res['status']);
    }

    // -----------------------------------------------------------------
    // Essay manual grading
    // -----------------------------------------------------------------

    public function test_answered_essay_keeps_result_pending_until_graded(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqEssay = $this->aqFor($attemptId, $this->qEssay->id);
        $this->answer($attemptId, $aqEssay, null, 'Jawaban esai siswa.');

        $res = $this->submit($attemptId)->assertStatus(200)->json('data.result');
        $this->assertSame('pending', $res['status'], 'answered essay must keep the result pending');
        $this->assertSame(0, (int) $res['total_score']);

        $stored = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aqEssay->id)->first();
        $this->assertSame('pending_manual', $stored->grade_status);
        $this->assertNull($stored->score);
    }

    public function test_teacher_grades_essay_and_result_updates(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqEssay = $this->aqFor($attemptId, $this->qEssay->id);
        $this->answer($attemptId, $aqEssay, null, 'Jawaban esai siswa.');
        $this->submit($attemptId);

        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aqEssay->id)->first()->id;

        $res = $this->gradeAs($this->teacherA, $answerId, ['score' => 16, 'feedback' => 'Bagus']);
        $res->assertStatus(200)
            ->assertJsonPath('data.score', 16)
            ->assertJsonPath('data.grade_status', 'manually_graded')
            ->assertJsonPath('data.result.total_score', 16)
            ->assertJsonPath('data.result.status', 'graded');

        $this->assertNotNull($res->json('data.graded_by'), 'grader identity is server-derived');
        $this->assertNotNull($res->json('data.graded_at'), 'graded timestamp is server-derived');

        $stored = ExamAnswer::find($answerId);
        $this->assertSame('manually_graded', $stored->grade_status);
        $this->assertSame(16, (int) $stored->score);
        $this->assertSame('Bagus', $stored->feedback);
        $this->assertSame($this->teacherA->id, $stored->graded_by);
        $this->assertNotNull($stored->graded_at);

        $this->assertSame(45.71, (float) ExamResult::where('participant_id', $this->participantA->id)->first()->percentage);
    }

    public function test_essay_score_bounds_enforced(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqEssay = $this->aqFor($attemptId, $this->qEssay->id);
        $this->answer($attemptId, $aqEssay, null, 'x');
        $this->submit($attemptId);
        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aqEssay->id)->first()->id;

        // max = 20
        $this->gradeAs($this->teacherA, $answerId, ['score' => 21])->assertStatus(422);
        $this->gradeAs($this->teacherA, $answerId, ['score' => -1])->assertStatus(422);
        $this->gradeAs($this->teacherA, $answerId, ['score' => 20])->assertStatus(200);
    }

    public function test_zero_score_and_regrade_are_deterministic(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqEssay = $this->aqFor($attemptId, $this->qEssay->id);
        $this->answer($attemptId, $aqEssay, null, 'x');
        $this->submit($attemptId);
        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aqEssay->id)->first()->id;

        $this->gradeAs($this->teacherA, $answerId, ['score' => 0])->assertStatus(200)->assertJsonPath('data.result.status', 'graded');
        $this->gradeAs($this->teacherA, $answerId, ['score' => 12])->assertStatus(200)->assertJsonPath('data.result.total_score', 12);

        $this->assertSame(1, ExamResult::where('participant_id', $this->participantA->id)->count(), 're-grading reconciles, never duplicates');
    }

    // -----------------------------------------------------------------
    // Attempt lifecycle integration
    // -----------------------------------------------------------------

    public function test_active_attempt_cannot_be_graded(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqEssay = $this->aqFor($attemptId, $this->qEssay->id);
        $this->answer($attemptId, $aqEssay, null, 'x');
        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aqEssay->id)->first()->id;

        $this->gradeAs($this->teacherA, $answerId, ['score' => 10])->assertStatus(422);
        $this->assertSame('active', ExamAttempt::find($attemptId)->status, 'grading must not reopen/finalize an active attempt');
    }

    public function test_expired_attempt_can_be_graded(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqEssay = $this->aqFor($attemptId, $this->qEssay->id);
        $this->answer($attemptId, $aqEssay, null, 'x');
        ExamAttempt::where('id', $attemptId)->update(['expires_at' => now()->subMinute()]);
        $this->submit($attemptId)->assertStatus(200);

        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aqEssay->id)->first()->id;
        $this->gradeAs($this->teacherA, $answerId, ['score' => 7])->assertStatus(200);
    }

    public function test_submit_is_idempotent(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $this->submit($attemptId)->assertStatus(200);
        $this->submit($attemptId)->assertStatus(200);
        $this->assertSame(1, ExamResult::where('participant_id', $this->participantA->id)->count());
    }

    // -----------------------------------------------------------------
    // Security / authorization
    // -----------------------------------------------------------------

    public function test_unauthenticated_grading_rejected(): void
    {
        $this->putJson('/api/teacher/exam-grading/answers/1', ['score' => 5])->assertStatus(401);
    }

    public function test_student_cannot_grade(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqEssay = $this->aqFor($attemptId, $this->qEssay->id);
        $this->answer($attemptId, $aqEssay, null, 'x');
        $this->submit($attemptId);
        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aqEssay->id)->first()->id;

        $this->gradeAs($this->studentA->user, $answerId, ['score' => 5])->assertStatus(403);
    }

    public function test_unrelated_teacher_cannot_grade(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqEssay = $this->aqFor($attemptId, $this->qEssay->id);
        $this->answer($attemptId, $aqEssay, null, 'x');
        $this->submit($attemptId);
        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aqEssay->id)->first()->id;

        // Teacher B only teaches IPA -> grading the MTK essay is 404 (no IDOR leak).
        $this->gradeAs($this->teacherB, $answerId, ['score' => 5])->assertStatus(404);
    }

    public function test_view_only_teacher_cannot_grade_but_keeps_read_access(): void
    {
        // B21-01: a Guru with only default view-exam-results may read grading
        // data but the essay-grade write is denied (403) — write requires
        // manage-exam-results.
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqEssay = $this->aqFor($attemptId, $this->qEssay->id);
        $this->answer($attemptId, $aqEssay, null, 'Jawaban');
        $this->submit($attemptId);
        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aqEssay->id)->first()->id;

        $viewOnly = $this->makeGradingTeacher('Guru View-Only', $this->qEssay);

        $this->gradingShowAs($viewOnly, $attemptId)->assertStatus(200);
        $this->gradeAs($viewOnly, $answerId, ['score' => 5])->assertStatus(403);

        $answer = ExamAnswer::find($answerId);
        $this->assertNull($answer->score, 'denied write must not touch the essay answer');
    }

    public function test_finalized_result_essay_regrade_rejected(): void
    {
        // B21-02: a finalized result rejects essay mutation deterministically —
        // the answer row stays untouched.
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqEssay = $this->aqFor($attemptId, $this->qEssay->id);
        $this->answer($attemptId, $aqEssay, null, 'Jawaban');
        $this->submit($attemptId);
        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aqEssay->id)->first()->id;
        $resultId = ExamResult::where('exam_attempt_id', $attemptId)->value('id');

        $this->gradeAs($this->teacherA, $answerId, ['score' => 16])->assertStatus(200);

        ExamResult::where('id', $resultId)->update(['is_final' => true]);

        $this->gradeAs($this->teacherA, $answerId, ['score' => 12])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Result is finalized and cannot be modified.');

        $this->assertSame(16, (int) ExamAnswer::find($answerId)->score, 'rejected regrade leaves the essay score unchanged');
        $this->assertSame(80.0, (float) ExamResult::find($resultId)->percentage, 'finalized result value unchanged');
    }

    public function test_teacher_grades_within_own_exam_scope(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->ipaExam->id);
        $aqEssay = $this->aqFor($attemptId, $this->qIpaEssay->id);
        $this->answer($attemptId, $aqEssay, null, 'ipa');
        $this->submit($attemptId);
        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aqEssay->id)->first()->id;

        $this->gradeAs($this->teacherB, $answerId, ['score' => 30])->assertStatus(200);
        // Teacher A (MTK) may not grade IPA exam answers.
        $this->gradeAs($this->teacherA, $answerId, ['score' => 5])->assertStatus(404);
    }

    public function test_teacher_grading_show_exposes_essay_answer_id(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqEssay = $this->aqFor($attemptId, $this->qEssay->id);
        $this->answer($attemptId, $aqEssay, null, 'Jawaban esai siswa.');
        $this->submit($attemptId);

        $expectedAnswerId = ExamAnswer::where('exam_attempt_id', $attemptId)
            ->where('attempt_question_id', $aqEssay->id)
            ->first()->id;

        $res = $this->gradingShowAs($this->teacherA, $attemptId)->assertStatus(200);
        $essay = collect($res->json('data.essays'))->firstWhere('attempt_question_id', $aqEssay->id);

        $this->assertNotNull($essay, 'essay must be present in the grading payload');
        $this->assertArrayHasKey('exam_answer_id', $essay, 'essay payload must expose exam_answer_id');
        $this->assertSame($expectedAnswerId, $essay['exam_answer_id'], 'exam_answer_id must equal the real exam_answers.id for the attempt');
        $this->assertSame($aqEssay->id, $essay['attempt_question_id'], 'attempt_question_id preserved');
    }

    public function test_grading_show_answer_id_matches_requested_attempt_on_multi_attempt(): void
    {
        $attempt1 = $this->startAs($this->studentA->user, $this->multiAttemptExam->id);
        $aq1 = $this->aqFor($attempt1, $this->qEssay->id);
        $this->answer($attempt1, $aq1, null, 'Jawaban percobaan pertama.');
        $this->submit($attempt1);

        $attempt2 = $this->startAs($this->studentA->user, $this->multiAttemptExam->id);
        $aq2 = $this->aqFor($attempt2, $this->qEssay->id);
        $this->answer($attempt2, $aq2, null, 'Jawaban percobaan kedua.');
        $this->submit($attempt2);

        $answer1Id = ExamAnswer::where('exam_attempt_id', $attempt1)->where('attempt_question_id', $aq1->id)->value('id');
        $answer2Id = ExamAnswer::where('exam_attempt_id', $attempt2)->where('attempt_question_id', $aq2->id)->value('id');
        $this->assertNotSame($answer1Id, $answer2Id, 'each attempt must own a distinct exam_answers row');

        $essays1 = collect($this->gradingShowAs($this->teacherA, $attempt1)->assertStatus(200)->json('data.essays'));
        $essays2 = collect($this->gradingShowAs($this->teacherA, $attempt2)->assertStatus(200)->json('data.essays'));

        $this->assertSame($answer1Id, $essays1->firstWhere('attempt_question_id', $aq1->id)['exam_answer_id'], 'attempt 1 must expose its own answer id');
        $this->assertSame($answer2Id, $essays2->firstWhere('attempt_question_id', $aq2->id)['exam_answer_id'], 'attempt 2 must expose its own answer id, not attempt 1');
    }

    public function test_regrading_attempt_one_does_not_modify_attempt_two_result(): void
    {
        $attempt1 = $this->startAs($this->studentA->user, $this->multiAttemptExam->id);
        $aq1 = $this->aqFor($attempt1, $this->qEssay->id);
        $this->answer($attempt1, $aq1, null, 'Jawaban percobaan pertama.');
        $this->submit($attempt1);

        $attempt2 = $this->startAs($this->studentA->user, $this->multiAttemptExam->id);
        $aq2 = $this->aqFor($attempt2, $this->qEssay->id);
        $this->answer($attempt2, $aq2, null, 'Jawaban percobaan kedua.');
        $this->submit($attempt2);

        $participantId = ExamAttempt::find($attempt2)->exam_participant_id;
        $this->assertSame(2, ExamResult::where('participant_id', $participantId)->count(), 'per-attempt result rows');

        $attempt1Result = ExamResult::where('exam_attempt_id', $attempt1)->firstOrFail();
        $attempt2Result = ExamResult::where('exam_attempt_id', $attempt2)->firstOrFail();
        $this->assertNotSame($attempt1Result->id, $attempt2Result->id, 'results must be distinct per attempt');

        // Regrade attempt #1 only — attempt #2 must stay untouched.
        $answer1Id = ExamAnswer::where('exam_attempt_id', $attempt1)->where('attempt_question_id', $aq1->id)->first()->id;
        $this->gradeAs($this->teacherA, $answer1Id, ['score' => 14])->assertStatus(200);

        $attempt1Result->refresh();
        $attempt2Result->refresh();

        $this->assertSame(14, (int) $attempt1Result->total_score, 'attempt 1 result updated');
        $this->assertSame('graded', $attempt1Result->status);
        $this->assertSame(0, (int) $attempt2Result->total_score, 'attempt 2 result untouched by regrade of attempt 1');
        $this->assertSame('pending', $attempt2Result->status, 'attempt 2 essay remains pending_manual');
        $this->assertSame(2, ExamResult::where('participant_id', $participantId)->count(), 'no duplicate rows after regrade');
    }

    public function test_teacher_grading_show_out_of_scope_is_not_found(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqEssay = $this->aqFor($attemptId, $this->qEssay->id);
        $this->answer($attemptId, $aqEssay, null, 'x');
        $this->submit($attemptId);

        // Teacher B (IPA only) must not read the MTK attempt grading payload.
        $this->gradingShowAs($this->teacherB, $attemptId)->assertStatus(404);
    }

    public function test_client_cannot_inject_scoring_truth(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqEssay = $this->aqFor($attemptId, $this->qEssay->id);
        $aqMC = $this->aqFor($attemptId, $this->qMC->id);

        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$aqEssay->id}", [
            'essay_answer' => 'x',
            'score' => 999,
            'grade_status' => 'manually_graded',
            'graded_by' => 1,
        ])->assertStatus(200);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$aqMC->id}", [
            'selected_option_id' => $aqMC->options->firstWhere('is_correct', false)->id,
            'score' => 999,
            'is_correct' => true,
        ])->assertStatus(200);

        $essayRow = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aqEssay->id)->first();
        $mcRow = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aqMC->id)->first();
        $this->assertNull($essayRow->score, 'client cannot inject essay score');
        $this->assertNull($essayRow->grade_status, 'client cannot set grade_status');
        $this->assertNull($essayRow->graded_by);
        $this->assertFalse($mcRow->is_correct, 'client cannot override snapshot correctness');
        $this->assertNull($mcRow->score, 'client cannot inject objective score');
    }

    // -----------------------------------------------------------------
    // Snapshot isolation of scoring
    // -----------------------------------------------------------------

    public function test_live_bank_mutation_does_not_change_score(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->exam->id);
        $aqMC = $this->aqFor($attemptId, $this->qMC->id);
        $this->answer($attemptId, $aqMC, $aqMC->options->firstWhere('is_correct', true)->id);

        // Mutate the live bank after the attempt started.
        QuestionBank::where('id', $this->qMC->id)->update(['points' => 999]);
        QuestionOption::where('question_id', $this->qMC->id)->update(['is_correct' => 0]);
        ExamQuestion::where('exam_id', $this->exam->id)->where('question_id', $this->qMC->id)->update(['points' => 999]);
        $this->qMC->delete();

        $res = $this->submit($attemptId)->assertStatus(200)->json('data.result');
        $this->assertSame(10, (int) $res['total_score'], 'score uses the frozen snapshot points (10), not the mutated bank (999)');
        $this->assertSame(1, $res['correct_count'], 'correctness uses the frozen snapshot option, unaffected by bank flip');
    }
}