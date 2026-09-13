<?php

namespace Tests\Feature;

use App\Models\Academic\Subject;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamAnswer;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamAttemptQuestion;
use App\Models\Examination\ExamAttemptQuestionOption;
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
 * Phase 2G — Question & attempt snapshot (historical integrity).
 *
 * Hermetic suite; builds its own schema on the default (sqlite :memory:)
 * connection in setUp — never touches live MySQL.
 */
class ExamAttemptSnapshotTest extends TestCase
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
            $t->string('code')->nullable();
            $t->text('question_text');
            $t->string('question_image')->nullable();
            $t->string('type');
            $t->string('difficulty')->default('medium');
            $t->text('explanation')->nullable();
            $t->unsignedInteger('points')->default(1);
            $t->string('status')->default('approved');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('question_options', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('question_id');
            $t->text('option_text');
            $t->string('option_image', 500)->nullable();
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
            $t->unsignedBigInteger('schedule_id')->nullable();
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
            $t->dateTime('answered_at');
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
    }

    private function seedFixture(): void
    {
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleSiswa = Role::create(['name' => 'Siswa']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@2g.test', 'username' => 'admin', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $userA = User::create(['name' => 'Siswa A', 'email' => 'a@2g.test', 'username' => 'a', 'password' => 'x', 'role_id' => $roleSiswa->id]);
        $userB = User::create(['name' => 'Siswa B', 'email' => 'b@2g.test', 'username' => 'b', 'password' => 'x', 'role_id' => $roleSiswa->id]);
        $this->studentA = Student::create(['user_id' => $userA->id, 'name' => 'Siswa A', 'nis' => 'A01']);
        $this->studentB = Student::create(['user_id' => $userB->id, 'name' => 'Siswa B', 'nis' => 'A02']);

        $mtk = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);

        $this->q1 = $this->makeQuestion($mtk, 'Q1?', 10, 'Jakarta', 'Bandung', true);
        $this->q2 = $this->makeQuestion($mtk, 'Q2?', 20, 'Merah', 'Biru', true);
        $this->q3 = $this->makeQuestion($mtk, 'Q3?', 5, 'X1', 'X2', true);
        $this->q4 = $this->makeQuestion($mtk, 'Q4?', 5, 'Y1', 'Y2', true);

        // Composed exam: q1 (10pts) pos1, q2 (20pts) pos2.
        $this->exam = Exam::create([
            'subject_id' => $mtk->id,
            'title' => 'Snapshot Exam',
            'duration_minutes' => 60,
            'max_attempts' => 2,
            'status' => 'published',
        ]);
        ExamQuestion::create(['exam_id' => $this->exam->id, 'question_id' => $this->q1->id, 'position' => 1, 'points' => 10]);
        ExamQuestion::create(['exam_id' => $this->exam->id, 'question_id' => $this->q2->id, 'position' => 2, 'points' => 20]);
        $this->participantA = ExamParticipant::create(['exam_id' => $this->exam->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-A', 'status' => 'registered', 'login_allowed' => true]);
        ExamParticipant::create(['exam_id' => $this->exam->id, 'student_id' => $this->studentB->id, 'exam_card_number' => 'CARD-B', 'status' => 'registered', 'login_allowed' => true]);

        // Shuffle exam.
        $this->shuffleExam = Exam::create([
            'subject_id' => $mtk->id,
            'title' => 'Shuffle Exam',
            'duration_minutes' => 30,
            'max_attempts' => 1,
            'shuffle_questions' => true,
            'shuffle_options' => true,
            'status' => 'published',
        ]);
        ExamQuestion::create(['exam_id' => $this->shuffleExam->id, 'question_id' => $this->q3->id, 'position' => 1, 'points' => 5]);
        ExamQuestion::create(['exam_id' => $this->shuffleExam->id, 'question_id' => $this->q4->id, 'position' => 2, 'points' => 5]);
        ExamParticipant::create(['exam_id' => $this->shuffleExam->id, 'student_id' => $this->studentA->id, 'exam_card_number' => 'CARD-S', 'status' => 'registered', 'login_allowed' => true]);
    }

    private function makeQuestion(Subject $subject, string $text, int $points, string $optA, string $optB, bool $aCorrect): QuestionBank
    {
        $q = QuestionBank::create([
            'subject_id' => $subject->id,
            'question_text' => $text,
            'type' => 'multiple_choice',
            'difficulty' => 'medium',
            'points' => $points,
            'status' => 'approved',
        ]);
        QuestionOption::create(['question_id' => $q->id, 'option_text' => $optA, 'is_correct' => $aCorrect]);
        QuestionOption::create(['question_id' => $q->id, 'option_text' => $optB, 'is_correct' => !$aCorrect]);

        return $q;
    }

    private function startAsA(int $examId): int
    {
        Sanctum::actingAs($this->studentA->user);

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

    private function apiQuestions(int $attemptId): array
    {
        return $this->getJson("/api/student/exam-attempts/{$attemptId}/questions")->assertStatus(200)->json('data.questions');
    }

    // -----------------------------------------------------------------
    // Snapshot creation
    // -----------------------------------------------------------------

    public function test_attempt_start_creates_complete_snapshot(): void
    {
        $attemptId = $this->startAsA($this->exam->id);

        $this->assertSame(2, ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->count());
        $this->assertSame(4, ExamAttemptQuestionOption::whereIn('attempt_question_id', ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->pluck('id'))->count());

        $aq1 = $this->aqFor($attemptId, $this->q1->id);
        $aq2 = $this->aqFor($attemptId, $this->q2->id);
        $this->assertSame('Q1?', $aq1->question_text);
        $this->assertSame(10, $aq1->points);
        $this->assertSame(1, $aq1->position);
        $this->assertSame(20, $aq2->points);
        $this->assertSame(2, $aq2->position);
        $this->assertSame(['Jakarta', 'Bandung'], $aq1->options->sortBy('position')->pluck('option_text')->all());
        $this->assertTrue($aq1->options->firstWhere('source_option_id', $this->q1->options()->where('is_correct', true)->first()->id)->is_correct);
    }

    public function test_delivery_is_sanitized_snapshot(): void
    {
        $attemptId = $this->startAsA($this->exam->id);
        $questions = $this->apiQuestions($attemptId);

        foreach ($questions as $q) {
            $this->assertArrayNotHasKey('is_correct', $q);
            $this->assertArrayNotHasKey('explanation', $q);
            foreach ($q['options'] as $o) {
                $this->assertArrayNotHasKey('is_correct', $o);
            }
        }
    }

    // -----------------------------------------------------------------
    // Historical isolation
    // -----------------------------------------------------------------

    public function test_question_text_change_does_not_alter_attempt(): void
    {
        $attemptId = $this->startAsA($this->exam->id);
        QuestionBank::where('id', $this->q1->id)->update(['question_text' => 'MUTATED TEXT?']);

        $questions = $this->apiQuestions($attemptId);
        $row = collect($questions)->firstWhere('source_question_id', $this->q1->id);
        $this->assertSame('Q1?', $row['question_text']);
    }

    public function test_option_replacement_does_not_alter_attempt(): void
    {
        $attemptId = $this->startAsA($this->exam->id);

        // Option replacement: delete & recreate live options with new texts.
        $q1 = QuestionBank::find($this->q1->id);
        $q1->options()->delete();
        $q1->options()->create(['option_text' => 'NEW A', 'is_correct' => true]);
        $q1->options()->create(['option_text' => 'NEW B', 'is_correct' => false]);

        $questions = $this->apiQuestions($attemptId);
        $row = collect($questions)->firstWhere('source_question_id', $this->q1->id);
        $this->assertSame(['Jakarta', 'Bandung'], collect($row['options'])->pluck('option_text')->values()->all());
    }

    public function test_points_change_does_not_alter_attempt(): void
    {
        $attemptId = $this->startAsA($this->exam->id);

        QuestionBank::where('id', $this->q1->id)->update(['points' => 99]);
        ExamQuestion::where('exam_id', $this->exam->id)->where('question_id', $this->q1->id)->update(['points' => 77]);

        $questions = $this->apiQuestions($attemptId);
        $row = collect($questions)->firstWhere('source_question_id', $this->q1->id);
        $this->assertSame(10, $row['points'], 'composed weight must be frozen in the snapshot');
    }

    public function test_archived_question_still_serves_active_attempt(): void
    {
        $attemptId = $this->startAsA($this->exam->id);
        $this->q1->delete(); // soft delete

        $questions = $this->apiQuestions($attemptId);
        $this->assertCount(2, $questions, 'delivery does not depend on live QuestionBank');

        // Answering still works against the snapshot identity.
        $aq = $this->aqFor($attemptId, $this->q1->id);
        $correctSnapshotOption = $aq->options->firstWhere('is_correct', true);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$aq->id}", ['selected_option_id' => $correctSnapshotOption->id])->assertStatus(200);
    }

    public function test_question_bank_soft_delete_and_live_lookup_independent(): void
    {
        $attemptId = $this->startAsA($this->exam->id);
        $this->q2->delete();

        $this->aqFor($attemptId, $this->q2->id); // snapshot row still there
        $this->assertCount(2, ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->get());
    }

    // -----------------------------------------------------------------
    // Multi-attempt independence
    // -----------------------------------------------------------------

    public function test_multi_attempt_snapshots_are_independent(): void
    {
        $attempt1 = $this->startAsA($this->exam->id);
        $this->postJson("/api/student/exam-attempts/{$attempt1}/submit")->assertStatus(200);

        QuestionBank::where('id', $this->q1->id)->update(['question_text' => 'MUTATED BEFORE ATTEMPT 2?']);

        $attempt2 = $this->startAsA($this->exam->id);

        $q1a1 = $this->aqFor($attempt1, $this->q1->id);
        $q1a2 = $this->aqFor($attempt2, $this->q1->id);
        $this->assertSame('Q1?', $q1a1->question_text, 'attempt 1 keeps snapshot A');
        $this->assertSame('MUTATED BEFORE ATTEMPT 2?', $q1a2->question_text, 'attempt 2 uses snapshot B');
    }

    // -----------------------------------------------------------------
    // Resume does not recreate snapshot
    // -----------------------------------------------------------------

    public function test_resume_does_not_recreate_snapshot(): void
    {
        $attemptId = $this->startAsA($this->exam->id);
        $rowsBefore = ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->count();

        // Duplicate start -> resume
        $this->startAsA($this->exam->id);
        $this->assertSame($attemptId, ExamAttempt::where('exam_participant_id', $this->participantA->id)->where('status', 'active')->first()->id);
        $this->assertSame($rowsBefore, ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->count(), 'resume must not rebuild the snapshot');

        $ids1 = collect($this->apiQuestions($attemptId))->pluck('id')->all();
        $ids2 = collect($this->apiQuestions($attemptId))->pluck('id')->all();
        $this->assertSame($ids1, $ids2);
    }

    // -----------------------------------------------------------------
    // Answer integrity
    // -----------------------------------------------------------------

    public function test_answer_references_snapshot_identity_and_survives_option_replacement(): void
    {
        $attemptId = $this->startAsA($this->exam->id);
        $aq = $this->aqFor($attemptId, $this->q1->id);
        $correct = $aq->options->firstWhere('is_correct', true);
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$aq->id}", ['selected_option_id' => $correct->id])->assertStatus(200);

        // Replace live options entirely.
        $q1 = QuestionBank::find($this->q1->id);
        $q1->options()->delete();
        $q1->options()->create(['option_text' => 'NEW', 'is_correct' => true]);

        // Submit -> score computed from snapshot.
        $res = $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $res->assertStatus(200)->assertJsonPath('data.result.total_score', 10)->assertJsonPath('data.result.correct_count', 1);

        $stored = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aq->id)->first();
        $this->assertNotNull($stored->attempt_question_id);
        $this->assertSame($correct->id, $stored->selected_attempt_option_id);
        $this->assertTrue($stored->is_correct);
    }

    public function test_client_cannot_set_grading_truth(): void
    {
        $attemptId = $this->startAsA($this->exam->id);
        $aq = $this->aqFor($attemptId, $this->q1->id);
        $wrong = $aq->options->firstWhere('is_correct', false);

        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$aq->id}", [
            'selected_option_id' => $wrong->id,
            'is_correct' => true,
            'score' => 999,
        ])->assertStatus(200);

        $stored = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aq->id)->first();
        $this->assertFalse($stored->is_correct, 'correctness comes from the snapshot, not the client');
    }

    public function test_answer_for_other_attempt_rejected(): void
    {
        $attempt1 = $this->startAsA($this->exam->id);
        $this->postJson("/api/student/exam-attempts/{$attempt1}/submit")->assertStatus(200);
        $attempt2 = $this->startAsA($this->exam->id);

        $q1OfAttempt1 = $this->aqFor($attempt1, $this->q1->id);
        $this->putJson("/api/student/exam-attempts/{$attempt2}/answers/{$q1OfAttempt1->id}", ['selected_option_id' => null])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Shuffle persistence
    // -----------------------------------------------------------------

    public function test_shuffle_order_is_persistent_across_requests(): void
    {
        $attemptId = $this->startAsA($this->shuffleExam->id);

        $r1 = $this->apiQuestions($attemptId);
        $r2 = $this->apiQuestions($attemptId);

        $this->assertSame(collect($r1)->pluck('id')->all(), collect($r2)->pluck('id')->all());
        foreach ($r1 as $i => $q) {
            $this->assertSame(collect($r1[$i]['options'])->pluck('id')->all(), collect($r2[$i]['options'])->pluck('id')->all());
        }
    }

    // -----------------------------------------------------------------
    // Ownership / security
    // -----------------------------------------------------------------

    public function test_student_cannot_access_other_students_snapshot(): void
    {
        $attemptA = $this->startAsA($this->exam->id);

        Sanctum::actingAs($this->studentB->user);
        $this->getJson("/api/student/exam-attempts/{$attemptA}/questions")->assertStatus(404);

        $aq = $this->aqFor($attemptA, $this->q1->id);
        $this->putJson("/api/student/exam-attempts/{$attemptA}/answers/{$aq->id}", ['selected_option_id' => null])->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Legacy compatibility
    // -----------------------------------------------------------------

    public function test_legacy_attempt_without_snapshot_remains_readable(): void
    {
        // A directly-created attempt row with no snapshot rows (legacy data).
        $legacy = ExamAttempt::create([
            'exam_participant_id' => $this->participantA->id,
            'exam_id' => $this->exam->id,
            'attempt_number' => 1,
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);
        $this->assertSame(0, ExamAttemptQuestion::where('exam_attempt_id', $legacy->id)->count());

        Sanctum::actingAs($this->studentA->user);
        $this->getJson("/api/student/exam-attempts/{$legacy->id}")->assertStatus(200);
        $this->getJson("/api/student/exam-attempts/{$legacy->id}/questions")->assertStatus(200)->assertJsonCount(0, 'data.questions');
    }
}