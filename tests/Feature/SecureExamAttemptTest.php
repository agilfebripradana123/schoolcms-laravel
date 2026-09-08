<?php

namespace Tests\Feature;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Subject;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamAnswer;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamAttemptEvent;
use App\Models\Examination\ExamParticipant;
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
use Tests\TestCase;

/**
 * Phase 10 — Secure Web Exam (student attempt session).
 *
 * Server-authoritative session: attempt limit, one-active-session,
 * ownership (404 to prevent enumeration), server timer, idempotent autosave,
 * idempotent submit, persistent random order, and event logging.
 */
class SecureExamAttemptTest extends TestCase
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
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->nullable()->index();
            $t->string('username')->nullable();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('photo')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
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
        Schema::create('teachers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('full_name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('classes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('teacher_id')->nullable();
            $t->string('level')->nullable();
            $t->string('academic_year')->nullable();
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
        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->string('type')->nullable();
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
            $t->unique(['teacher_id', 'class_id', 'subject_id', 'academic_year_id'], 'ta_uniq');
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->string('nisn')->nullable();
            $t->string('nis')->nullable();
            $t->string('name');
            $t->string('gender', 1)->nullable();
            $t->string('birth_place')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('address')->nullable();
            $t->string('phone')->nullable();
            $t->string('photo')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $t->string('code')->nullable();
            $t->string('name');
            $t->unsignedInteger('capacity')->nullable();
            $t->string('location')->nullable();
            $t->boolean('has_computer')->default(false);
            $t->string('status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('exam_instructions', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('content');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('exam_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->time('start_time');
            $t->time('end_time');
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
        Schema::create('exam_schedules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_id');
            $t->unsignedBigInteger('room_id');
            $t->unsignedBigInteger('session_id');
            $t->date('exam_date');
            $t->timestamps();
        });
        Schema::create('exam_participants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_id');
            $t->unsignedBigInteger('student_id');
            $t->string('exam_card_number');
            $t->string('status')->default('registered');
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->boolean('is_blocked')->default(false);
            $t->text('blocked_reason')->nullable();
            $t->boolean('login_allowed')->default(true);
            $t->unsignedBigInteger('current_session_id')->nullable();
            $t->dateTime('last_activity_at')->nullable();
            $t->string('ip_address')->nullable();
            $t->timestamps();
        });
        Schema::create('question_banks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('instruction_id')->nullable();
            $t->text('question_text');
            $t->string('question_image')->nullable();
            $t->string('type');
            $t->string('difficulty')->default('medium');
            $t->text('explanation')->nullable();
            $t->unsignedInteger('points')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('question_options', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('question_id');
            $t->string('option_text');
            $t->string('option_image')->nullable();
            $t->boolean('is_correct')->default(false);
            $t->timestamps();
        });
        Schema::create('exam_results', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('participant_id');
            $t->decimal('total_score', 10, 2)->default(0);
            $t->unsignedInteger('correct_count')->default(0);
            $t->unsignedInteger('wrong_count')->default(0);
            $t->unsignedInteger('unanswered_count')->default(0);
            $t->string('grade', 5)->nullable();
            $t->string('status')->default('pending');
            $t->dateTime('graded_at')->nullable();
            $t->timestamps();
            $t->unique(['participant_id'], 'uq_exam_results_participant');
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
            $t->string('token', 64)->nullable()->unique();
            $t->timestamps();
            $t->unique(['exam_participant_id', 'attempt_number'], 'uq_exam_attempts_participant_attempt');
            $t->index('exam_id', 'idx_exam_attempts_exam_id');
            $t->index('status', 'idx_exam_attempts_status');
        });
        Schema::create('exam_attempt_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_attempt_id');
            $t->string('event_type');
            $t->json('metadata')->nullable();
            $t->dateTime('occurred_at');
            $t->timestamps();
            $t->index('event_type', 'idx_att_events_event_type');
            $t->index('occurred_at', 'idx_att_events_occurred_at');
            $t->index('exam_attempt_id', 'idx_att_events_attempt_id');
        });
        Schema::create('exam_answers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_attempt_id')->nullable();
            $t->unsignedBigInteger('participant_id');
            $t->unsignedBigInteger('question_id');
            $t->unsignedBigInteger('selected_option_id')->nullable();
            $t->text('essay_answer')->nullable();
            $t->boolean('is_correct')->nullable();
            $t->dateTime('answered_at');
            $t->timestamps();
            $t->unique(['exam_attempt_id', 'question_id'], 'uq_exam_answers_attempt_question');
            $t->unique(['participant_id', 'question_id'], 'uq_exam_answers_participant_question');
        });
    }

    private function seedFixture(): void
    {
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);

        $year = AcademicYear::create(['name' => '2025/2026', 'is_active' => true]);
        $math = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);

        $userA = User::create(['name' => 'Siswa A', 'email' => 'a@school.test', 'username' => 'a', 'password' => bcrypt('x'), 'role_id' => $roleSiswa->id]);
        $userB = User::create(['name' => 'Siswa B', 'email' => 'b@school.test', 'username' => 'b', 'password' => bcrypt('x'), 'role_id' => $roleSiswa->id]);
        $userC = User::create(['name' => 'Siswa C', 'email' => 'c@school.test', 'username' => 'c', 'password' => bcrypt('x'), 'role_id' => $roleSiswa->id]);
        $guruUser = User::create(['name' => 'Guru X', 'email' => 'gx@school.test', 'username' => 'gx', 'password' => bcrypt('x'), 'role_id' => $roleGuru->id]);

        $studentA = Student::create(['user_id' => $userA->id, 'name' => 'Siswa A', 'nis' => '1001']);
        $studentB = Student::create(['user_id' => $userB->id, 'name' => 'Siswa B', 'nis' => '1002']);
        $studentC = Student::create(['user_id' => $userC->id, 'name' => 'Siswa C', 'nis' => '1003']);

        // Published exam (60 min, 2 questions, 1 attempt, no shuffle).
        $exam = Exam::create([
            'subject_id' => $math->id,
            'title' => 'Ujian MTK',
            'duration_minutes' => 60,
            'total_questions' => 2,
            'passing_score' => 70,
            'max_attempts' => 1,
            'shuffle_questions' => false,
            'shuffle_options' => false,
            'show_result' => true,
            'status' => 'published',
        ]);

        $q1 = QuestionBank::create(['subject_id' => $math->id, 'question_text' => 'Q1?', 'type' => 'multiple_choice', 'points' => 10, 'difficulty' => 'medium']);
        $q2 = QuestionBank::create(['subject_id' => $math->id, 'question_text' => 'Q2?', 'type' => 'multiple_choice', 'points' => 20, 'difficulty' => 'medium']);

        $q1o1 = QuestionOption::create(['question_id' => $q1->id, 'option_text' => 'Q1A', 'is_correct' => true]);
        $q1o2 = QuestionOption::create(['question_id' => $q1->id, 'option_text' => 'Q1B', 'is_correct' => false]);
        $q2o1 = QuestionOption::create(['question_id' => $q2->id, 'option_text' => 'Q2A', 'is_correct' => true]);
        $q2o2 = QuestionOption::create(['question_id' => $q2->id, 'option_text' => 'Q2B', 'is_correct' => false]);

        // A, B participants of the exam; C is NOT a participant.
        $participantA = ExamParticipant::create(['exam_id' => $exam->id, 'student_id' => $studentA->id, 'exam_card_number' => 'CARD-A']);
        $participantB = ExamParticipant::create(['exam_id' => $exam->id, 'student_id' => $studentB->id, 'exam_card_number' => 'CARD-B']);

        // A second exam with shuffle + repeated attempts for randomization tests.
        $examShuffle = Exam::create([
            'subject_id' => $math->id,
            'title' => 'Ujian Acak',
            'duration_minutes' => 30,
            'total_questions' => 2,
            'max_attempts' => 3,
            'shuffle_questions' => true,
            'shuffle_options' => true,
            'status' => 'published',
        ]);
        QuestionBank::create(['subject_id' => $math->id, 'question_text' => 'S1?', 'type' => 'multiple_choice', 'points' => 5, 'difficulty' => 'medium']);
        QuestionBank::create(['subject_id' => $math->id, 'question_text' => 'S2?', 'type' => 'multiple_choice', 'points' => 5, 'difficulty' => 'medium']);

        $participantShuffleA = ExamParticipant::create(['exam_id' => $examShuffle->id, 'student_id' => $studentA->id, 'exam_card_number' => 'CARD-SA']);
        $participantShuffleB = ExamParticipant::create(['exam_id' => $examShuffle->id, 'student_id' => $studentB->id, 'exam_card_number' => 'CARD-SB']);

        $this->userA = $userA;
        $this->userB = $userB;
        $this->userC = $userC;
        $this->guruUser = $guruUser;
        $this->studentA = $studentA;
        $this->studentB = $studentB;

        $this->examId = $exam->id;
        $this->examShuffleId = $examShuffle->id;
        $this->participantAId = $participantA->id;
        $this->participantBId = $participantB->id;
        $this->q1Id = $q1->id;
        $this->q2Id = $q2->id;
        $this->q1o1Id = $q1o1->id;
        $this->q1o2Id = $q1o2->id;
        $this->q2o1Id = $q2o1->id;
    }

    // -----------------------------------------------------------------
    // Start / attempt limit / one active session
    // -----------------------------------------------------------------

    public function test_start_valid_participant_creates_active_attempt(): void
    {
        $this->actingAs($this->userA, 'sanctum');
        $res = $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->examId]);
        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.attempt_number', 1);
        $this->assertDatabaseHas('exam_attempts', ['exam_participant_id' => $this->participantAId, 'status' => 'active']);
    }

    public function test_start_non_participant_is_not_found(): void
    {
        $this->actingAs($this->userC, 'sanctum');
        $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->examId])->assertStatus(404);
    }

    public function test_start_non_student_role_is_forbidden(): void
    {
        $this->actingAs($this->guruUser, 'sanctum');
        $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->examId])->assertStatus(403);
    }

    public function test_start_unauthenticated_is_unauthorized(): void
    {
        $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->examId])->assertStatus(401);
    }

    public function test_one_active_session_returns_same_attempt(): void
    {
        $this->actingAs($this->userA, 'sanctum');
        $first = $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->examId]);
        $first->assertStatus(200);
        $id = $first->json('data.id');

        $second = $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->examId]);
        $second->assertStatus(200)->assertJsonPath('data.id', $id);

        $this->assertSame(1, ExamAttempt::where('exam_participant_id', $this->participantAId)->count());
    }

    public function test_max_attempts_enforced(): void
    {
        $this->actingAs($this->userA, 'sanctum');
        $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->examId])->assertStatus(200);
        $attempt = ExamAttempt::where('exam_participant_id', $this->participantAId)->first();

        $this->postJson("/api/student/exam-attempts/{$attempt->id}/submit")->assertStatus(200);

        // max_attempts = 1 -> second start rejected.
        $again = $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->examId]);
        $again->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Ownership
    // -----------------------------------------------------------------

    public function test_cannot_view_other_students_attempt(): void
    {
        $this->actingAs($this->userB, 'sanctum');
        $attemptB = $this->startFor($this->userA);
        $this->actingAs($this->userB, 'sanctum');
        $this->getJson("/api/student/exam-attempts/{$attemptB}")->assertStatus(404);
    }

    public function test_cannot_save_answer_on_other_students_attempt(): void
    {
        $attemptB = $this->startFor($this->userA);
        $this->actingAs($this->userB, 'sanctum');
        $this->putJson("/api/student/exam-attempts/{$attemptB}/answers/{$this->q1Id}", ['selected_option_id' => $this->q1o1Id])->assertStatus(404);
    }

    public function test_cannot_submit_other_students_attempt(): void
    {
        $attemptB = $this->startFor($this->userA);
        $this->actingAs($this->userB, 'sanctum');
        $this->postJson("/api/student/exam-attempts/{$attemptB}/submit")->assertStatus(404);
    }

    public function test_cannot_report_event_on_other_students_attempt(): void
    {
        $attemptB = $this->startFor($this->userA);
        $this->actingAs($this->userB, 'sanctum');
        $this->postJson("/api/student/exam-attempts/{$attemptB}/events", ['event_type' => 'tab_switch'])->assertStatus(404);
    }

    public function test_reconnect_show_returns_saved_answers(): void
    {
        $attemptId = $this->startFor($this->userA);
        $this->asA()->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$this->q1Id}", ['selected_option_id' => $this->q1o1Id])->assertStatus(200);

        $res = $this->asA()->getJson("/api/student/exam-attempts/{$attemptId}");
        $res->assertStatus(200)
            ->assertJsonPath("data.attempt.id", $attemptId)
            ->assertJsonPath("data.answers.{$this->q1Id}.selected_option_id", $this->q1o1Id);

        $answers = $res->json('data.answers');
        $this->assertArrayNotHasKey((string) $this->q2Id, $answers);
    }

    // -----------------------------------------------------------------
    // Timer
    // -----------------------------------------------------------------

    public function test_active_attempt_accepts_answer(): void
    {
        $attemptId = $this->startFor($this->userA);
        $res = $this->asA()->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$this->q1Id}", ['selected_option_id' => $this->q1o1Id]);
        $res->assertStatus(200)->assertJsonPath('data.selected_option_id', $this->q1o1Id);
    }

    public function test_expired_attempt_rejects_answer_and_transitions_status(): void
    {
        $attemptId = $this->startFor($this->userA);

        // Force expiry (server-authoritative; simulate time passing).
        ExamAttempt::where('id', $attemptId)->update(['expires_at' => now()->subMinute()]);

        $this->asA()->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$this->q1Id}", ['selected_option_id' => $this->q1o1Id])->assertStatus(422);

        $attempt = ExamAttempt::find($attemptId);
        $this->assertSame('expired', $attempt->status);
    }

    public function test_expired_attempt_is_reported_on_reconnect(): void
    {
        $attemptId = $this->startFor($this->userA);
        ExamAttempt::where('id', $attemptId)->update(['expires_at' => now()->subMinute()]);
        $this->asA()->getJson("/api/student/exam-attempts/{$attemptId}")
            ->assertStatus(200)
            ->assertJsonPath('data.attempt.status', 'expired');
    }

    // -----------------------------------------------------------------
    // Answers (idempotency, validation)
    // -----------------------------------------------------------------

    public function test_answer_update_is_idempotent(): void
    {
        $attemptId = $this->startFor($this->userA);
        $this->asA()->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$this->q1Id}", ['selected_option_id' => $this->q1o1Id])->assertStatus(200);
        $this->asA()->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$this->q1Id}", ['selected_option_id' => $this->q1o2Id])->assertStatus(200);

        $this->assertSame(1, ExamAnswer::where('exam_attempt_id', $attemptId)->where('question_id', $this->q1Id)->count());
        $this->assertDatabaseHas('exam_answers', ['exam_attempt_id' => $attemptId, 'question_id' => $this->q1Id, 'selected_option_id' => $this->q1o2Id]);
    }

    public function test_answer_question_outside_attempt_rejected(): void
    {
        $attemptId = $this->startFor($this->userA);
        // q2 is inside, but a bogus question id is not.
        $this->asA()->putJson("/api/student/exam-attempts/{$attemptId}/answers/999999", ['selected_option_id' => $this->q1o1Id])->assertStatus(422);
    }

    public function test_answer_invalid_option_rejected(): void
    {
        $attemptId = $this->startFor($this->userA);
        $this->asA()->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$this->q1Id}", ['selected_option_id' => 999999])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Submit (idempotency, result)
    // -----------------------------------------------------------------

    public function test_submit_computes_result(): void
    {
        $attemptId = $this->startFor($this->userA);
        $this->asA()->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$this->q1Id}", ['selected_option_id' => $this->q1o1Id])->assertStatus(200);
        $this->asA()->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$this->q2Id}", ['selected_option_id' => $this->q2o1Id])->assertStatus(200);

        $res = $this->asA()->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $res->assertStatus(200)
            ->assertJsonPath('data.result.correct_count', 2)
            ->assertJsonPath('data.result.total_score', 30)
            ->assertJsonPath('data.result.grade', 'A');
        $this->assertSame(1, ExamResult::where('participant_id', $this->participantAId)->count());
    }

    public function test_duplicate_submit_is_idempotent(): void
    {
        $attemptId = $this->startFor($this->userA);
        $this->asA()->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertStatus(200);
        $this->asA()->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertStatus(200);
        $this->assertSame(1, ExamResult::where('participant_id', $this->participantAId)->count());
    }

    public function test_submit_expired_attempt_finalizes(): void
    {
        $attemptId = $this->startFor($this->userA);
        $this->asA()->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$this->q1Id}", ['selected_option_id' => $this->q1o1Id])->assertStatus(200);
        ExamAttempt::where('id', $attemptId)->update(['expires_at' => now()->subMinute()]);

        $res = $this->asA()->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $res->assertStatus(200)->assertJsonPath('data.attempt.status', 'submitted');
        $this->assertSame(1, ExamResult::where('participant_id', $this->participantAId)->count());
    }

    // -----------------------------------------------------------------
    // Randomization
    // -----------------------------------------------------------------

    public function test_question_order_is_stable_across_requests(): void
    {
        $attemptId = $this->startFor($this->userA);

        $r1 = $this->asA()->getJson("/api/student/exam-attempts/{$attemptId}/questions");
        $r2 = $this->asA()->getJson("/api/student/exam-attempts/{$attemptId}/questions");
        $r1->assertStatus(200);
        $ids1 = collect($r1->json('data.questions'))->pluck('id')->all();
        $ids2 = collect($r2->json('data.questions'))->pluck('id')->all();
        $this->assertSame($ids1, $ids2);
    }

    public function test_question_delivery_does_not_expose_answer_key(): void
    {
        $attemptId = $this->startFor($this->userA);
        $res = $this->asA()->getJson("/api/student/exam-attempts/{$attemptId}/questions");
        $res->assertStatus(200);
        foreach ($res->json('data.questions') as $question) {
            $this->assertArrayNotHasKey('explanation', $question);
            foreach ($question['options'] as $option) {
                $this->assertArrayNotHasKey('is_correct', $option);
            }
        }
    }

    public function test_shuffled_attempts_have_stable_per_attempt_orders(): void
    {
        // Attempt 1.
        $this->actingAs($this->userA, 'sanctum');
        $a1 = $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->examShuffleId])->assertStatus(200)->json('data.id');

        $order1a = collect($this->asA()->getJson("/api/student/exam-attempts/{$a1}/questions")->json('data.questions'))->pluck('id')->all();
        $order1b = collect($this->asA()->getJson("/api/student/exam-attempts/{$a1}/questions")->json('data.questions'))->pluck('id')->all();

        // Submit attempt 1, then start attempt 2 (one-active-session permits a fresh attempt).
        $this->asA()->postJson("/api/student/exam-attempts/{$a1}/submit")->assertStatus(200);
        $a2 = $this->asA()->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->examShuffleId])->assertStatus(200)->json('data.id');
        $this->assertNotSame($a1, $a2, 'a new attempt must be created after submitting');

        $order2a = collect($this->asA()->getJson("/api/student/exam-attempts/{$a2}/questions")->json('data.questions'))->pluck('id')->all();
        $order2b = collect($this->asA()->getJson("/api/student/exam-attempts/{$a2}/questions")->json('data.questions'))->pluck('id')->all();

        $this->assertSame($order1a, $order1b, 'order must be stable within attempt 1');
        $this->assertSame($order2a, $order2b, 'order must be stable within attempt 2');
        $this->assertNotSame([], $order1a);
        $this->assertNotSame([], $order2a);
    }

    public function test_scoring_uses_correct_option_identity(): void
    {
        // q1 correct option is q1o1. Answer q1o2 (wrong) -> correct_count 0 for q1.
        $attemptId = $this->startFor($this->userA);
        $this->asA()->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$this->q1Id}", ['selected_option_id' => $this->q1o2Id])->assertStatus(200);
        $this->asA()->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$this->q2Id}", ['selected_option_id' => $this->q2o1Id])->assertStatus(200);

        $res = $this->asA()->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $res->assertStatus(200)->assertJsonPath('data.result.correct_count', 1)->assertJsonPath('data.result.total_score', 20);
    }

    // -----------------------------------------------------------------
    // Events
    // -----------------------------------------------------------------

    public function test_valid_event_saved_with_server_timestamp(): void
    {
        $attemptId = $this->startFor($this->userA);
        $res = $this->asA()->postJson("/api/student/exam-attempts/{$attemptId}/events", ['event_type' => 'tab_switch', 'metadata' => ['count' => 2]]);
        $res->assertStatus(200);
        $event = ExamAttemptEvent::where('exam_attempt_id', $attemptId)->first();
        $this->assertNotNull($event);
        $this->assertSame('tab_switch', $event->event_type);
        $this->assertNotNull($event->occurred_at);
        $this->assertSame(['count' => 2], $event->metadata);
    }

    public function test_invalid_event_rejected(): void
    {
        $attemptId = $this->startFor($this->userA);
        $this->asA()->postJson("/api/student/exam-attempts/{$attemptId}/events", ['event_type' => 'not_a_real_event'])->assertStatus(422);
        $this->assertSame(0, ExamAttemptEvent::count());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function asA()
    {
        $this->actingAs($this->userA, 'sanctum');
        return $this;
    }

    private function startFor(User $user): int
    {
        $this->actingAs($user, 'sanctum');
        $id = $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->examId])->assertStatus(200)->json('data.id');
        return (int) $id;
    }
}
