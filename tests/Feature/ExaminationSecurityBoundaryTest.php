<?php

namespace Tests\Feature;

use App\Models\Academic\Subject;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamAnswer;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamAttemptQuestion;
use App\Models\Examination\ExamAttemptQuestionOption;
use App\Models\Examination\ExamParticipant;
use App\Models\Examination\ExamResult;
use App\Models\Examination\QuestionBank;
use App\Models\Examination\QuestionOption;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2B — Examination security boundary (P0 hardening).
 *
 * Hermetic suite: builds its own schema on the default (sqlite :memory:)
 * connection in setUp — it never touches the live MySQL database.
 *
 * Verifies the P0 acceptance criteria:
 *   - no answer-key leak (is_correct / explanation) to students
 *   - admin question/exam/participant/answer/result GETs are not bare
 *     auth:sanctum (gated by manage-exams)
 *   - student answer/participant/result payloads are ownership-scoped and
 *     sanitized (no other-student data, no grading truth)
 *   - student cannot read another student's attempt
 *   - client loses authority over is_correct / score / grade
 */
class ExaminationSecurityBoundaryTest extends TestCase
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
            $t->string('description')->nullable();
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
            $t->string('type')->nullable();
            $t->string('description')->nullable();
            $t->timestamps();
            $t->softDeletes();
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
            $t->text('option_text');
            $t->string('option_image')->nullable();
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
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->string('nisn')->nullable();
            $t->string('nis')->nullable();
            $t->string('name');
            $t->string('gender', 1)->nullable();
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
            $t->text('blocked_reason')->nullable();
            $t->boolean('login_allowed')->default(true);
            $t->unsignedBigInteger('current_session_id')->nullable();
            $t->dateTime('last_activity_at')->nullable();
            $t->string('ip_address')->nullable();
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
        Schema::create('exam_questions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_id');
            $t->unsignedBigInteger('question_id');
            $t->unsignedBigInteger('blueprint_item_id')->nullable();
            $t->unsignedInteger('position')->default(0);
            $t->unsignedInteger('points')->default(1);
            $t->timestamps();
        });
        Schema::create('exam_results', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('participant_id');
            $t->unsignedBigInteger('exam_attempt_id')->nullable();
            $t->decimal('total_score', 10, 2)->default(0);
            $t->decimal('percentage', 5, 2)->nullable();
            $t->unsignedInteger('correct_count')->default(0);
            $t->unsignedInteger('wrong_count')->default(0);
            $t->unsignedInteger('unanswered_count')->default(0);
            $t->string('grade', 5)->nullable();
            $t->string('status')->default('pending');
            $t->dateTime('graded_at')->nullable();
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

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@phase2b.test', 'username' => 'admin', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $guru = User::create(['name' => 'Guru', 'email' => 'guru@phase2b.test', 'username' => 'guru', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $studentAUser = User::create(['name' => 'Siswa A', 'email' => 'a@phase2b.test', 'username' => 'a', 'password' => 'x', 'role_id' => $roleSiswa->id]);
        $studentBUser = User::create(['name' => 'Siswa B', 'email' => 'b@phase2b.test', 'username' => 'b', 'password' => 'x', 'role_id' => $roleSiswa->id]);

        $studentA = Student::create(['user_id' => $studentAUser->id, 'name' => 'Siswa A', 'nis' => 'A1001']);
        $studentB = Student::create(['user_id' => $studentBUser->id, 'name' => 'Siswa B', 'nis' => 'A1002']);

        $subject = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);

        $question = QuestionBank::create([
            'subject_id' => $subject->id,
            'question_text' => 'SEC-Q?',
            'type' => 'multiple_choice',
            'difficulty' => 'medium',
            'explanation' => 'SECRET-KEY-EXPLANATION',
            'points' => 10,
        ]);
        $correctOption = QuestionOption::create(['question_id' => $question->id, 'option_text' => 'Benar', 'is_correct' => true]);
        QuestionOption::create(['question_id' => $question->id, 'option_text' => 'Salah', 'is_correct' => false]);

        $exam = Exam::create([
            'subject_id' => $subject->id,
            'title' => 'Ujian Rahasia',
            'duration_minutes' => 60,
            'total_questions' => 1,
            'passing_score' => 70,
            'max_attempts' => 2,
            'status' => 'published',
        ]);

        $participantA = ExamParticipant::create([
            'exam_id' => $exam->id,
            'student_id' => $studentA->id,
            'exam_card_number' => 'CARD-A',
            'status' => 'completed',
            'ip_address' => '10.0.0.1',
            'blocked_reason' => null,
            'current_session_id' => 999,
        ]);
        $participantB = ExamParticipant::create([
            'exam_id' => $exam->id,
            'student_id' => $studentB->id,
            'exam_card_number' => 'CARD-B',
            'status' => 'registered',
            'ip_address' => '10.0.0.2',
        ]);

        ExamAnswer::create([
            'participant_id' => $participantA->id,
            'question_id' => $question->id,
            'selected_option_id' => $correctOption->id,
            'is_correct' => true,
            'answered_at' => now(),
        ]);
        ExamAnswer::create([
            'participant_id' => $participantB->id,
            'question_id' => $question->id,
            'selected_option_id' => $correctOption->id,
            'is_correct' => true,
            'answered_at' => now(),
        ]);

        // Attempt + result belonging to student A (attempt-linked, per-attempt contract).
        $attemptA = ExamAttempt::create([
            'exam_participant_id' => $participantA->id,
            'exam_id' => $exam->id,
            'attempt_number' => 1,
            'status' => 'submitted',
            'started_at' => now(),
            'submitted_at' => now(),
            'expires_at' => now()->addMinutes(60),
        ]);

        ExamResult::create([
            'participant_id' => $participantA->id,
            'exam_attempt_id' => $attemptA->id,
            'total_score' => 90,
            'grade' => 'A',
            'status' => 'graded',
        ]);

        // Legacy historical result without an attempt link (read-only).
        ExamResult::create([
            'participant_id' => $participantA->id,
            'total_score' => 10,
            'grade' => 'B',
            'status' => 'graded',
        ]);

        // Attempt belonging to student B only.
        $this->attemptB = ExamAttempt::create([
            'exam_participant_id' => $participantB->id,
            'exam_id' => $exam->id,
            'attempt_number' => 1,
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(60),
        ]);

        // Expired attempt (participant B) — eligible but without a result.
        $this->attemptExpired = ExamAttempt::create([
            'exam_participant_id' => $participantB->id,
            'exam_id' => $exam->id,
            'attempt_number' => 2,
            'status' => 'expired',
            'started_at' => now()->subMinutes(30),
            'expires_at' => now()->subMinute(),
        ]);

        // Submitted attempt (participant B) — eligible, without a result.
        $this->attemptNoResult = ExamAttempt::create([
            'exam_participant_id' => $participantB->id,
            'exam_id' => $exam->id,
            'attempt_number' => 3,
            'status' => 'submitted',
            'started_at' => now()->subMinutes(20),
            'submitted_at' => now()->subMinutes(5),
            'expires_at' => now()->addMinutes(60),
        ]);

        $this->attemptA = $attemptA;
        $this->resultAId = ExamResult::where('exam_attempt_id', $attemptA->id)->first()->id;
        $this->legacyResultId = ExamResult::where('exam_attempt_id', null)->first()->id;

        $this->admin = $admin;
        $this->guru = $guru;
        $this->studentA = $studentAUser;
        $this->studentB = $studentBUser;
        $this->studentAId = $studentA->id;
        $this->studentBId = $studentB->id;
        $this->participantAId = $participantA->id;
        $this->participantBId = $participantB->id;
        $this->examId = $exam->id;
        $this->questionId = $question->id;
        $this->answerAId = ExamAnswer::where('participant_id', $participantA->id)->first()->id;
        $this->answerBId = ExamAnswer::where('participant_id', $participantB->id)->first()->id;
    }

    // -----------------------------------------------------------------
    // P0 — answer key / admin boundary
    // -----------------------------------------------------------------

    public function test_student_cannot_access_question_bank(): void
    {
        Sanctum::actingAs($this->studentA);
        $this->getJson('/api/questions')->assertStatus(403);
    }

    public function test_guru_without_manage_exams_cannot_access_question_bank(): void
    {
        Sanctum::actingAs($this->guru);
        $this->getJson('/api/questions')->assertStatus(403);
    }

    public function test_admin_can_access_question_bank(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/questions');
        $response->assertStatus(200);
    }

    public function test_student_cannot_access_admin_exam_endpoints(): void
    {
        Sanctum::actingAs($this->studentA);
        $this->getJson('/api/exams')->assertStatus(403);
        $this->getJson('/api/exam-participants')->assertStatus(403);
        $this->getJson('/api/exam-results')->assertStatus(403);
        $this->getJson('/api/exam-answers')->assertStatus(403);
        $this->getJson('/api/exam-schedules')->assertStatus(403);
    }

    public function test_unauthorized_admin_exam_read_payloads_never_reach_students(): void
    {
        Sanctum::actingAs($this->studentA);
        // Assert the gate fires (403) with an empty (null) data payload so no
        // examination data ever reaches a student.
        $body = $this->getJson('/api/exam-results')->assertStatus(403)->json();
        $this->assertNull($body['data']);
        $this->assertSame('Forbidden', $body['message']);
    }

    // -----------------------------------------------------------------
    // P0 — student answer ownership + sanitization
    // -----------------------------------------------------------------

    public function test_student_answers_are_ownership_scoped(): void
    {
        Sanctum::actingAs($this->studentA);
        $payload = $this->getJson('/api/student/exam-answers')->assertStatus(200)->json('data');

        $this->assertCount(1, $payload, 'Student A must only see their own single answer');
        $this->assertSame($this->answerAId, $payload[0]['id']);
        $this->assertNotContains($this->answerBId, array_column($payload, 'id'));
    }

    public function test_student_answer_payload_excludes_grading_truth(): void
    {
        Sanctum::actingAs($this->studentA);
        $payload = $this->getJson('/api/student/exam-answers')->assertStatus(200)->json('data');

        foreach ($payload as $item) {
            $this->assertArrayNotHasKey('is_correct', $item);
            $this->assertArrayNotHasKey('explanation', $item);
            $this->assertArrayNotHasKey('score', $item);
            $this->assertArrayNotHasKey('feedback', $item);
            $this->assertArrayNotHasKey('graded_by', $item);
            $this->assertArrayNotHasKey('graded_at', $item);
            $this->assertArrayNotHasKey('exam_attempt_id', $item);
        }
    }

    public function test_student_participant_payload_excludes_internal_fields(): void
    {
        Sanctum::actingAs($this->studentA);
        $payload = $this->getJson('/api/student/exam-participants')->assertStatus(200)->json('data');

        $this->assertCount(1, $payload, 'Student A must only see their own participant row');
        $this->assertSame($this->participantAId, $payload[0]['id']);
        $this->assertArrayNotHasKey('ip_address', $payload[0]);
        $this->assertArrayNotHasKey('current_session_id', $payload[0]);
        $this->assertArrayNotHasKey('last_activity_at', $payload[0]);
        $this->assertArrayNotHasKey('blocked_reason', $payload[0]);
    }

    public function test_student_result_payload_excludes_nested_participant_internals(): void
    {
        Sanctum::actingAs($this->studentA);
        $payload = $this->getJson('/api/student/exam-results')->assertStatus(200)->json('data');

        $this->assertCount(1, $payload);
        $this->assertSame('A', $payload[0]['grade']);
        $this->assertSame('graded', $payload[0]['status']);

        $encoded = json_encode($payload);
        $this->assertStringNotContainsString('ip_address', $encoded);
        $this->assertStringNotContainsString('current_session_id', $encoded);
        $this->assertStringNotContainsString('blocked_reason', $encoded);
        $this->assertStringNotContainsString('Siswa B', $encoded);
    }

    // -----------------------------------------------------------------
    // P0 — attempt IDOR (cross-student)
    // -----------------------------------------------------------------

    public function test_student_cannot_read_another_students_attempt(): void
    {
        Sanctum::actingAs($this->studentA);
        $this->getJson("/api/student/exam-attempts/{$this->attemptB->id}")->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // P1 — client authority (server remains source of truth)
    // -----------------------------------------------------------------

    public function test_client_cannot_smuggle_grading_truth_into_attempt_answer(): void
    {
        Sanctum::actingAs($this->studentA);
        $attemptId = $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->examId])
            ->assertStatus(200)
            ->json('data.id');

        // Resolve the snapshot attempt-question id for the fixture question.
        $attemptQuestion = collect($this->getJson("/api/student/exam-attempts/{$attemptId}/questions")->json('data.questions'))
            ->firstWhere('source_question_id', $this->questionId);
        $this->assertNotNull($attemptQuestion);

        // Smuggle grading truth the client must never control.
        $response = $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$attemptQuestion['id']}", [
            'selected_option_id' => null,
            'essay_answer' => null,
            'is_correct' => true,
            'score' => 1000,
            'grade' => 'A+',
            'passed' => true,
        ]);
        $response->assertStatus(200);

        $stored = ExamAnswer::where('exam_attempt_id', $attemptId)
            ->where('attempt_question_id', $attemptQuestion['id'])
            ->first();
        $this->assertNotNull($stored);
        $this->assertNull($stored->is_correct, 'client-supplied is_correct must be ignored');
        $this->assertNull($stored->essay_answer);
    }

    public function test_admin_answer_request_no_longer_accepts_is_correct(): void
    {
        // The admin Store/Update requests must not expose grading truth to the
        // client either — verification is split after the app-boot requests:
        // the validation rule lists are the boundary. Run a store with a
        // forged is_correct and ensure the stored answer records is_correct=null.
        Sanctum::actingAs($this->admin);
        $res = $this->postJson('/api/exam-answers', [
            'participant_id' => $this->participantAId,
            'question_id' => $this->questionId,
            'essay_answer' => 'from admin',
            'is_correct' => true,
            'answered_at' => now()->toDateTimeString(),
        ]);
        $res->assertStatus(201);
        $id = $res->json('data.id');
        $stored = ExamAnswer::find($id);
        $this->assertNotNull($stored);
        $this->assertNull($stored->is_correct, 'admin request must not persist client-supplied is_correct');
    }

    // -----------------------------------------------------------------
    // B10 — admin result per-attempt contract
    // -----------------------------------------------------------------

    public function test_admin_store_result_binds_attempt_and_uses_authoritative_scoring(): void
    {
        Sanctum::actingAs($this->admin);

        // Attempt has no result yet; store with forged manual score fields
        // must produce the attempt-bound, scoring-generated result.
        $res = $this->postJson('/api/exam-results', [
            'exam_attempt_id' => $this->attemptNoResult->id,
            'participant_id' => 999,
            'total_score' => 999,
            'correct_count' => 99,
            'grade' => 'A+',
            'status' => 'graded',
        ]);
        $res->assertStatus(201);
        $this->assertSame($this->attemptNoResult->id, (int) $res->json('data.exam_attempt_id'));
        $this->assertSame(0, (int) $res->json('data.total_score'), 'manual score inputs are never authoritative');

        $stored = ExamResult::where('exam_attempt_id', $this->attemptNoResult->id)->first();
        $this->assertNotNull($stored);
        $this->assertSame(0, (int) $stored->total_score);
        $this->assertSame($this->participantBId, $stored->participant_id, 'participant identity derived from the attempt');
    }

    public function test_admin_store_duplicate_attempt_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/exam-results', ['exam_attempt_id' => $this->attemptNoResult->id])->assertStatus(201);

        $res = $this->postJson('/api/exam-results', ['exam_attempt_id' => $this->attemptNoResult->id]);
        $res->assertStatus(422);
        $this->assertSame(1, ExamResult::where('exam_attempt_id', $this->attemptNoResult->id)->count(), 'existing result must remain intact');
    }

    public function test_admin_result_resource_exposes_attempt_identity(): void
    {
        Sanctum::actingAs($this->admin);
        $res = $this->getJson("/api/exam-results/{$this->resultAId}")->assertStatus(200);
        $this->assertSame($this->attemptA->id, (int) $res->json('data.exam_attempt_id'));
        $this->assertSame(1, (int) $res->json('data.attempt_number'));
    }

    public function test_admin_result_resource_marks_legacy_rows_without_attempt_number(): void
    {
        Sanctum::actingAs($this->admin);
        $res = $this->getJson("/api/exam-results/{$this->legacyResultId}")->assertStatus(200);
        $this->assertNull($res->json('data.exam_attempt_id'));
        $this->assertNull($res->json('data.attempt_number'));
    }

    // -----------------------------------------------------------------
    // B11 — admin attempt selector + result-create eligibility
    // -----------------------------------------------------------------

    public function test_admin_can_list_eligible_attempts(): void
    {
        Sanctum::actingAs($this->admin);
        $res = $this->getJson('/api/exam-attempts')->assertStatus(200);
        $rows = $res->json('data');

        // submitted (with + without result) and expired attempts are included;
        // the active attempt is excluded from the eligible selector.
        $ids = array_column($rows, 'id');
        $this->assertContains($this->attemptA->id, $ids, 'submitted attempt with result is selectable context');
        $this->assertContains($this->attemptNoResult->id, $ids, 'submitted attempt without result included');
        $this->assertContains($this->attemptExpired->id, $ids, 'expired attempt included');
        $this->assertNotContains($this->attemptB->id, $ids, 'active attempt excluded by default');

        // has_result derived server-side.
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }
        $this->assertTrue($byId[$this->attemptA->id]['has_result']);
        $this->assertFalse($byId[$this->attemptNoResult->id]['has_result']);
        $this->assertFalse($byId[$this->attemptExpired->id]['has_result']);

        // attempt_number + student/exam labels present, no internals exposed.
        foreach ($rows as $row) {
            $this->assertIsInt($row['attempt_number']);
            $this->assertNotNull($row['exam']['title']);
            $this->assertNotNull($row['exam']['subject']['name']);
            $this->assertNotNull($row['participant']['student']['name']);
        }
        $encoded = json_encode($rows);
        $this->assertStringNotContainsString('token', $encoded);
        $this->assertStringNotContainsString('question_order', $encoded);
        $this->assertStringNotContainsString('total_score', $encoded);
        $this->assertStringNotContainsString('essay_answer', $encoded);
    }

    public function test_admin_attempt_list_requires_admin_authorization(): void
    {
        Sanctum::actingAs($this->guru);
        $this->getJson('/api/exam-attempts')->assertStatus(403);
    }

    public function test_admin_attempt_list_filters(): void
    {
        Sanctum::actingAs($this->admin);

        $statusFiltered = $this->getJson('/api/exam-attempts?status=submitted')->assertStatus(200)->json('data');
        $ids = array_column($statusFiltered, 'id');
        $this->assertContains($this->attemptNoResult->id, $ids);
        $this->assertNotContains($this->attemptExpired->id, $ids, 'status filter cannot broaden beyond eligible set');

        $searchedA = $this->getJson('/api/exam-attempts?search=Siswa%20A')->assertStatus(200)->json('data');
        $searchIdsA = array_column($searchedA, 'id');
        $this->assertContains($this->attemptA->id, $searchIdsA);
        $this->assertNotContains($this->attemptNoResult->id, $searchIdsA, 'other students attempts must not match search');
        $this->assertNotContains($this->attemptB->id, $searchIdsA);

        $searchedB = $this->getJson('/api/exam-attempts?search=Siswa%20B')->assertStatus(200)->json('data');
        $searchIdsB = array_column($searchedB, 'id');
        $this->assertContains($this->attemptNoResult->id, $searchIdsB);
        $this->assertContains($this->attemptExpired->id, $searchIdsB);
        $this->assertNotContains($this->attemptB->id, $searchIdsB, 'active attempt excluded even when search matches');
    }

    public function test_admin_store_active_attempt_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $res = $this->postJson('/api/exam-results', ['exam_attempt_id' => $this->attemptB->id]);
        $res->assertStatus(422);
        $this->assertSame(0, ExamResult::where('exam_attempt_id', $this->attemptB->id)->count(), 'no result may be created for an active attempt');
    }

    public function test_admin_store_expired_attempt_creates_result(): void
    {
        Sanctum::actingAs($this->admin);
        $res = $this->postJson('/api/exam-results', ['exam_attempt_id' => $this->attemptExpired->id]);
        $res->assertStatus(201);
        $this->assertSame($this->attemptExpired->id, (int) $res->json('data.exam_attempt_id'));
        $this->assertSame(1, ExamResult::where('exam_attempt_id', $this->attemptExpired->id)->count());
    }

    // -----------------------------------------------------------------
    // B18 — participant deletion guard (history preservation)
    // -----------------------------------------------------------------

    public function test_admin_can_delete_participant_without_attempts(): void
    {
        $exam2 = Exam::create([
            'subject_id' => \App\Models\Academic\Subject::first()->id,
            'title' => 'Ujian Tanpa Attempt',
            'duration_minutes' => 60,
            'status' => 'published',
        ]);
        $participant = ExamParticipant::create([
            'exam_id' => $exam2->id,
            'student_id' => $this->studentAId,
            'exam_card_number' => 'CARD-NO-ATTEMPT',
        ]);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/exam-participants/{$participant->id}")->assertStatus(200);
        $this->assertNull(ExamParticipant::find($participant->id), 'participant without attempts can be deleted');
    }

    public function test_admin_cannot_delete_participant_with_active_attempt(): void
    {
        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/exam-participants/{$this->participantBId}")->assertStatus(422);

        $this->assertNotNull(ExamParticipant::find($this->participantBId), 'participant must remain');
        $this->assertNotNull(ExamAttempt::find($this->attemptB->id), 'active attempt must remain');
    }

    public function test_admin_cannot_delete_participant_with_submitted_attempt_preserves_history(): void
    {
        $aq = ExamAttemptQuestion::create([
            'exam_attempt_id' => $this->attemptA->id,
            'source_question_id' => $this->questionId,
            'question_text' => 'Historic Q',
            'question_type' => 'multiple_choice',
            'points' => 5,
            'position' => 1,
        ]);
        ExamAttemptQuestionOption::create([
            'attempt_question_id' => $aq->id,
            'source_option_id' => null,
            'option_text' => 'Historic A',
            'position' => 1,
            'is_correct' => true,
        ]);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/exam-participants/{$this->participantAId}")->assertStatus(422);

        $this->assertNotNull(ExamParticipant::find($this->participantAId));
        $this->assertSame('submitted', ExamAttempt::find($this->attemptA->id)->status, 'submitted attempt remains');
        $this->assertNotNull(ExamAttemptQuestion::find($aq->id), 'question snapshot remains');
        $this->assertSame(1, ExamAttemptQuestionOption::where('attempt_question_id', $aq->id)->count(), 'snapshot options remain');
        $this->assertNotNull(ExamResult::find($this->resultAId), 'result remains');
        $this->assertNotNull(ExamAnswer::where('participant_id', $this->participantAId)->first(), 'answers remain');
    }

    public function test_non_admin_cannot_delete_participant(): void
    {
        Sanctum::actingAs($this->studentA);
        $this->deleteJson("/api/exam-participants/{$this->participantAId}")->assertStatus(403);
        $this->assertNotNull(ExamParticipant::find($this->participantAId));
    }
}