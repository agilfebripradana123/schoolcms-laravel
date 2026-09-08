<?php

namespace Tests\Feature;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Subject;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamParticipant;
use App\Models\Examination\ExamResult;
use App\Models\Examination\ExamSchedule;
use App\Models\Examination\ExamSession;
use App\Models\Facilities\Room;
use App\Models\Staff\Teacher;
use App\Models\Staff\TeacherAssignment;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 9 — Ujian Guru (teacher self-service data scope).
 *
 * Scope: authenticated user -> teacherProfile -> teacher.id -> TeacherAssignment.subject_id.
 * Exam hanya memiliki subject_id (tanpa class/academic_year), sehingga scope guru
 * ditentukan oleh mata pelajaran yang menjadi lingkup mengajarnya.
 */
class TeacherExamDataScopeTest extends TestCase
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
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleAdmin = Role::create(['name' => 'Admin']);

        $year = AcademicYear::create(['name' => '2025/2026', 'is_active' => true]);
        $math = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        $indo = Subject::create(['code' => 'BIN', 'name' => 'Bahasa Indonesia']);

        $guruA = User::create(['name' => 'Guru A', 'email' => 'guruA@school.test', 'username' => 'gurua', 'password' => bcrypt('x'), 'role_id' => $roleGuru->id]);
        $teacherA = Teacher::create(['user_id' => $guruA->id, 'full_name' => 'Guru A']);
        $guruB = User::create(['name' => 'Guru B', 'email' => 'guruB@school.test', 'username' => 'gurub', 'password' => bcrypt('x'), 'role_id' => $roleGuru->id]);
        $teacherB = Teacher::create(['user_id' => $guruB->id, 'full_name' => 'Guru B']);

        $classA = SchoolClass::create(['name' => 'VII A']);
        $classB = SchoolClass::create(['name' => 'VIII B']);

        // Guru A mengajar Matematika di VII A; Guru B mengajar B.Indonesia di VIII B.
        TeacherAssignment::create(['teacher_id' => $teacherA->id, 'class_id' => $classA->id, 'subject_id' => $math->id, 'academic_year_id' => $year->id]);
        TeacherAssignment::create(['teacher_id' => $teacherB->id, 'class_id' => $classB->id, 'subject_id' => $indo->id, 'academic_year_id' => $year->id]);

        $examMath = Exam::create(['subject_id' => $math->id, 'title' => 'Ujian Matematika', 'duration_minutes' => 60, 'status' => 'published']);
        $examIndo = Exam::create(['subject_id' => $indo->id, 'title' => 'Ujian Bahasa Indonesia', 'duration_minutes' => 45, 'status' => 'published']);

        $room1 = Room::create(['name' => 'Ruang 1']);
        $room2 = Room::create(['name' => 'Ruang 2']);
        $session1 = ExamSession::create(['name' => 'Sesi 1', 'start_time' => '08:00:00', 'end_time' => '09:00:00']);
        $session2 = ExamSession::create(['name' => 'Sesi 2', 'start_time' => '10:00:00', 'end_time' => '11:00:00']);

        $scheduleMath = ExamSchedule::create(['exam_id' => $examMath->id, 'room_id' => $room1->id, 'session_id' => $session1->id, 'exam_date' => '2026-06-01']);
        $scheduleIndo = ExamSchedule::create(['exam_id' => $examIndo->id, 'room_id' => $room2->id, 'session_id' => $session2->id, 'exam_date' => '2026-06-05']);

        $studentA = Student::create(['name' => 'Siswa A', 'class_id' => $classA->id, 'nis' => '1001']);
        $studentB = Student::create(['name' => 'Siswa B', 'class_id' => $classB->id, 'nis' => '1002']);

        $participantMath = ExamParticipant::create(['exam_id' => $examMath->id, 'student_id' => $studentA->id, 'exam_card_number' => 'CARD-001']);
        $participantIndo = ExamParticipant::create(['exam_id' => $examIndo->id, 'student_id' => $studentB->id, 'exam_card_number' => 'CARD-002']);

        $resultMath = ExamResult::create(['participant_id' => $participantMath->id, 'total_score' => 85, 'correct_count' => 17, 'wrong_count' => 2, 'unanswered_count' => 1, 'status' => 'graded', 'graded_at' => now()]);
        $resultIndo = ExamResult::create(['participant_id' => $participantIndo->id, 'total_score' => 60, 'correct_count' => 12, 'wrong_count' => 6, 'unanswered_count' => 2, 'status' => 'graded', 'graded_at' => now()]);

        $this->guruA = $guruA;
        $this->guruB = $guruB;
        $this->mathId = $math->id;
        $this->indoId = $indo->id;
        $this->examMathId = $examMath->id;
        $this->examIndoId = $examIndo->id;
        $this->scheduleMathId = $scheduleMath->id;
        $this->scheduleIndoId = $scheduleIndo->id;
        $this->resultMathId = $resultMath->id;
        $this->resultIndoId = $resultIndo->id;
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@school.test', 'username' => 'admin', 'password' => bcrypt('x'), 'role_id' => $roleAdmin->id]);
    }

    public function test_case_a_user_without_permission_is_forbidden(): void
    {
        $this->seedDummyUser();
        $this->actingAs($this->dummy, 'sanctum');
        $this->getJson('/api/teacher/exams')->assertStatus(403);
    }

    public function test_case_b_user_without_teacher_profile_is_forbidden(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->getJson('/api/teacher/exams')->assertStatus(403);
    }

    public function test_case_c_guru_a_lists_only_exams_in_teaching_subject_scope(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        $response = $this->getJson('/api/teacher/exams');
        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Ujian Matematika'], $titles);
        $this->assertNotContains('Ujian Bahasa Indonesia', $titles);
    }

    public function test_case_d_guru_a_get_exam_of_other_subject_is_not_found(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        $this->getJson("/api/teacher/exams/{$this->examIndoId}")->assertStatus(404);
    }

    public function test_case_e_guru_a_lists_only_schedules_in_teaching_subject_scope(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        $response = $this->getJson('/api/teacher/exam-schedules');
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->scheduleMathId], $ids);
        $this->assertNotContains($this->scheduleIndoId, $ids);
    }

    public function test_case_f_guru_a_get_schedule_of_other_subject_is_not_found(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        $this->getJson("/api/teacher/exam-schedules/{$this->scheduleIndoId}")->assertStatus(404);
    }

    public function test_case_g_guru_a_lists_only_results_in_teaching_subject_scope(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        $response = $this->getJson('/api/teacher/exam-results');
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->resultMathId], $ids);
        $this->assertNotContains($this->resultIndoId, $ids);
    }

    public function test_case_h_guru_a_get_result_of_other_subject_is_not_found(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        $this->getJson("/api/teacher/exam-results/{$this->resultIndoId}")->assertStatus(404);
    }

    public function test_case_i_admin_global_exam_endpoint_not_broken(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->getJson('/api/exams')->assertOk();
        $this->getJson('/api/exam-schedules')->assertOk();
        $this->getJson('/api/exam-results')->assertOk();
    }

    public function test_case_j_guru_a_cannot_access_other_subject_exam_via_show(): void
    {
        $this->actingAs($this->guruA, 'sanctum');
        $this->getJson("/api/teacher/exams/{$this->examIndoId}")->assertStatus(404);
    }

    private function seedDummyUser(): void
    {
        $roleSiswa = Role::create(['name' => 'Siswa']);
        $this->dummy = User::create(['name' => 'Siswa X', 'email' => 'siswaX@school.test', 'username' => 'siswax', 'password' => bcrypt('x'), 'role_id' => $roleSiswa->id]);
    }
}
