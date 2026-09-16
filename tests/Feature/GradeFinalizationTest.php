<?php

namespace Tests\Feature;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassStudent;
use App\Models\Academic\ClassSubject;
use App\Models\Academic\Grade;
use App\Models\Academic\GradeAssessment;
use App\Models\Academic\ReportCard;
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
use App\Models\System\Permission;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2J — Grade finalization foundation.
 *
 * Hermetic suite; builds its own schema on the default (sqlite :memory:)
 * connection in setUp — never touches live MySQL.
 */
class GradeFinalizationTest extends TestCase
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
        Schema::create('class_students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('student_id');
            $t->string('status')->default('active');
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
            $t->boolean('is_final')->default(false);
            $t->dateTime('finalized_at')->nullable();
            $t->dateTime('graded_at')->nullable();
            $t->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@2j.test', 'username' => 'admin', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $teacherUser = User::create(['name' => 'Guru MTK', 'email' => 'ta@2j.test', 'username' => 'ta', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $teacherPermUser = User::create(['name' => 'Guru Finalize', 'email' => 'tf@2j.test', 'username' => 'tf', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $userA = User::create(['name' => 'Siswa A', 'email' => 'a@2j.test', 'username' => 'a', 'password' => 'x', 'role_id' => $roleSiswa->id]);

        $this->teacher = $teacherUser;
        $this->teacherPerm = $teacherPermUser;
        $this->student = Student::create(['user_id' => $userA->id, 'name' => 'Siswa A', 'nis' => 'A01']);

        $this->ay = AcademicYear::create(['name' => '2025/2026', 'is_active' => true]);
        $this->semester = Semester::create(['academic_year_id' => $this->ay->id, 'name' => '1', 'is_active' => true]);
        $this->class1 = SchoolClass::create(['name' => '7A']);
        $mtk = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);

        ClassSubject::create(['class_id' => $this->class1->id, 'subject_id' => $mtk->id, 'academic_year_id' => $this->ay->id]);
        ClassStudent::create(['class_id' => $this->class1->id, 'student_id' => $this->student->id, 'status' => 'active']);
        $this->student->update(['class_id' => $this->class1->id]);

        $teacherRec = Teacher::create(['user_id' => $teacherUser->id, 'full_name' => 'Guru MTK']);
        Teacher::create(['user_id' => $teacherPermUser->id, 'full_name' => 'Guru Finalize']);
        TeacherAssignment::create(['teacher_id' => $teacherRec->id, 'class_id' => $this->class1->id, 'subject_id' => $mtk->id, 'academic_year_id' => $this->ay->id]);

        // finalize-grades permission granted ONLY to the second teacher (user-level).
        $perm = Permission::create(['name' => 'finalize-grades']);
        \Illuminate\Support\Facades\DB::table('permission_user')->insert([
            'permission_id' => $perm->id,
            'user_id' => $teacherPermUser->id,
        ]);

        $this->grade = Grade::create([
            'student_id' => $this->student->id,
            'subject_id' => $mtk->id,
            'class_id' => $this->class1->id,
            'type' => 'uts',
            'score' => 82.5,
            'semester' => '1',
            'academic_year' => '2025/2026',
            'semester_id' => $this->semester->id,
            'academic_year_id' => $this->ay->id,
        ]);
        $this->legacyAssessment = GradeAssessment::create([
            'student_id' => $this->student->id,
            'subject_id' => $mtk->id,
            'class_id' => $this->class1->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $this->semester->id,
            'assessment_category' => 'uts',
            'assessment_sequence' => 1,
            'score' => 82.5,
            'max_score' => 100.00,
            'source_type' => 'exam_result',
            'source_id' => 1,
        ]);
        $this->mtk = $mtk;

        // Exam chain (for sync / regrade propagation tests).
        $this->qMC = QuestionBank::create(['subject_id' => $mtk->id, 'question_text' => 'MC?', 'type' => 'multiple_choice', 'difficulty' => 'medium', 'points' => 10, 'status' => 'approved']);
        QuestionOption::create(['question_id' => $this->qMC->id, 'option_text' => 'A', 'is_correct' => true]);
        QuestionOption::create(['question_id' => $this->qMC->id, 'option_text' => 'B', 'is_correct' => false]);
        $this->qEssay = QuestionBank::create(['subject_id' => $mtk->id, 'question_text' => 'Essay?', 'type' => 'essay', 'difficulty' => 'medium', 'points' => 20, 'status' => 'approved']);

        $this->examMC = $this->makeExam($mtk, 'MC UTS', 'uts', $this->qMC->id, 10);
        $this->examEssay = $this->makeExam($mtk, 'Essay UTS', 'uts', $this->qEssay->id, 20);

        $this->participantMC = ExamParticipant::create(['exam_id' => $this->examMC->id, 'student_id' => $this->student->id, 'exam_card_number' => 'CARD-MC', 'status' => 'registered', 'login_allowed' => true]);
        $this->participantEssay = ExamParticipant::create(['exam_id' => $this->examEssay->id, 'student_id' => $this->student->id, 'exam_card_number' => 'CARD-ES', 'status' => 'registered', 'login_allowed' => true]);
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

    private function adminFinalize(int $gradeId, array $body = [])
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/grades/{$gradeId}/finalize", $body);
    }

    private function adminUnfinalize(int $gradeId)
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/grades/{$gradeId}/unfinalize");
    }

    private function startAsA(int $examId): int
    {
        Sanctum::actingAs($this->student->user);
        $res = $this->postJson('/api/student/exam-attempts/start', ['exam_id' => $examId]);
        $res->assertStatus(200);

        return (int) $res->json('data.id');
    }

    private function correctOption(int $attemptId): int
    {
        $aq = ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->with('options')->first();

        return $aq->options->firstWhere('is_correct', true)->id;
    }

    // -----------------------------------------------------------------
    // A/B/C — Finalization, idempotency, unfinalization
    // -----------------------------------------------------------------

    public function test_finalize_sets_lock_and_stamps(): void
    {
        $res = $this->adminFinalize($this->grade->id);
        $res->assertStatus(200);
        $this->assertTrue($res->json('data.is_final'));
        $this->assertNotNull($res->json('data.finalized_at'));
        $this->assertSame($this->admin->id, $res->json('data.finalized_by'));

        $row = Grade::find($this->grade->id);
        $this->assertTrue($row->is_final);
        $this->assertNotNull($row->finalized_at);
        $this->assertSame($this->admin->id, $row->finalized_by);
    }

    public function test_finalize_already_finalized_is_idempotent(): void
    {
        $this->adminFinalize($this->grade->id)->assertStatus(200);
        $stamp = Grade::find($this->grade->id)->finalized_at;

        $this->adminFinalize($this->grade->id)->assertStatus(200);
        $row = Grade::find($this->grade->id);
        $this->assertTrue($row->is_final);
        $this->assertSame($stamp?->toISOString(), $row->finalized_at?->toISOString(), 'second finalize must not rewrite the original stamp');
    }

    public function test_unfinalize_reverses_lock(): void
    {
        $this->adminFinalize($this->grade->id)->assertStatus(200);
        $res = $this->adminUnfinalize($this->grade->id);
        $res->assertStatus(200);
        $this->assertFalse($res->json('data.is_final'));
        $this->assertNull($res->json('data.finalized_at'));
        $this->assertNull($res->json('data.finalized_by'));
    }

    // -----------------------------------------------------------------
    // D/E — GradeController guards
    // -----------------------------------------------------------------

    public function test_finalized_grade_cannot_update_or_delete(): void
    {
        $this->adminFinalize($this->grade->id)->assertStatus(200);

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/grades/'.$this->grade->id, ['score' => 10])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Grade is finalized and cannot be modified.');
        $this->deleteJson('/api/grades/'.$this->grade->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Grade is finalized and cannot be modified.');
        $this->assertNotNull(Grade::find($this->grade->id));
    }

    public function test_unfinalized_grade_can_update(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/grades/'.$this->grade->id, ['score' => 90])->assertStatus(200);
        $this->assertSame(90.0, (float) Grade::find($this->grade->id)->score);
    }

    public function test_client_cannot_inject_finalization_fields(): void
    {
        // Finalize request carries forged fields -> all ignored; actor is server-derived.
        $res = $this->adminFinalize($this->grade->id, [
            'is_final' => false,
            'finalized_by' => 999,
            'finalized_at' => '2000-01-01 00:00:00',
            'student_id' => 999,
            'score' => 100,
        ]);
        $res->assertStatus(200)->assertJsonPath('data.is_final', true);

        $row = Grade::find($this->grade->id);
        $this->assertSame($this->admin->id, $row->finalized_by);
        $this->assertSame(82.5, (float) $row->score, 'score is untouched by finalization');

        // Admin update cannot smuggle is_final through the graded request.
        Sanctum::actingAs($this->admin);
        $this->adminUnfinalize($this->grade->id)->assertStatus(200);
        $this->putJson('/api/grades/'.$this->grade->id, ['is_final' => true, 'score' => 77])->assertStatus(200);
        $row = Grade::find($this->grade->id);
        $this->assertFalse($row->is_final, 'is_final is not an accepted update field');
        $this->assertSame(77.0, (float) $row->score);
    }

    // -----------------------------------------------------------------
    // F — TeacherGradeController bulk guard
    // -----------------------------------------------------------------

    private function bulkPayload(array $items): array
    {
        return [
            'class_id' => $this->class1->id,
            'subject_id' => $this->mtk->id,
            'type' => 'uts',
            'semester' => '1',
            'semester_id' => $this->semester->id,
            'academic_year_id' => $this->ay->id,
            'items' => $items,
        ];
    }

    public function test_finalized_grade_cannot_be_overwritten_by_bulk_upsert(): void
    {
        $this->adminFinalize($this->grade->id)->assertStatus(200);

        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/grades/bulk', $this->bulkPayload([
            ['student_id' => $this->student->id, 'score' => 40],
        ]))->assertStatus(422);

        $this->assertSame(82.5, (float) Grade::find($this->grade->id)->score, 'finalized score is preserved');
    }

    // -----------------------------------------------------------------
    // G/H — ExamGradeIntegrationService
    // -----------------------------------------------------------------

    public function test_sync_rejected_for_finalized_grade(): void
    {
        // Build a graded result in a fresh exam slot (different grade row) so
        // sync would target a NEW row — lock that row first.
        $attemptId = $this->startAsA($this->examEssay->id);
        $aq = ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->where('question_type', 'essay')->first();
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$aq->id}", ['essay_answer' => 'x'])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $resultId = ExamResult::where('participant_id', $this->participantEssay->id)->value('id');

        // The exam is `uts`; its sync targets the (uts) slot — lock that slot.
        $utsLocked = Grade::where('student_id', $this->student->id)->where('type', 'uts')->first();
        $this->adminFinalize($utsLocked->id)->assertStatus(200);

        $res = $this->adminSync($resultId);
        $res->assertStatus(422);

        $row = Grade::find($utsLocked->id);
        $this->assertTrue($row->is_final);
        $this->assertSame(82.5, (float) $row->score, 'locked slot is never overwritten');
        $this->assertSame('uts', $row->type);
    }

    private function adminSync(int $resultId, array $body = [])
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/exam-results/{$resultId}/grade-sync", $body);
    }

    public function test_non_final_grade_still_syncs(): void
    {
        $attemptId = $this->startAsA($this->examEssay->id);
        $aq = ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->where('question_type', 'essay')->first();
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$aq->id}", ['essay_answer' => 'x'])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $resultId = ExamResult::where('participant_id', $this->participantEssay->id)->value('id');

        // Grade the essay (20/20 = 100%) so the result is graded, then admin syncs.
        Sanctum::actingAs($this->teacher);
        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aq->id)->value('id');
        $this->putJson("/api/teacher/exam-grading/answers/{$answerId}", ['score' => 20])->assertStatus(200);

        Sanctum::actingAs($this->admin);
        $res = $this->postJson("/api/exam-results/{$resultId}/grade-sync");
        $res->assertStatus(200);
        $syncedAssessment = GradeAssessment::where('source_type', 'exam_result')->where('source_id', $resultId)->first();
        $this->assertNotNull($syncedAssessment);
        $synced = Grade::where('student_id', $syncedAssessment->student_id)
            ->where('subject_id', $syncedAssessment->subject_id)
            ->where('class_id', $syncedAssessment->class_id)
            ->where('type', $syncedAssessment->assessment_category)
            ->first();
        $this->assertNotNull($synced);
        $this->assertSame(100.0, (float) $synced->score);
    }

    // -----------------------------------------------------------------
    // I — regrade propagation
    // -----------------------------------------------------------------

    public function test_regrade_does_not_mutate_finalized_grade(): void
    {
        // Build a completed exam-grade flow: essay exam -> graded -> synced -> finalized.
        $attemptId = $this->startAsA($this->examEssay->id);
        $aq = ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->where('question_type', 'essay')->first();
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$aq->id}", ['essay_answer' => 'x'])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aq->id)->value('id');
        $resultId = ExamResult::where('participant_id', $this->participantEssay->id)->value('id');

        Sanctum::actingAs($this->teacher);
        $this->putJson("/api/teacher/exam-grading/answers/{$answerId}", ['score' => 20])->assertStatus(200);
        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/teacher/exam-grading/results/{$resultId}/grade-sync")->assertStatus(200);

        $syncedAssessment = GradeAssessment::where('source_type', 'exam_result')->where('source_id', $resultId)->first();
        $synced = Grade::where('student_id', $syncedAssessment->student_id)
            ->where('subject_id', $syncedAssessment->subject_id)
            ->where('class_id', $syncedAssessment->class_id)
            ->where('type', $syncedAssessment->assessment_category)
            ->first();
        $this->adminFinalize($synced->id)->assertStatus(200);

        // Regrade down to 5/20 -> exam result updates, academic grade stays locked.
        Sanctum::actingAs($this->teacher);
        $gradeRes = $this->putJson("/api/teacher/exam-grading/answers/{$answerId}", ['score' => 5]);
        $gradeRes->assertStatus(200);

        $synced->refresh();
        $this->assertTrue($synced->is_final);
        $this->assertSame(100.0, (float) $synced->score, 'finalized academic grade is never rewritten by a regrade');
        $this->assertSame(25.0, (float) ExamResult::find($resultId)->percentage, 'exam result itself still updates');
    }

    public function test_non_final_grade_receives_regrade_propagation(): void
    {
        $attemptId = $this->startAsA($this->examEssay->id);
        $aq = ExamAttemptQuestion::where('exam_attempt_id', $attemptId)->where('question_type', 'essay')->first();
        $this->putJson("/api/student/exam-attempts/{$attemptId}/answers/{$aq->id}", ['essay_answer' => 'x'])->assertStatus(200);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit");
        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->where('attempt_question_id', $aq->id)->value('id');
        $resultId = ExamResult::where('participant_id', $this->participantEssay->id)->value('id');

        Sanctum::actingAs($this->teacher);
        $this->putJson("/api/teacher/exam-grading/answers/{$answerId}", ['score' => 20])->assertStatus(200);
        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/teacher/exam-grading/results/{$resultId}/grade-sync")->assertStatus(200);

        Sanctum::actingAs($this->teacher);
        $this->putJson("/api/teacher/exam-grading/answers/{$answerId}", ['score' => 10])->assertStatus(200);

        $syncedAssessment = GradeAssessment::where('source_type', 'exam_result')->where('source_id', $resultId)->first();
        $synced = Grade::where('student_id', $syncedAssessment->student_id)
            ->where('subject_id', $syncedAssessment->subject_id)
            ->where('class_id', $syncedAssessment->class_id)
            ->where('type', $syncedAssessment->assessment_category)
            ->first();
        $this->assertSame(50.0, (float) $synced->score, 'unlocked grade auto-follows the regrade');
    }

    // -----------------------------------------------------------------
    // J — ReportCard guard
    // -----------------------------------------------------------------

    public function test_published_report_card_blocks_grade_update(): void
    {
        ReportCard::create([
            'student_id' => $this->student->id,
            'class_id' => $this->class1->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $this->semester->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/grades/'.$this->grade->id, ['score' => 60])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Grade cannot be modified: a published report card exists for this academic period.');
        $this->assertSame(82.5, (float) Grade::find($this->grade->id)->score);
    }

    public function test_draft_report_card_does_not_lock(): void
    {
        ReportCard::create([
            'student_id' => $this->student->id,
            'class_id' => $this->class1->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $this->semester->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/grades/'.$this->grade->id, ['score' => 88])->assertStatus(200);
        $this->assertSame(88.0, (float) Grade::find($this->grade->id)->score);
    }

    public function test_report_card_publication_does_not_auto_finalize(): void
    {
        ReportCard::create([
            'student_id' => $this->student->id,
            'class_id' => $this->class1->id,
            'academic_year_id' => $this->ay->id,
            'semester_id' => $this->semester->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $row = Grade::find($this->grade->id);
        $this->assertFalse($row->is_final, 'published report card is not Grade.is_final');
    }

    // -----------------------------------------------------------------
    // K — Authorization
    // -----------------------------------------------------------------

    public function test_unauthenticated_rejected(): void
    {
        $this->postJson('/api/grades/1/finalize')->assertStatus(401);
    }

    public function test_authorization_matrix(): void
    {
        // student -> 403
        Sanctum::actingAs($this->student->user);
        $this->postJson('/api/grades/'.$this->grade->id.'/finalize')->assertStatus(403);

        // teacher without permission -> 403
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/grades/'.$this->grade->id.'/finalize')->assertStatus(403);

        // teacher WITH finalize-grades permission -> 200
        Sanctum::actingAs($this->teacherPerm);
        $this->postJson('/api/grades/'.$this->grade->id.'/finalize')->assertStatus(200);

        // admin -> 200 (superuser bypass)
        $this->adminUnfinalize($this->grade->id)->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // L/M — Security + legacy preservation
    // -----------------------------------------------------------------

    public function test_legacy_grade_rows_preserved(): void
    {
        // The legacy Grade stays readable and intact.
        $row = Grade::with('student')->find($this->grade->id);
        $this->assertSame('uts', $row->type);
        $this->assertSame(82.5, (float) $row->score);
        $this->assertFalse($row->is_final);
        $this->assertSame('Siswa A', $row->student->name);

        // Source trace now lives on the assessment.
        $assessment = GradeAssessment::find($this->legacyAssessment->id);
        $this->assertSame('exam_result', $assessment->source_type);
        $this->assertSame(1, $assessment->source_id);
    }
}
