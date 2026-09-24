<?php

namespace Tests\Feature;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassStudent;
use App\Models\Academic\Period;
use App\Models\Academic\Schedule;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Staff\Teacher;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B-A07 — Student portal schedule data scope.
 *
 * /api/student/schedules must be scoped by the authenticated student's
 * authoritative active enrollment (class_students), matched on class_id AND
 * academic_year_id. The legacy nullable students.class_id must NOT control
 * access, and semester must not be invented as a filter (enrollment is
 * year-scoped only).
 *
 * Hermetic sqlite :memory: schema includes `audit_logs` because the global
 * AppServiceProvider audit hook inserts on every model create.
 */
class StudentScheduleDataScopeTest extends TestCase
{
    private int $class23;
    private int $class24;
    private int $year2;
    private int $year3;
    private int $sem2A;
    private int $sem2B;
    private int $subjectId;

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

        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->string('nisn');
            $t->string('nis');
            $t->string('name');
            $t->string('gender')->nullable();
            $t->timestamps();
            $t->softDeletes();
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

        Schema::create('periods', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('start_time')->nullable();
            $t->string('end_time')->nullable();
            $t->timestamps();
        });

        Schema::create('schedules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('teacher_id')->nullable();
            $t->string('day');
            $t->unsignedBigInteger('period_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->unsignedBigInteger('semester_id')->nullable();
            $t->timestamps();
        });

        Schema::create('class_students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->string('status');
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('user_id')->nullable();
            $t->string('action', 50);
            $t->string('model', 100)->nullable();
            $t->unsignedInteger('model_id')->nullable();
            $t->text('description');
            $t->string('ip_address', 45);
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    private function seedFixture(): void
    {
        $siswa = Role::create(['name' => 'Siswa']);

        $year2 = AcademicYear::create(['name' => '2025/2026']);
        $year3 = AcademicYear::create(['name' => '2026/2027']);
        $sem2a = Semester::create(['academic_year_id' => $year2->id, 'name' => 'Ganjil']);
        $sem2b = Semester::create(['academic_year_id' => $year2->id, 'name' => 'Genap']);

        $subject = Subject::create(['code' => 'MAT', 'name' => 'Matematika']);
        $class23 = SchoolClass::create(['name' => 'XI-1', 'level' => 'XI']);
        $class24 = SchoolClass::create(['name' => 'XI-2', 'level' => 'XI']);
        Period::create(['name' => '1', 'start_time' => '07:00', 'end_time' => '07:45']);
        Period::create(['name' => '2', 'start_time' => '08:00', 'end_time' => '08:45']);
        Teacher::create(['full_name' => 'Guru A', 'user_id' => null]);

        $this->class23 = $class23->id;
        $this->class24 = $class24->id;
        $this->year2 = $year2->id;
        $this->year3 = $year3->id;
        $this->sem2A = $sem2a->id;
        $this->sem2B = $sem2b->id;
        $this->subjectId = $subject->id;
    }

    private function loginAsStudent(array $studentOverrides = []): int
    {
        $user = User::create([
            'username' => 'siswa_'.mt_rand(100000, 999999),
            'name' => 'Siswa B-A07',
            'email' => 'b07.'.mt_rand(100000, 999999).'@test.local',
            'password' => bcrypt('x'),
            'is_active' => true,
            'role_id' => Role::where('name', 'Siswa')->firstOrFail()->id,
        ]);

        $student = Student::create(array_merge([
            'user_id' => $user->id,
            'class_id' => null,
            'nisn' => 'BN-'.str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT),
            'nis' => 'BN-'.str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT),
            'name' => 'Siswa B-A07',
            'gender' => 'L',
        ], $studentOverrides));

        Sanctum::actingAs($user);

        return $student->id;
    }

    private function enroll(int $studentId, int $classId, int $yearId, string $status = 'active'): void
    {
        ClassStudent::create([
            'class_id' => $classId,
            'student_id' => $studentId,
            'academic_year_id' => $yearId,
            'status' => $status,
        ]);
    }

    private function makeSchedule(int $classId, int $yearId, ?int $semesterId, string $day, int $periodId): int
    {
        return Schedule::create([
            'class_id' => $classId,
            'subject_id' => $this->subjectId,
            'teacher_id' => Teacher::first()->id,
            'day' => $day,
            'period_id' => $periodId,
            'academic_year_id' => $yearId,
            'semester_id' => $semesterId,
        ])->id;
    }

    private function scheduleIds(array $json): array
    {
        return array_map(fn ($row) => $row['id'], $json);
    }

    public function test_active_enrollment_sees_own_class_schedule(): void
    {
        $studentId = $this->loginAsStudent();
        $this->enroll($studentId, $this->class23, $this->year2);
        $s1 = $this->makeSchedule($this->class23, $this->year2, $this->sem2A, 'senin', 1);

        $response = $this->getJson('/api/student/schedules');

        $response->assertOk();
        $this->assertSame([$s1], $this->scheduleIds($response->json('data')));
    }

    public function test_student_cannot_see_another_class(): void
    {
        $studentId = $this->loginAsStudent();
        $this->enroll($studentId, $this->class23, $this->year2);
        $s23 = $this->makeSchedule($this->class23, $this->year2, $this->sem2A, 'senin', 1);
        $this->makeSchedule($this->class24, $this->year2, $this->sem2A, 'selasa', 2);

        $response = $this->getJson('/api/student/schedules');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($s23, $data[0]['id']);
        $this->assertSame('XI-1', $data[0]['room_name']);
    }

    public function test_year_isolation(): void
    {
        $studentId = $this->loginAsStudent();
        $this->enroll($studentId, $this->class23, $this->year2);
        $s2 = $this->makeSchedule($this->class23, $this->year2, $this->sem2A, 'senin', 1);
        $this->makeSchedule($this->class23, $this->year3, null, 'selasa', 2);

        $response = $this->getJson('/api/student/schedules');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($s2, $data[0]['id']);
    }

    public function test_both_semesters_of_enrollment_year_visible(): void
    {
        $studentId = $this->loginAsStudent();
        $this->enroll($studentId, $this->class23, $this->year2);
        $sA = $this->makeSchedule($this->class23, $this->year2, $this->sem2A, 'senin', 1);
        $sB = $this->makeSchedule($this->class23, $this->year2, $this->sem2B, 'selasa', 2);

        $response = $this->getJson('/api/student/schedules');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame([$sA, $sB], $this->scheduleIds($data));
    }

    public function test_inactive_enrollment_returns_empty(): void
    {
        $movedId = $this->loginAsStudent();
        $this->enroll($movedId, $this->class23, $this->year2, 'moved');
        $graduatedId = $this->loginAsStudent();
        $this->enroll($graduatedId, $this->class23, $this->year2, 'graduated');
        $this->makeSchedule($this->class23, $this->year2, $this->sem2A, 'senin', 1);

        // Graduated student → empty.
        $this->getJson('/api/student/schedules')->assertOk()->assertJsonPath('data', []);

        // Re-auth as the moved student → empty.
        $movedUser = User::find(Student::find($movedId)->user_id);
        Sanctum::actingAs($movedUser);
        $this->getJson('/api/student/schedules')->assertOk()->assertJsonPath('data', []);
    }

    public function test_no_enrollment_returns_empty(): void
    {
        $this->loginAsStudent();
        $this->makeSchedule($this->class23, $this->year2, $this->sem2A, 'senin', 1);

        $response = $this->getJson('/api/student/schedules');

        $response->assertOk()->assertJsonPath('data', []);
    }

    public function test_day_filter_stays_within_scope(): void
    {
        $studentId = $this->loginAsStudent();
        $this->enroll($studentId, $this->class23, $this->year2);
        $sSenin = $this->makeSchedule($this->class23, $this->year2, $this->sem2A, 'senin', 1);
        $this->makeSchedule($this->class23, $this->year2, $this->sem2A, 'selasa', 2);
        $this->makeSchedule($this->class23, $this->year2, $this->sem2A, 'rabu', 1);

        $response = $this->getJson('/api/student/schedules?day=senin');

        $response->assertOk();
        $this->assertSame([$sSenin], $this->scheduleIds($response->json('data')));
    }

    public function test_legacy_class_id_null_does_not_block_schedules(): void
    {
        $studentId = $this->loginAsStudent(['class_id' => null]);
        $this->enroll($studentId, $this->class23, $this->year2);
        $s1 = $this->makeSchedule($this->class23, $this->year2, $this->sem2A, 'senin', 1);

        $response = $this->getJson('/api/student/schedules');

        $response->assertOk();
        $this->assertSame([$s1], $this->scheduleIds($response->json('data')));
    }

    public function test_legacy_class_id_mismatch_is_ignored(): void
    {
        $studentId = $this->loginAsStudent(['class_id' => $this->class24]);
        $this->enroll($studentId, $this->class23, $this->year2);
        $this->makeSchedule($this->class23, $this->year2, $this->sem2A, 'senin', 1);
        $this->makeSchedule($this->class24, $this->year2, $this->sem2A, 'selasa', 2);

        $response = $this->getJson('/api/student/schedules');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('XI-1', $data[0]['room_name']);
    }
}