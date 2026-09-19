<?php

namespace Tests\Feature;

use App\Models\Academic\Subject;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamAnswer;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamParticipant;
use App\Models\Examination\ExamAttemptQuestion;
use App\Models\Examination\ExamQuestion;
use App\Models\Examination\ExamResult;
use App\Models\Examination\ExamSchedule;
use App\Models\Examination\ExamSession;
use App\Models\Examination\QuestionBank;
use App\Models\Examination\QuestionOption;
use App\Models\Facilities\Room;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2F — Participant & attempt hardening.
 *
 * Hermetic suite; builds its own schema on the default (sqlite :memory:)
 * connection in setUp — never touches live MySQL.
 */
class ExamParticipantAttemptHardeningTest extends TestCase
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
            $t->string('status')->default('draft');
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
        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $t->string('code')->nullable();
            $t->string('name');
            $t->string('status')->default('active');
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
        Schema::create('exam_answers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_attempt_id')->nullable();
            $t->unsignedBigInteger('attempt_question_id')->nullable();
            $t->unsignedBigInteger('participant_id');
            $t->unsignedBigInteger('question_id');
            $t->unsignedBigInteger('selected_option_id')->nullable();
            $t->unsignedBigInteger('selected_attempt_option_id')->nullable();
            $t->decimal('score', 10, 2)->nullable();
            $t->string('grade_status', 20)->nullable();
            $t->unsignedBigInteger('graded_by')->nullable();
            $t->dateTime('graded_at')->nullable();
            $t->text('feedback')->nullable();
            $t->text('essay_answer')->nullable();
            $t->boolean('is_correct')->nullable();
            $t->dateTime('answered_at');
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
        Schema::create('exam_results', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('participant_id');
            $t->unsignedBigInteger('exam_attempt_id')->nullable();
            $t->decimal('percentage', 5, 2)->nullable();
            $t->decimal('total_score', 10, 2)->default(0);
            $t->unsignedInteger('correct_count')->default(0);
            $t->unsignedInteger('wrong_count')->default(0);
            $t->unsignedInteger('unanswered_count')->default(0);
            $t->string('grade', 5)->nullable();
            $t->string('status')->default('pending');
            $t->dateTime('graded_at')->nullable();
            $t->boolean('is_final')->default(false);
            $t->dateTime('finalized_at')->nullable();
            $t->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@2f.test', 'username' => 'admin', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $this->guru = User::create(['name' => 'Guru', 'email' => 'guru@2f.test', 'username' => 'guru', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $userA = User::create(['name' => 'Siswa A', 'email' => 'a@2f.test', 'username' => 'a', 'password' => 'x', 'role_id' => $roleSiswa->id]);
        $userB = User::create(['name' => 'Siswa B', 'email' => 'b@2f.test', 'username' => 'b', 'password' => 'x', 'role_id' => $roleSiswa->id]);

        $this->studentA = Student::create(['user_id' => $userA->id, 'name' => 'Siswa A', 'nis' => 'A01']);
        $this->studentB = Student::create(['user_id' => $userB->id, 'name' => 'Siswa B', 'nis' => 'A02']);

        $mtk = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        $this->q1 = $this->makeQuestion($mtk);

        // max_attempts = 2 exam, participants A and B.
        $this->examMulti = $this->makeExam('Multi', 2);
        ExamQuestion::create(['exam_id' => $this->examMulti->id, 'question_id' => $this->q1->id, 'position' => 1, 'points' => 10]);
        $this->participantA = ExamParticipant::create(['exam_id' => $this->examMulti->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-A', 'status' => 'registered', 'login_allowed' => true]);
        $this->participantB = ExamParticipant::create(['exam_id' => $this->examMulti->id, 'student_id' => $this->studentB->id, 'exam_card_number' => 'CARD-B', 'status' => 'registered', 'login_allowed' => true]);

        // max_attempts = 1 exam, participant A only.
        $this->examSingle = $this->makeExam('Single', 1);
        ExamQuestion::create(['exam_id' => $this->examSingle->id, 'question_id' => $this->q1->id, 'position' => 1, 'points' => 10]);
        ExamParticipant::create(['exam_id' => $this->examSingle->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-A1', 'status' => 'registered', 'login_allowed' => true]);

        $this->room = Room::create(['code' => 'R1', 'name' => 'Ruang 1']);
        $this->session = ExamSession::create(['name' => 'Sesi 1', 'start_time' => '08:00:00', 'end_time' => '10:00:00']);
        $this->correctOption = QuestionOption::where('question_id', $this->q1->id)->where('is_correct', true)->first();
    }

    private function makeQuestion(Subject $subject): QuestionBank
    {
        $q = QuestionBank::create([
            'subject_id' => $subject->id,
            'question_text' => 'Q1?',
            'type' => 'multiple_choice',
            'difficulty' => 'medium',
            'points' => 10,
            'status' => 'approved',
        ]);
        QuestionOption::create(['question_id' => $q->id, 'option_text' => 'A', 'is_correct' => true]);
        QuestionOption::create(['question_id' => $q->id, 'option_text' => 'B', 'is_correct' => false]);

        return $q;
    }

    private function makeExam(string $title, int $maxAttempts): Exam
    {
        return Exam::create([
            'subject_id' => $this->q1->subject_id,
            'title' => $title,
            'duration_minutes' => 60,
            'max_attempts' => $maxAttempts,
            'status' => 'published',
        ]);
    }

    private function startAs(User $user, int $examId, array $extra = [])
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/student/exam-attempts/start', array_merge(['exam_id' => $examId], $extra));
    }

    /**
     * Resolve the snapshot attempt-question id + correct snapshot option id for
     * a source question in the given attempt.
     */
    private function snapshotRefs(int $attemptId, int $sourceQid): array
    {
        $aq = ExamAttemptQuestion::where('exam_attempt_id', $attemptId)
            ->where('source_question_id', $sourceQid)
            ->with('options')
            ->first();

        return [
            'aq' => $aq->id,
            'correct' => collect($aq->options)->firstWhere('is_correct', true)->id ?? null,
        ];
    }

    private function addWindow(int $examId, string $start, string $end): ExamSchedule
    {
        return ExamSchedule::create([
            'exam_id' => $examId,
            'room_id' => $this->room->id,
            'session_id' => $this->session->id,
            'exam_date' => now()->toDateString(),
            'start_datetime' => $start,
            'end_datetime' => $end,
        ]);
    }

    // -----------------------------------------------------------------
    // Eligibility
    // -----------------------------------------------------------------

    public function test_eligible_participant_can_start(): void
    {
        $res = $this->startAs($this->studentA->user, $this->examMulti->id);
        $res->assertStatus(200)->assertJsonPath('data.attempt_number', 1);
    }

    public function test_non_participant_rejected(): void
    {
        $res = $this->startAs($this->studentB->user, $this->examSingle->id);
        $res->assertStatus(404);
    }

    public function test_participant_status_eligible_enforced(): void
    {
        $this->participantA->update(['is_blocked' => true]);
        $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Attempt creation / numbering / server-owned state
    // -----------------------------------------------------------------

    public function test_attempt_number_is_server_generated_and_immune_to_client(): void
    {
        $res = $this->startAs($this->studentA->user, $this->examMulti->id, [
            'attempt_number' => 99,
            'participant_id' => $this->participantB->id,
            'status' => 'submitted',
            'score' => 100,
        ]);
        $res->assertStatus(200)->assertJsonPath('data.attempt_number', 1);

        $attempt = ExamAttempt::where('exam_participant_id', $this->participantA->id)->first();
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame($this->participantA->id, $attempt->exam_participant_id, 'participant_id cannot be hijacked');
        $this->assertSame('active', $attempt->status);
        $this->assertNull($attempt->submitted_at);
    }

    public function test_duplicate_start_resumes_same_active_attempt(): void
    {
        $first = $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(200)->json('data.id');
        $second = $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(200)->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, ExamAttempt::where('exam_participant_id', $this->participantA->id)->count(), 'no duplicate active attempt');
        $this->assertSame(1, ExamAttempt::where('exam_participant_id', $this->participantA->id)->where('status', 'active')->count());
    }

    public function test_submitted_attempt_is_not_resumable(): void
    {
        $first = $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(200)->json('data.id');
        $this->postJson("/api/student/exam-attempts/{$first}/submit")->assertStatus(200);

        $second = $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(200)->json('data');
        $this->assertNotSame($first, $second['id']);
        $this->assertSame(2, $second['attempt_number']);
    }

    public function test_expired_attempt_is_not_resumable(): void
    {
        $first = $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(200)->json('data.id');
        ExamAttempt::where('id', $first)->update(['expires_at' => now()->subMinute()]);

        $second = $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(200)->json('data');
        $this->assertNotSame($first, $second['id']);
        $this->assertSame(2, $second['attempt_number']);
        $this->assertSame('expired', ExamAttempt::find($first)->status, 'lazy expiry marks the old attempt expired');
    }

    public function test_max_attempts_enforced(): void
    {
        $first = $this->startAs($this->studentA->user, $this->examSingle->id)->assertStatus(200)->json('data.id');
        $this->postJson("/api/student/exam-attempts/{$first}/submit")->assertStatus(200);

        $this->startAs($this->studentA->user, $this->examSingle->id)->assertStatus(422);
    }

    public function test_next_attempt_allowed_when_limit_permits(): void
    {
        $first = $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(200)->json('data.id');
        $this->postJson("/api/student/exam-attempts/{$first}/submit")->assertStatus(200);

        $second = $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(200)->json('data.id');
        $this->assertSame(2, ExamAttempt::find($second)->attempt_number);
        $this->postJson("/api/student/exam-attempts/{$second}/submit")->assertStatus(200);

        // max_attempts = 2 -> a third attempt must be rejected.
        $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Schedule binding
    // -----------------------------------------------------------------

    public function test_schedule_binding_gates_student(): void
    {
        // A is bound to schedule window [future, future+1h]; B-less window open elsewhere.
        $scheduleA = $this->addWindow($this->examMulti->id, now()->addHour()->toDateTimeString(), now()->addHours(2)->toDateTimeString());
        $this->addWindow($this->examMulti->id, now()->subHour()->toDateTimeString(), now()->addHour()->toDateTimeString());
        $this->participantA->update(['schedule_id' => $scheduleA->id]);

        $res = $this->startAs($this->studentA->user, $this->examMulti->id, ['schedule_id' => 1]);
        $res->assertStatus(422)->assertJsonPath('message', 'Exam has not started yet.');
        $this->assertNull(ExamAttempt::where('exam_participant_id', $this->participantA->id)->first(), 'no attempt inside a non-open bound window');
    }

    public function test_bound_schedule_survives_arbitrary_schedule_input(): void
    {
        $scheduleA = $this->addWindow($this->examMulti->id, now()->subHour()->toDateTimeString(), now()->addHour()->toDateTimeString());
        $this->participantA->update(['schedule_id' => $scheduleA->id]);

        // Even if the client tries to pick another schedule, the bound one wins.
        $this->startAs($this->studentA->user, $this->examMulti->id, ['schedule_id' => 999])->assertStatus(200);
    }

    public function test_bound_schedule_missing_denies_not_opens(): void
    {
        // A is bound to a schedule id that does not belong to this exam.
        $this->participantA->update(['schedule_id' => 424242]);

        $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Time boundaries
    // -----------------------------------------------------------------

    public function test_before_window_rejected(): void
    {
        $this->addWindow($this->examMulti->id, now()->addHour()->toDateTimeString(), now()->addHours(2)->toDateTimeString());
        $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(422);
    }

    public function test_after_window_new_attempt_rejected(): void
    {
        $this->addWindow($this->examMulti->id, now()->subHours(2)->toDateTimeString(), now()->subHour()->toDateTimeString());
        $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(422);
    }

    public function test_inflight_attempt_survives_window_end(): void
    {
        $scheduleId = $this->addWindow($this->examMulti->id, now()->subHour()->toDateTimeString(), now()->addHour()->toDateTimeString())->id;
        $first = $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(200)->json('data.id');

        // Window closes.
        ExamSchedule::where('id', $scheduleId)->update(['end_datetime' => now()->subMinute()]);

        // Resume still works (in-flight attempt), answer + submit still work.
        $resumed = $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(200)->json('data.id');
        $this->assertSame($first, $resumed, 'in-flight attempt resumes after window ends');

        $ref = $this->snapshotRefs($first, $this->q1->id);
        $this->putJson("/api/student/exam-attempts/{$first}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$first}/submit")->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // Ownership / IDOR
    // -----------------------------------------------------------------

    public function test_student_cannot_access_other_students_attempt(): void
    {
        $aId = $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(200)->json('data.id');

        $this->startAs($this->studentB->user, $this->examMulti->id)->assertStatus(200);
        $this->getJson("/api/student/exam-attempts/{$aId}")->assertStatus(404);
        $this->getJson("/api/student/exam-attempts/{$aId}/questions")->assertStatus(404);
        $this->putJson("/api/student/exam-attempts/{$aId}/answers/{$this->q1->id}", ['selected_option_id' => $this->correctOption->id])->assertStatus(404);
        $this->postJson("/api/student/exam-attempts/{$aId}/submit")->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------

    public function test_duplicate_submit_idempotent(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(200)->json('data.id');

        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertStatus(200);
        $this->assertSame(1, ExamResult::where('participant_id', $this->participantA->id)->count());
        $this->assertSame(1, ExamAttempt::where('id', $attemptId)->where('status', 'submitted')->count());
    }

    public function test_autosave_is_idempotent(): void
    {
        $attemptId = $this->startAs($this->studentA->user, $this->examMulti->id)->assertStatus(200)->json('data.id');
        $ref = $this->snapshotRefs($attemptId, $this->q1->id);

        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$ref['aq']}", ['selected_option_id' => $ref['correct']])->assertStatus(200);

        $this->assertSame(1, ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $ref['aq'])->count());
    }

    // -----------------------------------------------------------------
    // Security
    // -----------------------------------------------------------------

    public function test_unauthenticated_rejected(): void
    {
        $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->examMulti->id])->assertStatus(401);
    }

    public function test_teacher_cannot_start_student_attempt(): void
    {
        Sanctum::actingAs($this->guru);
        $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->examMulti->id])->assertStatus(403);
    }

    public function test_student_cannot_manage_participants(): void
    {
        Sanctum::actingAs($this->studentA->user);
        $this->getJson('/api/exam-participants')->assertStatus(403);
        $this->postJson('/api/exam-participants', ['exam_id' => $this->examMulti->id, 'student_id' => $this->studentB->id, 'exam_card_number' => 'X', 'status' => 'registered'])->assertStatus(403);
    }

    public function test_admin_can_bind_participant_to_schedule(): void
    {
        Sanctum::actingAs($this->admin);
        $schedule = $this->addWindow($this->examMulti->id, now()->addHour()->toDateTimeString(), now()->addHours(2)->toDateTimeString());

        $this->putJson('/api/exam-participants/'.$this->participantA->id, ['schedule_id' => $schedule->id])
            ->assertStatus(200)
            ->assertJsonPath('data.schedule_id', $schedule->id);
    }
}