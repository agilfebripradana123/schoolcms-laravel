<?php

namespace Tests\Feature;

use App\Models\Academic\Subject;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamQuestion;
use App\Models\Examination\ExamParticipant;
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
 * Phase 2E — Exam lifecycle & scheduling hardening.
 *
 * Hermetic suite; builds its own schema on the default (sqlite :memory:)
 * connection in setUp — never touches live MySQL.
 */
class ExamLifecycleAndSchedulingTest extends TestCase
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
            $t->unsignedBigInteger('owner_id')->nullable();
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
            $t->unsignedInteger('capacity')->nullable();
            $t->string('location')->nullable();
            $t->boolean('has_computer')->default(false);
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
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
            $t->string('exam_card_number');
            $t->string('status')->default('registered');
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->boolean('is_blocked')->default(false);
            $t->boolean('login_allowed')->default(true);
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
    }

    private function seedFixture(): void
    {
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@2e.test', 'username' => 'admin', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $this->guru = User::create(['name' => 'Guru', 'email' => 'guru@2e.test', 'username' => 'guru', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $studentAUser = User::create(['name' => 'Siswa A', 'email' => 'a@2e.test', 'username' => 'a', 'password' => 'x', 'role_id' => $roleSiswa->id]);
        $studentBUser = User::create(['name' => 'Siswa B', 'email' => 'b@2e.test', 'username' => 'b', 'password' => 'x', 'role_id' => $roleSiswa->id]);

        $this->studentA = Student::create(['user_id' => $studentAUser->id, 'name' => 'Siswa A', 'nis' => 'A01']);
        $this->studentB = Student::create(['user_id' => $studentBUser->id, 'name' => 'Siswa B', 'nis' => 'A02']);

        $mtk = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        $ipa = Subject::create(['code' => 'IPA', 'name' => 'IPA']);

        $this->q1 = $this->makeQuestion($mtk, 'approved', 'Q1?');
        $this->q2 = $this->makeQuestion($mtk, 'approved', 'Q2?');
        $this->qOther = $this->makeQuestion($ipa, 'approved', 'Q-OTHER?');

        $this->room = Room::create(['code' => 'R1', 'name' => 'Ruang 1', 'status' => 'active']);
        $this->session = ExamSession::create(['name' => 'Sesi 1', 'start_time' => '08:00:00', 'end_time' => '10:00:00']);

        $this->draftExam = $this->makeExam('draft', 'Draft Exam');
        ExamQuestion::create(['exam_id' => $this->draftExam->id, 'question_id' => $this->q1->id, 'position' => 1, 'points' => 10]);

        $this->emptyDraftExam = $this->makeExam('draft', 'Empty Draft');

        $this->readyPublished = $this->makeExam('published', 'Ready Published');
        ExamQuestion::create(['exam_id' => $this->readyPublished->id, 'question_id' => $this->q1->id, 'position' => 1, 'points' => 10]);

        $this->ongoingExam = $this->makeExam('ongoing', 'Ongoing Exam');
        $this->completedExam = $this->makeExam('completed', 'Completed Exam');
        $this->archivedExam = $this->makeExam('archived', 'Archived Exam');

        $this->windowExam = $this->makeExam('published', 'Window Exam');
        ExamQuestion::create(['exam_id' => $this->windowExam->id, 'question_id' => $this->q1->id, 'position' => 1, 'points' => 10]);
        ExamParticipant::create([
            'exam_id' => $this->windowExam->id,
            'student_id' => $this->studentA->id,
            'exam_card_number' => 'CARD-A',
            'status' => 'registered',
            'login_allowed' => true,
        ]);
    }

    private function makeQuestion(Subject $subject, string $status, string $text): QuestionBank
    {
        $q = QuestionBank::create([
            'subject_id' => $subject->id,
            'question_text' => $text,
            'type' => 'multiple_choice',
            'difficulty' => 'medium',
            'points' => 10,
            'status' => $status,
        ]);
        QuestionOption::create(['question_id' => $q->id, 'option_text' => 'A', 'is_correct' => true]);
        QuestionOption::create(['question_id' => $q->id, 'option_text' => 'B', 'is_correct' => false]);

        return $q;
    }

    private function makeExam(string $status, string $title): Exam
    {
        return Exam::create([
            'subject_id' => $this->q1->subject_id,
            'title' => $title,
            'duration_minutes' => 60,
            'status' => $status,
        ]);
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    private function schedulePayload(Exam $exam, ?string $start, ?string $end): array
    {
        return [
            'exam_id' => $exam->id,
            'room_id' => $this->room->id,
            'session_id' => $this->session->id,
            'exam_date' => now()->toDateString(),
            'start_datetime' => $start,
            'end_datetime' => $end,
        ];
    }

    // -----------------------------------------------------------------
    // Lifecycle transitions
    // -----------------------------------------------------------------

    public function test_draft_with_composition_can_publish(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/exams/'.$this->draftExam->id, ['status' => 'published'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'published');
    }

    public function test_draft_without_composition_cannot_publish(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/exams/'.$this->emptyDraftExam->id, ['status' => 'published'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Exam cannot be published.');
    }

    public function test_illegal_forward_transitions_rejected(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/exams/'.$this->draftExam->id, ['status' => 'ongoing'])->assertStatus(422);
        $this->putJson('/api/exams/'.$this->draftExam->id, ['status' => 'completed'])->assertStatus(422);
        $this->putJson('/api/exams/'.$this->draftExam->id, ['status' => 'archived'])->assertStatus(422);
    }

    public function test_forward_lifecycle_is_valid(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/exams/'.$this->draftExam->id, ['status' => 'published'])->assertStatus(200);
        $this->putJson('/api/exams/'.$this->draftExam->id, ['status' => 'ongoing'])->assertStatus(200);
        $this->putJson('/api/exams/'.$this->draftExam->id, ['status' => 'completed'])->assertStatus(200);
        $this->putJson('/api/exams/'.$this->draftExam->id, ['status' => 'archived'])->assertStatus(200);
    }

    public function test_backward_transitions_rejected(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/exams/'.$this->readyPublished->id, ['status' => 'draft'])->assertStatus(422);
        $this->putJson('/api/exams/'.$this->archivedExam->id, ['status' => 'published'])->assertStatus(422);
        $this->putJson('/api/exams/'.$this->completedExam->id, ['status' => 'ongoing'])->assertStatus(422);
        $this->putJson('/api/exams/'.$this->archivedExam->id, ['status' => 'completed'])->assertStatus(422);
    }

    public function test_new_exam_always_starts_as_draft(): void
    {
        $this->actAsAdmin();
        $data = $this->postJson('/api/exams', [
            'subject_id' => $this->q1->subject_id,
            'title' => 'New',
            'duration_minutes' => 60,
            'status' => 'published',
        ])->assertStatus(201)->json('data');

        $this->assertSame('draft', $data['status'], 'client cannot bypass lifecycle by creating the exam directly as published');
    }

    public function test_publish_rejected_when_composed_question_is_deleted(): void
    {
        $this->actAsAdmin();
        $exam = $this->makeExam('draft', 'Decay Draft');
        ExamQuestion::create(['exam_id' => $exam->id, 'question_id' => $this->q1->id, 'position' => 1, 'points' => 10]);
        $this->q1->delete();

        $this->putJson('/api/exams/'.$exam->id, ['status' => 'published'])->assertStatus(422);
    }

    public function test_publish_rejected_when_composed_question_subject_mismatches(): void
    {
        $this->actAsAdmin();
        $exam = $this->makeExam('draft', 'Mismatch Draft');
        ExamQuestion::create(['exam_id' => $exam->id, 'question_id' => $this->qOther->id, 'position' => 1, 'points' => 10]);

        $this->putJson('/api/exams/'.$exam->id, ['status' => 'published'])->assertStatus(422);
    }

    public function test_operational_exams_reject_composition_mutation(): void
    {
        $this->actAsAdmin();
        foreach (['published', 'ongoing', 'completed', 'archived'] as $status) {
            $exam = $this->makeExam($status, "Locked $status");
            $this->postJson('/api/exams/'.$exam->id.'/questions', ['question_id' => $this->q1->id])
                ->assertStatus(422);
        }
    }

    // -----------------------------------------------------------------
    // Scheduling
    // -----------------------------------------------------------------

    public function test_valid_schedule_accepted(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exam-schedules', $this->schedulePayload(
            $this->draftExam,
            now()->addHour()->toDateTimeString(),
            now()->addHours(2)->toDateTimeString()
        ))->assertStatus(201);
    }

    public function test_end_before_or_equal_start_rejected(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exam-schedules', $this->schedulePayload(
            $this->draftExam,
            now()->toDateTimeString(),
            now()->toDateTimeString()
        ))->assertStatus(422);

        $this->postJson('/api/exam-schedules', $this->schedulePayload(
            $this->draftExam,
            now()->addHours(2)->toDateTimeString(),
            now()->addHour()->toDateTimeString()
        ))->assertStatus(422);
    }

    public function test_partial_window_rejected(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exam-schedules', $this->schedulePayload(
            $this->draftExam,
            now()->addHour()->toDateTimeString(),
            null
        ))->assertStatus(422);
    }

    public function test_completed_exam_cannot_be_scheduled(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exam-schedules', $this->schedulePayload(
            $this->completedExam,
            now()->addHour()->toDateTimeString(),
            now()->addHours(2)->toDateTimeString()
        ))->assertStatus(422);
    }

    public function test_archived_exam_cannot_be_scheduled(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exam-schedules', $this->schedulePayload(
            $this->archivedExam,
            now()->addHour()->toDateTimeString(),
            now()->addHours(2)->toDateTimeString()
        ))->assertStatus(422);
    }

    public function test_ongoing_exam_cannot_be_scheduled(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exam-schedules', $this->schedulePayload(
            $this->ongoingExam,
            now()->addHour()->toDateTimeString(),
            now()->addHours(2)->toDateTimeString()
        ))->assertStatus(422);
    }

    public function test_invalid_exam_schedule_relation_rejected(): void
    {
        $this->actAsAdmin();
        $payload = $this->schedulePayload($this->draftExam, now()->addHour()->toDateTimeString(), now()->addHours(2)->toDateTimeString());
        $payload['exam_id'] = 99999;
        $this->postJson('/api/exam-schedules', $payload)->assertStatus(422);
    }

    public function test_multiple_schedules_per_exam_allowed(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exam-schedules', $this->schedulePayload($this->draftExam, now()->addHour()->toDateTimeString(), now()->addHours(2)->toDateTimeString()))->assertStatus(201);
        $this->postJson('/api/exam-schedules', $this->schedulePayload($this->draftExam, now()->addDays(1)->toDateTimeString(), now()->addDays(1)->addHour()->toDateTimeString()))->assertStatus(201);
        $this->assertSame(2, ExamSchedule::where('exam_id', $this->draftExam->id)->count(), 'multiple schedules per exam are valid (room/session grouping)');
    }

    // -----------------------------------------------------------------
    // Student execution window (server time)
    // -----------------------------------------------------------------

    private function addWindowToWindowExam(?string $start, ?string $end): void
    {
        ExamSchedule::create([
            'exam_id' => $this->windowExam->id,
            'room_id' => $this->room->id,
            'session_id' => $this->session->id,
            'exam_date' => now()->toDateString(),
            'start_datetime' => $start,
            'end_datetime' => $end,
        ]);
    }

    public function test_student_cannot_start_before_window(): void
    {
        Sanctum::actingAs($this->studentA->user);
        $this->addWindowToWindowExam(now()->addHour()->toDateTimeString(), now()->addHours(2)->toDateTimeString());

        $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->windowExam->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Exam has not started yet.');
    }

    public function test_student_can_start_during_window(): void
    {
        Sanctum::actingAs($this->studentA->user);
        $this->addWindowToWindowExam(now()->subHour()->toDateTimeString(), now()->addHour()->toDateTimeString());

        $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->windowExam->id])->assertStatus(200);
    }

    public function test_student_cannot_start_new_attempt_after_window(): void
    {
        Sanctum::actingAs($this->studentA->user);
        $this->addWindowToWindowExam(now()->subHours(2)->toDateTimeString(), now()->subHour()->toDateTimeString());

        $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->windowExam->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Exam window has ended; no new attempts can be started.');
    }

    public function test_student_not_participant_rejected(): void
    {
        Sanctum::actingAs($this->studentB->user);
        $this->addWindowToWindowExam(now()->subHour()->toDateTimeString(), now()->addHour()->toDateTimeString());

        $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->windowExam->id])->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Security
    // -----------------------------------------------------------------

    public function test_unauthenticated_lifecycle_mutation_rejected(): void
    {
        $this->putJson('/api/exams/'.$this->draftExam->id, ['status' => 'published'])->assertStatus(401);
    }

    public function test_student_cannot_mutate_lifecycle(): void
    {
        Sanctum::actingAs($this->studentA->user);
        $this->putJson('/api/exams/'.$this->draftExam->id, ['status' => 'published'])->assertStatus(403);
    }

    public function test_teacher_cannot_mutate_lifecycle(): void
    {
        Sanctum::actingAs($this->guru);
        $this->putJson('/api/exams/'.$this->draftExam->id, ['status' => 'published'])->assertStatus(403);
    }

    public function test_admin_lifecycle_mutation_succeeds(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/exams/'.$this->draftExam->id, ['status' => 'published'])->assertStatus(200);
    }
}