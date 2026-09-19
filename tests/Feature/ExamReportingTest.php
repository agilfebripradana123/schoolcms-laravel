<?php

namespace Tests\Feature;

use App\Models\Academic\AcademicYear;
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
use App\Models\Staff\Teacher;
use App\Models\Staff\TeacherAssignment;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase B15 — read-only examination reporting foundation.
 *
 * Hermetic suite; builds its own schema and fixtures. Covers exam-level
 * effective aggregation, participant counting under multiple attempts, and
 * snapshot-based question reporting.
 */
class ExamReportingTest extends TestCase
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
        Schema::create('teachers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('full_name')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('academic_years', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('classes', function (Blueprint $t) {
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
        Schema::create('teacher_assignments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('teacher_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('academic_year_id');
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
        Schema::create('exams', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('subject_id');
            $t->string('title');
            $t->unsignedInteger('duration_minutes');
            $t->unsignedInteger('passing_score')->default(0);
            $t->unsignedInteger('total_questions')->default(0);
            $t->unsignedInteger('max_attempts')->default(1);
            $t->boolean('shuffle_questions')->default(false);
            $t->boolean('shuffle_options')->default(false);
            $t->boolean('show_result')->default(true);
            $t->string('status')->default('published');
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
        Schema::create('question_banks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('subject_id');
            $t->string('code')->nullable();
            $t->text('question_text');
            $t->string('type');
            $t->string('difficulty')->default('medium');
            $t->unsignedInteger('points')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('question_options', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('question_id');
            $t->string('option_text');
            $t->boolean('is_correct')->default(false);
            $t->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'a@rep.test', 'username' => 'admin', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $tAUser = User::create(['name' => 'Guru MTK', 'email' => 'ta@rep.test', 'username' => 'ta', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $tBUser = User::create(['name' => 'Guru IPA', 'email' => 'tb@rep.test', 'username' => 'tb', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $s1User = User::create(['name' => 'S1', 'email' => 's1@rep.test', 'username' => 's1', 'password' => 'x', 'role_id' => $roleSiswa->id]);
        $s2User = User::create(['name' => 'S2', 'email' => 's2@rep.test', 'username' => 's2', 'password' => 'x', 'role_id' => $roleSiswa->id]);
        $s3User = User::create(['name' => 'S3', 'email' => 's3@rep.test', 'username' => 's3', 'password' => 'x', 'role_id' => $roleSiswa->id]);

        $s1 = Student::create(['user_id' => $s1User->id, 'name' => 'S1', 'nis' => 'R01']);
        $s2 = Student::create(['user_id' => $s2User->id, 'name' => 'S2', 'nis' => 'R02']);
        $s3 = Student::create(['user_id' => $s3User->id, 'name' => 'S3', 'nis' => 'R03']);

        $mtk = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        $ipa = Subject::create(['code' => 'IPA', 'name' => 'IPA']);

        $this->teacherA = $tAUser;
        $this->teacherB = $tBUser;
        $teacherARec = Teacher::create(['user_id' => $tAUser->id, 'full_name' => 'Guru MTK']);
        $teacherBRec = Teacher::create(['user_id' => $tBUser->id, 'full_name' => 'Guru IPA']);
        $year = AcademicYear::create(['name' => '2025/2026']);
        $class = \Illuminate\Support\Facades\DB::table('classes')->insertGetId(['name' => 'X-1', 'created_at' => now(), 'updated_at' => now()]);
        TeacherAssignment::create(['teacher_id' => $teacherARec->id, 'class_id' => $class, 'subject_id' => $mtk->id, 'academic_year_id' => $year->id]);
        TeacherAssignment::create(['teacher_id' => $teacherBRec->id, 'class_id' => $class, 'subject_id' => $ipa->id, 'academic_year_id' => $year->id]);

        $this->s1User = $s1User;
        $this->s2User = $s2User;
        $this->s3User = $s3User;
        $this->s1 = $s1;
        $this->s2 = $s2;
        $this->s3 = $s3;

        $this->exam = Exam::create(['subject_id' => $mtk->id, 'title' => 'Ujian MTK', 'duration_minutes' => 60, 'status' => 'completed']);
        $this->examOther = Exam::create(['subject_id' => $ipa->id, 'title' => 'Ujian IPA', 'duration_minutes' => 60, 'status' => 'completed']);

        // Live question bank content (mutable, NOT used for historical reporting).
        $liveQ = QuestionBank::create(['subject_id' => $mtk->id, 'question_text' => 'LIVE MC', 'type' => 'multiple_choice', 'points' => 10]);
        $liveOptA = QuestionOption::create(['question_id' => $liveQ->id, 'option_text' => 'LIVE A', 'is_correct' => true]);
        $liveOptB = QuestionOption::create(['question_id' => $liveQ->id, 'option_text' => 'LIVE B', 'is_correct' => false]);
        $this->liveEssay = QuestionBank::create(['subject_id' => $mtk->id, 'question_text' => 'LIVE ESSAY', 'type' => 'essay', 'points' => 20]);

        $p1 = ExamParticipant::create(['exam_id' => $this->exam->id, 'student_id' => $s1->id, 'exam_card_number' => 'R-CARD-1']);
        $p2 = ExamParticipant::create(['exam_id' => $this->exam->id, 'student_id' => $s2->id, 'exam_card_number' => 'R-CARD-2']);
        $p3 = ExamParticipant::create(['exam_id' => $this->exam->id, 'student_id' => $s3->id, 'exam_card_number' => 'R-CARD-3']);

        $t0 = Carbon::parse('2026-01-01 08:00:00');
        // S1: two submitted attempts (a1 percent 100, a2 percent 40).
        $a1 = $this->attempt($p1, 1, 'submitted', $t0->copy()->subDays(2), $t0->copy()->subDay(), $s1, $liveQ, $liveOptA, $liveOptB);
        $a2 = $this->attempt($p1, 2, 'submitted', $t0->copy()->subDay(), $t0, $s1, $liveQ, $liveOptA, $liveOptB);
        // S2: a1 submitted percent 70, a2 still active (incomplete).
        $b1 = $this->attempt($p2, 1, 'submitted', $t0->copy()->subDays(3), $t0->copy()->subDays(2), $s2, $liveQ, $liveOptA, $liveOptB);
        $b2 = $this->attempt($p2, 2, 'active', $t0->copy(), null, $s2, $liveQ, $liveOptA, $liveOptB);
        // S3: two submitted attempts with EQUAL submitted_at -> highest id wins.
        $c1 = $this->attempt($p3, 1, 'submitted', $t0->copy()->subDay(), $t0->copy(), $s3, $liveQ, $liveOptA, $liveOptB);
        $c2 = $this->attempt($p3, 2, 'submitted', $t0->copy()->subDay(), $t0->copy(), $s3, $liveQ, $liveOptA, $liveOptB);

        $this->attachResult($a1, 100.00);
        $this->attachResult($a2, 40.00);
        $this->attachResult($b1, 70.00);
        $this->attachResult($c1, 50.00);
        $this->attachResult($c2, 60.00);

        $this->attempts = compact('a1', 'a2', 'b1', 'c1', 'c2');
        $this->liveQ = $liveQ;
        $this->liveOptA = $liveOptA;
        $this->liveOptB = $liveOptB;
    }

    /**
     * Create an attempt with frozen MC + essay snapshot; apply answers.
     */
    private function attempt(ExamParticipant $participant, int $number, string $status, Carbon $startedAt, ?Carbon $submittedAt, Student $student, QuestionBank $liveQ, QuestionOption $liveOptA, QuestionOption $liveOptB): int
    {
        $attempt = ExamAttempt::create([
            'exam_participant_id' => $participant->id,
            'exam_id' => $participant->exam_id,
            'attempt_number' => $number,
            'status' => $status,
            'started_at' => $startedAt,
            'expires_at' => $startedAt->copy()->addMinutes(60),
            'submitted_at' => $submittedAt,
        ]);

        [$mc, $mcCorrect, $mcWrong] = $this->snapshotQuestion($attempt, $liveQ, $liveOptA, $liveOptB, 'MC?', 'multiple_choice', 10, 1);
        $this->snapshotQuestion($attempt, $this->liveEssay, null, null, 'ESAI?', 'essay', 20, 2);

        $answered = $attempt->status === 'submitted';
        if ($answered) {
            // First submitted attempt answers MC correctly; second submitted attempt answers MC wrongly (unless student S3 which varies per attempt).
            $correctSelection = $student->name === 'S3' ? ($number === 2 ? null : $mcCorrect) : ($number === 1 ? $mcCorrect : $mcWrong);
            if ($correctSelection === null) {
                // S3 attempt 2: unanswered.
                $this->answer($attempt, $mc, $student, null, null, null);
            } else {
                $this->answer($attempt, $mc, $student, $correctSelection, $correctSelection === $mcCorrect, null);
            }
            $this->answer($attempt, $this->aqForEssay($attempt), $student, null, null, 'Jawaban esai.');
        }

        return $attempt->id;
    }

    private function snapshotQuestion(ExamAttempt $attempt, QuestionBank $liveQ, ?QuestionOption $liveOptA, ?QuestionOption $liveOptB, string $text, string $type, int $points, int $position): array
    {
        $aq = ExamAttemptQuestion::create([
            'exam_attempt_id' => $attempt->id,
            'source_question_id' => $liveQ->id,
            'question_text' => $text,
            'question_type' => $type,
            'points' => $points,
            'position' => $position,
        ]);

        if ($type !== 'essay') {
            $optA = ExamAttemptQuestionOption::create(['attempt_question_id' => $aq->id, 'source_option_id' => $liveOptA->id, 'option_text' => 'Frozen A', 'position' => 1, 'is_correct' => true]);
            $optB = ExamAttemptQuestionOption::create(['attempt_question_id' => $aq->id, 'source_option_id' => $liveOptB->id, 'option_text' => 'Frozen B', 'position' => 2, 'is_correct' => false]);

            return [$aq, $optA->id, $optB->id];
        }

        return [$aq, null, null];
    }

    private function aqForEssay(ExamAttempt $attempt): ExamAttemptQuestion
    {
        return ExamAttemptQuestion::where('exam_attempt_id', $attempt->id)->where('question_type', 'essay')->firstOrFail();
    }

    private function answer(ExamAttempt $attempt, ExamAttemptQuestion $aq, Student $student, ?int $optionId, ?bool $isCorrect, ?string $essay): void
    {
        ExamAnswer::create([
            'exam_attempt_id' => $attempt->id,
            'attempt_question_id' => $aq->id,
            'participant_id' => $attempt->exam_participant_id,
            'question_id' => $aq->source_question_id,
            'selected_option_id' => $optionId,
            'selected_attempt_option_id' => $optionId,
            'essay_answer' => $essay,
            'is_correct' => $isCorrect,
            'grade_status' => $essay !== null ? 'pending_manual' : null,
            'answered_at' => now(),
        ]);
    }

    private function attachResult(int $attemptId, float $percentage): void
    {
        $attempt = ExamAttempt::find($attemptId);
        ExamResult::create([
            'participant_id' => $attempt->exam_participant_id,
            'exam_attempt_id' => $attemptId,
            'total_score' => 0,
            'correct_count' => 0,
            'wrong_count' => 0,
            'unanswered_count' => 0,
            'percentage' => $percentage,
            'grade' => 'A',
            'status' => 'graded',
        ]);
    }

    // -----------------------------------------------------------------
    // Exam summary
    // -----------------------------------------------------------------

    public function test_summary_counts_participants_once_despite_multiple_attempts(): void
    {
        Sanctum::actingAs($this->admin);
        $res = $this->getJson("/api/exam-reports/{$this->exam->id}")->assertStatus(200)->json('data.summary');

        $this->assertSame(3, $res['participant_count'], 'three unique participants');
        $this->assertSame(6, $res['attempt_count'], 'six attempts total');
        $this->assertSame(5, $res['submitted_attempt_count']);
        $this->assertSame(1, $res['incomplete_attempt_count'], 'one active attempt is incomplete');
        $this->assertSame(3, $res['effective_attempt_count'], 'one effective attempt per participant');
    }

    public function test_effective_percentage_uses_latest_submitted_attempt_and_ignores_older(): void
    {
        Sanctum::actingAs($this->admin);
        $summary = $this->getJson("/api/exam-reports/{$this->exam->id}")->assertStatus(200)->json('data.summary');

        // Effective rows: S1 latest (40), S2 (70), S3 latest (60, tie broken by id).
        $this->assertEquals(56.67, $summary['average_percentage'], 'avg', 0.001);
        $this->assertEquals(40.0, $summary['minimum_percentage'], 'min', 0.001);
        $this->assertEquals(70.0, $summary['maximum_percentage'], 'max', 0.001);
    }

    public function test_effective_attempt_selection_is_deterministic_on_equal_timestamps(): void
    {
        Sanctum::actingAs($this->admin);
        $summary = $this->getJson("/api/exam-reports/{$this->exam->id}")->assertStatus(200)->json('data.summary');

        // S3 has two submitted attempts with equal submitted_at; the higher id
        // (a2, percent 60) must be selected over (a1, percent 50).
        $this->assertEquals(56.67, $summary['average_percentage'], 'avg picks 60 not 50', 0.001);
        $this->assertEquals(40.0, $summary['minimum_percentage'], 'min', 0.001);
        $this->assertEquals(70.0, $summary['maximum_percentage'], 'max', 0.001);
    }

    // -----------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------

    public function test_teacher_can_report_exam_within_subject_scope(): void
    {
        Sanctum::actingAs($this->teacherA);
        $this->getJson("/api/teacher/exam-reports/{$this->exam->id}")->assertStatus(200);
        $this->getJson("/api/teacher/exam-reports/{$this->exam->id}/questions")->assertStatus(200);
    }

    public function test_teacher_cannot_report_exam_outside_subject_scope(): void
    {
        Sanctum::actingAs($this->teacherA);
        $this->getJson("/api/teacher/exam-reports/{$this->examOther->id}")->assertStatus(404);
        $this->getJson("/api/teacher/exam-reports/{$this->examOther->id}/questions")->assertStatus(404);
    }

    public function test_admin_can_report_any_exam(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson("/api/exam-reports/{$this->exam->id}")->assertStatus(200);
        $this->getJson("/api/exam-reports/{$this->examOther->id}")->assertStatus(200);
    }

    public function test_student_cannot_access_reporting(): void
    {
        Sanctum::actingAs($this->s1User);
        $this->getJson("/api/exam-reports/{$this->exam->id}")->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Question reporting
    // -----------------------------------------------------------------

    public function test_question_statistics_use_frozen_snapshots_not_live_bank(): void
    {
        Sanctum::actingAs($this->admin);

        // Mutate the live question bank — historical reporting must not change.
        QuestionBank::where('id', $this->liveQ->id)->update(['question_text' => 'MUTATED', 'points' => 999]);
        QuestionOption::where('id', $this->liveOptA->id)->update(['option_text' => 'MUTATED A', 'is_correct' => 0]);
        QuestionOption::where('id', $this->liveOptB->id)->update(['option_text' => 'MUTATED B', 'is_correct' => 1]);

        $res = $this->getJson("/api/exam-reports/{$this->exam->id}/questions")->assertStatus(200)->json('data.questions');

        $mc = collect($res)->firstWhere('type', 'multiple_choice');
        $this->assertSame('MC?', $mc['question_text'], 'frozen snapshot text');
        $this->assertSame(10, $mc['points'], 'frozen snapshot points');
        $this->assertSame(6, $mc['attempts_total']);
        $this->assertSame(3, $mc['correct'], 'correctness from snapshot is_correct');
        $this->assertSame(1, $mc['incorrect']);
        $this->assertSame(4, $mc['answered']);
        $this->assertSame(2, $mc['unanswered']);
        $this->assertEquals(50.0, $mc['correctness_percentage'], 'correct %', 0.001);

        $optionTexts = array_column($mc['option_distribution'], 'option_text');
        $this->assertContains('Frozen A', $optionTexts);
        $this->assertContains('Frozen B', $optionTexts);
    }

    public function test_answers_across_attempts_are_not_merged_incorrectly(): void
    {
        Sanctum::actingAs($this->admin);
        $mc = collect($this->getJson("/api/exam-reports/{$this->exam->id}/questions")->assertStatus(200)->json('data.questions'))
            ->firstWhere('type', 'multiple_choice');

        // All attempts: S1 a1 correct, S1 a2 wrong, S2 a1 correct, S2 a2 unanswered, S3 a1 correct, S3 a2 unanswered.
        $this->assertSame(6, $mc['attempts_total']);
        $this->assertSame(3, $mc['correct']);
        $this->assertSame(1, $mc['incorrect']);
        $this->assertSame(2, $mc['unanswered']);
        $this->assertSame(3, $mc['option_distribution'][0]['selected_count'], 'correct-option selections across attempts');
        $this->assertSame(1, $mc['option_distribution'][1]['selected_count']);
    }

    public function test_objective_option_distribution_does_not_leak_answer_key(): void
    {
        Sanctum::actingAs($this->admin);
        $raw = $this->get('/api/exam-reports/'.$this->exam->id.'/questions');
        $raw->assertStatus(200);

        $encoded = $raw->getContent();
        $this->assertStringNotContainsString('"is_correct"', $encoded, 'correct-option flags must never leak through the report');
        $this->assertStringNotContainsString('explanation', $encoded);
    }

    public function test_essay_grading_status_counts(): void
    {
        Sanctum::actingAs($this->admin);

        // Manually grade S3's essay to fixed score 12.
        $s3aq = $this->aqForEssay(ExamAttempt::find($this->attempts['c1']));
        ExamAnswer::where('attempt_question_id', $s3aq->id)
            ->update(['grade_status' => 'manually_graded', 'score' => 12, 'graded_at' => now()]);

        $essay = collect($this->getJson("/api/exam-reports/{$this->exam->id}/questions")->assertStatus(200)->json('data.questions'))
            ->firstWhere('type', 'essay');

        $this->assertSame(6, $essay['attempts_total']);
        $this->assertSame(5, $essay['answered']);
        $this->assertSame(1, $essay['unanswered']);
        $this->assertSame(4, $essay['essay']['pending_manual']);
        $this->assertSame(1, $essay['essay']['manually_graded']);
        $this->assertEquals(12.0, $essay['essay']['average_score'], 'avg essay score', 0.001);
    }
}