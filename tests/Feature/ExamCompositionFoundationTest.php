<?php

namespace Tests\Feature;

use App\Models\Academic\Subject;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamParticipant;
use App\Models\Examination\ExamQuestion;
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
 * Phase 2D — Exam Composition foundation.
 *
 * Hermetic suite; builds its own schema on the default (sqlite :memory:)
 * connection in setUp — never touches live MySQL.
 */
class ExamCompositionFoundationTest extends TestCase
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

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@2d.test', 'username' => 'admin', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $this->guru = User::create(['name' => 'Guru', 'email' => 'guru@2d.test', 'username' => 'guru', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $studentUser = User::create(['name' => 'Siswa', 'email' => 's@2d.test', 'username' => 's', 'password' => 'x', 'role_id' => $roleSiswa->id]);

        $this->student = Student::create(['user_id' => $studentUser->id, 'name' => 'Siswa', 'nis' => 'S001']);

        $mtk = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        $ipa = Subject::create(['code' => 'IPA', 'name' => 'IPA']);

        $this->qApproved = $this->makeQuestion($mtk, 'approved', 'Q1?', 10);
        $this->qSecond = $this->makeQuestion($mtk, 'approved', 'Q2?', 20);
        $this->qDraft = $this->makeQuestion($mtk, 'draft', 'Q3?', 5);
        $this->qArchived = $this->makeQuestion($mtk, 'archived', 'Q4?', 5);
        $this->qOtherSubject = $this->makeQuestion($ipa, 'approved', 'Q5?', 5);

        // Draft exam -> composition editable.
        $this->draftExam = Exam::create([
            'subject_id' => $mtk->id,
            'title' => 'Draft Exam',
            'duration_minutes' => 60,
            'status' => 'draft',
        ]);

        // Operational exam -> composition protected.
        $this->publishedExam = Exam::create([
            'subject_id' => $mtk->id,
            'title' => 'Published Exam',
            'duration_minutes' => 60,
            'total_questions' => 1,
            'status' => 'published',
        ]);

        // Composed published exam for attempt tests.
        $this->composedExam = Exam::create([
            'subject_id' => $mtk->id,
            'title' => 'Composed Exam',
            'duration_minutes' => 60,
            'total_questions' => 99,
            'status' => 'published',
        ]);
        ExamQuestion::create(['exam_id' => $this->composedExam->id, 'question_id' => $this->qSecond->id, 'position' => 1, 'points' => 20]);
        ExamQuestion::create(['exam_id' => $this->composedExam->id, 'question_id' => $this->qApproved->id, 'position' => 2, 'points' => 10]);

        // Legacy published exam (no composition) for fallback test.
        $this->legacyExam = Exam::create([
            'subject_id' => $mtk->id,
            'title' => 'Legacy Exam',
            'duration_minutes' => 60,
            'total_questions' => 1,
            'status' => 'published',
        ]);

        foreach ([$this->publishedExam, $this->composedExam, $this->legacyExam] as $exam) {
            ExamParticipant::create([
                'exam_id' => $exam->id,
                'student_id' => $this->student->id,
                'exam_card_number' => 'CARD-'.$exam->id,
                'status' => 'registered',
                'login_allowed' => true,
            ]);
        }
    }

    private function makeQuestion(Subject $subject, string $status, string $text, int $points): QuestionBank
    {
        $q = QuestionBank::create([
            'subject_id' => $subject->id,
            'question_text' => $text,
            'type' => 'multiple_choice',
            'difficulty' => 'medium',
            'points' => $points,
            'status' => $status,
        ]);
        QuestionOption::create(['question_id' => $q->id, 'option_text' => 'A', 'is_correct' => true]);
        QuestionOption::create(['question_id' => $q->id, 'option_text' => 'B', 'is_correct' => false]);

        return $q;
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    // -----------------------------------------------------------------
    // A. create composition
    // -----------------------------------------------------------------

    public function test_admin_can_add_approved_question(): void
    {
        $this->actAsAdmin();
        $res = $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id]);
        $res->assertStatus(201);
        $data = $res->json('data');
        $this->assertSame($this->draftExam->id, $data['exam_id']);
        $this->assertSame($this->qApproved->id, $data['question_id']);
        $this->assertSame(1, $data['position'], 'auto position defaults to 1');
        $this->assertSame(10, $data['points'], 'points inherit the question points when not supplied');
        $this->assertSame('Q1?', $data['question']['question_text']);

        $this->assertDatabaseHas('exam_questions', ['exam_id' => $this->draftExam->id, 'question_id' => $this->qApproved->id], null);
    }

    public function test_relationship_maps_back_to_exam(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id])->json('data.id');
        $eq = ExamQuestion::with('exam', 'question')->find($id);
        $this->assertSame($this->draftExam->id, $eq->exam->id);
        $this->assertSame($this->qApproved->id, $eq->question->id);
    }

    // -----------------------------------------------------------------
    // B. duplicate protection
    // -----------------------------------------------------------------

    public function test_same_question_cannot_be_added_twice(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id])->assertStatus(201);
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id])->assertStatus(422);
        $this->assertSame(1, ExamQuestion::where('exam_id', $this->draftExam->id)->where('question_id', $this->qApproved->id)->count());
    }

    // -----------------------------------------------------------------
    // C. subject compatibility
    // -----------------------------------------------------------------

    public function test_matching_subject_succeeds(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qSecond->id])->assertStatus(201);
    }

    public function test_mismatched_subject_rejected(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qOtherSubject->id])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // D. question validity
    // -----------------------------------------------------------------

    public function test_missing_exam_rejected(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exams/99999/questions', ['question_id' => $this->qApproved->id])->assertStatus(404);
    }

    public function test_missing_question_rejected(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => 99999])->assertStatus(422);
    }

    public function test_deleted_question_rejected(): void
    {
        $this->actAsAdmin();
        $this->qApproved->delete();
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // E. question status
    // -----------------------------------------------------------------

    public function test_draft_question_rejected(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qDraft->id])->assertStatus(422);
    }

    public function test_archived_question_rejected(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qArchived->id])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // F. ordering
    // -----------------------------------------------------------------

    public function test_explicit_position_accepted(): void
    {
        $this->actAsAdmin();
        $data = $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id, 'position' => 5])->json('data');
        $this->assertSame(5, $data['position']);
    }

    public function test_invalid_position_rejected(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id, 'position' => 0])->assertStatus(422);
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id, 'position' => -1])->assertStatus(422);
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id, 'position' => 'abc'])->assertStatus(422);
    }

    public function test_duplicate_position_rejected(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id, 'position' => 3])->assertStatus(201);
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qSecond->id, 'position' => 3])->assertStatus(422);
    }

    public function test_reorder_is_atomic_and_normalises_positions(): void
    {
        $this->actAsAdmin();
        $a = $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id, 'position' => 1])->json('data.id');
        $b = $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qSecond->id, 'position' => 2])->json('data.id');

        $res = $this->putJson('/api/exams/'.$this->draftExam->id.'/questions/reorder', ['ordered' => [$b, $a]]);
        $res->assertStatus(200);

        $positions = collect($res->json('data'))->pluck('position')->all();
        $this->assertSame([1, 2], $positions);
        $this->assertSame($this->qSecond->id, $res->json('data.0.question_id'), 'first question after reorder');
        $this->assertSame($this->qApproved->id, $res->json('data.1.question_id'), 'second question after reorder');
    }

    public function test_reorder_rejects_foreign_composition(): void
    {
        $this->actAsAdmin();
        $other = $this->postJson('/api/exams/'.$this->publishedExam->id.'/questions', ['question_id' => $this->qApproved->id])->assertStatus(422)->json();
        $this->assertSame('Exam composition is locked for the current exam status.', $other['message']);

        $foreign = $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id])->json('data.id');

        // reference the draft-exam composition from a second draft exam
        $exam2 = Exam::create(['subject_id' => $this->qApproved->subject_id, 'title' => 'Exam 2', 'duration_minutes' => 30, 'status' => 'draft']);
        $this->putJson('/api/exams/'.$exam2->id.'/questions/reorder', ['ordered' => [$foreign]])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // G. weight (points)
    // -----------------------------------------------------------------

    public function test_positive_points_accepted(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id, 'points' => 5])->assertStatus(201)->assertJsonPath('data.points', 5);
    }

    public function test_zero_or_negative_points_rejected(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id, 'points' => 0])->assertStatus(422);
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id, 'points' => -5])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // H. exam lifecycle
    // -----------------------------------------------------------------

    public function test_operational_exam_rejects_composition_mutation(): void
    {
        $this->actAsAdmin();

        foreach (['published', 'ongoing', 'completed', 'archived'] as $status) {
            $exam = Exam::create(['subject_id' => $this->qApproved->subject_id, 'title' => "Exam $status", 'duration_minutes' => 30, 'status' => $status]);
            $this->postJson("/api/exams/{$exam->id}/questions", ['question_id' => $this->qApproved->id])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Exam composition is locked for the current exam status.');
        }
    }

    // -----------------------------------------------------------------
    // I. authorization
    // -----------------------------------------------------------------

    public function test_unauthenticated_rejected(): void
    {
        $this->getJson('/api/exams/'.$this->draftExam->id.'/questions')->assertStatus(401);
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', [])->assertStatus(401);
    }

    public function test_student_rejected(): void
    {
        Sanctum::actingAs($this->student->user);
        $this->getJson('/api/exams/'.$this->draftExam->id.'/questions')->assertStatus(403);
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id])->assertStatus(403);
    }

    public function test_teacher_rejected(): void
    {
        Sanctum::actingAs($this->guru);
        $this->getJson('/api/exams/'.$this->draftExam->id.'/questions')->assertStatus(403);
        $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id])->assertStatus(403);
    }

    public function test_admin_succeeds(): void
    {
        $this->actAsAdmin();
        $this->getJson('/api/exams/'.$this->draftExam->id.'/questions')->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // J. IDOR
    // -----------------------------------------------------------------

    public function test_composition_of_other_exam_cannot_be_mutated_via_wrong_route(): void
    {
        $this->actAsAdmin();
        $exam2 = Exam::create(['subject_id' => $this->qApproved->subject_id, 'title' => 'Exam 2', 'duration_minutes' => 30, 'status' => 'draft']);
        $eqId = $this->postJson('/api/exams/'.$this->draftExam->id.'/questions', ['question_id' => $this->qApproved->id])->json('data.id');

        $this->patchJson('/api/exams/'.$exam2->id.'/questions/'.$eqId, ['position' => 9])->assertStatus(404);
        $this->deleteJson('/api/exams/'.$exam2->id.'/questions/'.$eqId)->assertStatus(404);

        $this->assertNotNull(ExamQuestion::find($eqId), 'composition must not be removed via wrong exam route');
    }

    // -----------------------------------------------------------------
    // L. attempt compatibility: legacy fallback + composed source of truth
    // -----------------------------------------------------------------

    public function test_legacy_exam_falls_back_to_subject_bank(): void
    {
        Sanctum::actingAs($this->student->user);
        $attemptId = $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->legacyExam->id])->assertStatus(200)->json('data.id');

        $questions = $this->getJson("/api/student/exam-attempts/{$attemptId}/questions")->assertStatus(200)->json('data.questions');
        $ids = collect($questions)->pluck('source_question_id')->all();

        // legacy subject bank = [qApproved, qSecond, qDraft, qArchived], sliced to total_questions=1 -> first by id = qApproved
        $this->assertSame([$this->qApproved->id], $ids);
    }

    public function test_composed_exam_uses_explicit_composition_order(): void
    {
        Sanctum::actingAs($this->student->user);
        $attemptId = $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $this->composedExam->id])->assertStatus(200)->json('data.id');

        $questions = $this->getJson("/api/student/exam-attempts/{$attemptId}/questions")->assertStatus(200)->json('data.questions');
        $ids = collect($questions)->pluck('source_question_id')->all();
        $points = collect($questions)->pluck('points')->all();

        // composition authority: [qSecond (pos 1), qApproved (pos 2)] — NOT affected by total_questions=99 slicing
        $this->assertSame([$this->qSecond->id, $this->qApproved->id], $ids);
        $this->assertSame([20, 10], $points, 'composed exam uses exam_questions.points as effective weight');
    }
}