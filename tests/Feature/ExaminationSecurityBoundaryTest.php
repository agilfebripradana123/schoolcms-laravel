<?php

namespace Tests\Feature;

use App\Models\Academic\Subject;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamAnswer;
use App\Models\Examination\ExamAttempt;
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
            $t->unsignedBigInteger('participant_id');
            $t->unsignedBigInteger('question_id');
            $t->unsignedBigInteger('selected_option_id')->nullable();
            $t->text('essay_answer')->nullable();
            $t->boolean('is_correct')->nullable();
            $t->dateTime('answered_at');
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
            $t->decimal('total_score', 10, 2)->default(0);
            $t->unsignedInteger('correct_count')->default(0);
            $t->unsignedInteger('wrong_count')->default(0);
            $t->unsignedInteger('unanswered_count')->default(0);
            $t->string('grade', 5)->nullable();
            $t->string('status')->default('pending');
            $t->dateTime('graded_at')->nullable();
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

        ExamResult::create([
            'participant_id' => $participantA->id,
            'total_score' => 90,
            'grade' => 'A',
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

        // Smuggle grading truth the client must never control.
        $response = $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$this->questionId}", [
            'selected_option_id' => null,
            'essay_answer' => null,
            'is_correct' => true,
            'score' => 1000,
            'grade' => 'A+',
            'passed' => true,
        ]);
        $response->assertStatus(200);

        $stored = ExamAnswer::where('exam_attempt_id', $attemptId)
            ->where('question_id', $this->questionId)
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
}