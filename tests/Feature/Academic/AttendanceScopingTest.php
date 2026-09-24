<?php

namespace Tests\Feature;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassStudent;
use App\Models\Academic\SchoolClass;
use App\Models\Staff\Teacher;
use App\Models\Staff\TeacherAssignment;
use App\Models\Students\Attendance;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * B-A05 (DB-2) — attendance academic scoping & integrity.
 *
 * Business key: UNIQUE (student_id, class_id, date, academic_year_id) named
 * `uniq_attendance_slot`. Every read/write path (admin, teacher self-service,
 * student portal, reports) is bound to an academic year; the active academic
 * year is the server default when the client omits one. Semester is NOT part
 * of the identity (attendance is a per-day record).
 */
class AttendanceScopingTest extends TestCase
{
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->ids['attendance'], true) as $attendance) {
            $attendance->forceDelete();
        }
        foreach (array_reverse($this->ids['classStudent'], true) as $row) {
            $row->forceDelete();
        }
        foreach (array_reverse($this->ids['assignment'], true) as $row) {
            $row->forceDelete();
        }
        foreach (array_reverse($this->ids['student'], true) as $row) {
            $row->forceDelete();
        }
        foreach (array_reverse($this->ids['teacher'], true) as $row) {
            $row->forceDelete();
        }
        foreach (array_reverse($this->ids['user'], true) as $row) {
            $row->forceDelete();
        }
        parent::tearDown();
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
        Schema::create('teacher_assignments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('teacher_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('academic_year_id');
            $t->timestamps();
        });
        Schema::create('class_students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->string('status')->default('active');
            $t->timestamps();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->string('nisn')->unique();
            $t->string('nis')->unique();
            $t->string('name');
            $t->string('gender');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->date('date');
            $t->string('status');
            $t->string('note')->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'class_id', 'date', 'academic_year_id'], 'uniq_attendance_slot');
        });
    }

    private function seedFixture(): void
    {
        $this->ids = [
            'user' => [], 'teacher' => [], 'student' => [], 'assignment' => [],
            'classStudent' => [], 'attendance' => [],
        ];

        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);

        $year1 = AcademicYear::create(['name' => '2026/2027', 'is_active' => true]);
        $year2 = AcademicYear::create(['name' => '2025/2026', 'is_active' => false]);

        $adminUser = User::create(['name' => 'Admin', 'email' => 'a@b.test', 'username' => 'admin', 'password' => bcrypt('x'), 'role_id' => $roleAdmin->id]);
        $guruUser = User::create(['name' => 'Guru', 'email' => 'g@b.test', 'username' => 'guru', 'password' => bcrypt('x'), 'role_id' => $roleGuru->id]);
        $siswaUser = User::create(['name' => 'Siswa', 'email' => 's@b.test', 'username' => 'siswa', 'password' => bcrypt('x'), 'role_id' => $roleSiswa->id]);

        $teacher = Teacher::create(['user_id' => $guruUser->id, 'full_name' => 'Pak Guru']);
        $classA = SchoolClass::create(['name' => 'X RPL 1']);
        $classB = SchoolClass::create(['name' => 'XI TKJ 1']);

        $s1 = Student::create(['user_id' => $siswaUser->id, 'nisn' => '9001', 'nis' => '001', 'name' => 'Ahmad', 'gender' => 'L']);
        $s2 = Student::create(['nisn' => '9002', 'nis' => '002', 'name' => 'Budi', 'gender' => 'L']);
        $s3 = Student::create(['nisn' => '9003', 'nis' => '003', 'name' => 'Citra', 'gender' => 'P']);

        TeacherAssignment::create(['teacher_id' => $teacher->id, 'class_id' => $classA->id, 'academic_year_id' => $year1->id]);
        TeacherAssignment::create(['teacher_id' => $teacher->id, 'class_id' => $classB->id, 'academic_year_id' => $year2->id]);

        ClassStudent::create(['class_id' => $classA->id, 'student_id' => $s1->id, 'academic_year_id' => $year1->id]);
        ClassStudent::create(['class_id' => $classA->id, 'student_id' => $s2->id, 'academic_year_id' => $year1->id]);
        ClassStudent::create(['class_id' => $classB->id, 'student_id' => $s3->id, 'academic_year_id' => $year2->id]);
        // S1 is a member of class A in both years, so the same (student, class, date)
        // slot is legal once per year.
        ClassStudent::create(['class_id' => $classA->id, 'student_id' => $s1->id, 'academic_year_id' => $year2->id]);

        $this->ids['user'] = array_merge($this->ids['user'], [$adminUser, $guruUser, $siswaUser]);
        $this->ids['teacher'] = [...$this->ids['teacher'], $teacher];
        $this->ids['student'] = [$s1, $s2, $s3];
        $this->ids['assignment'] = TeacherAssignment::all()->all();

        $this->year1Id = $year1->id;
        $this->year2Id = $year2->id;
        $this->classAId = $classA->id;
        $this->classBId = $classB->id;
        $this->s1Id = $s1->id;
        $this->s2Id = $s2->id;
        $this->s3Id = $s3->id;
        $this->admin = $adminUser;
        $this->guru = $guruUser;
        $this->siswa = $siswaUser;
    }

    private function makeAttendance(int $studentId, int $classId, int $yearId, string $date, string $status): Attendance
    {
        $attendance = Attendance::create([
            'student_id' => $studentId,
            'class_id' => $classId,
            'academic_year_id' => $yearId,
            'date' => $date,
            'status' => $status,
        ]);
        $this->ids['attendance'][] = $attendance;
        return $attendance;
    }

    public function test_a_admin_duplicate_same_context_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $payload = [
            'student_id' => $this->s1Id,
            'class_id' => $this->classAId,
            'date' => '2026-09-03',
            'status' => 'hadir',
        ];

        $this->postJson('/api/attendance', $payload)->assertStatus(201);
        $this->postJson('/api/attendance', $payload)->assertStatus(422);

        $this->assertSame(1, Attendance::where('student_id', $this->s1Id)->where('date', '2026-09-03')->count());
    }

    public function test_a_database_unique_index_rejects_duplicate_slot(): void
    {
        $this->makeAttendance($this->s1Id, $this->classAId, $this->year1Id, '2026-09-03', 'hadir');

        $this->expectException(\Illuminate\Database\QueryException::class);
        Attendance::create([
            'student_id' => $this->s1Id,
            'class_id' => $this->classAId,
            'academic_year_id' => $this->year1Id,
            'date' => '2026-09-03',
            'status' => 'sakit',
        ]);
    }

    public function test_b_same_slot_in_different_year_is_allowed_and_does_not_overwrite(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $first = $this->postJson('/api/attendance', [
            'student_id' => $this->s1Id,
            'class_id' => $this->classAId,
            'academic_year_id' => $this->year1Id,
            'date' => '2026-09-03',
            'status' => 'hadir',
        ])->assertStatus(201);

        $second = $this->postJson('/api/attendance', [
            'student_id' => $this->s1Id,
            'class_id' => $this->classAId,
            'academic_year_id' => $this->year2Id,
            'date' => '2026-09-03',
            'status' => 'sakit',
        ])->assertStatus(201);

        $this->assertSame($this->year1Id, (int) $first->json('data.academic_year_id'));
        $this->assertSame($this->year2Id, (int) $second->json('data.academic_year_id'));

        $rows = Attendance::where('student_id', $this->s1Id)->where('class_id', $this->classAId)->where('date', '2026-09-03')->get();
        $this->assertSame(2, $rows->count());
        $this->assertSame(['hadir', 'sakit'], $rows->pluck('status')->sort()->values()->all());
    }

    public function test_c_same_year_default_active_year_is_recorded_even_when_omitted(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $response = $this->postJson('/api/attendance', [
            'student_id' => $this->s1Id,
            'class_id' => $this->classAId,
            'date' => '2026-09-05',
            'status' => 'izin',
        ])->assertStatus(201);

        $this->assertSame($this->year1Id, (int) $response->json('data.academic_year_id'));
    }

    public function test_c_non_member_student_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/attendance', [
            'student_id' => $this->s3Id,
            'class_id' => $this->classAId,
            'academic_year_id' => $this->year1Id,
            'date' => '2026-09-06',
            'status' => 'hadir',
        ])->assertStatus(422);
    }

    public function test_d_teacher_scope_rejects_outside_class_and_non_member(): void
    {
        $this->actingAs($this->guru, 'sanctum');

        $this->getJson("/api/teacher/attendance?date=2026-09-03&class_id={$this->classBId}")
            ->assertStatus(404);

        $this->getJson("/api/teacher/attendance?date=2026-09-03&class_id={$this->classAId}")
            ->assertOk();

        $this->postJson('/api/teacher/attendance', [
            'class_id' => $this->classAId,
            'date' => '2026-09-03',
            'items' => [
                ['student_id' => $this->s1Id, 'status' => 'hadir'],
            ],
        ])->assertOk();

        $this->postJson('/api/teacher/attendance', [
            'class_id' => $this->classAId,
            'date' => '2026-09-03',
            'items' => [
                ['student_id' => $this->s3Id, 'status' => 'hadir'],
            ],
        ])->assertStatus(422);
    }

    public function test_d_teacher_store_is_scoped_by_year(): void
    {
        $this->actingAs($this->guru, 'sanctum');

        $this->postJson('/api/teacher/attendance', [
            'class_id' => $this->classAId,
            'academic_year_id' => $this->year1Id,
            'date' => '2026-09-04',
            'items' => [
                ['student_id' => $this->s1Id, 'status' => 'sakit'],
            ],
        ])->assertOk();

        $this->assertSame(1, Attendance::where('student_id', $this->s1Id)
            ->where('class_id', $this->classAId)
            ->where('academic_year_id', $this->year1Id)
            ->where('date', '2026-09-04')->count());
    }

    public function test_e_student_history_is_year_isolated(): void
    {
        $this->actingAs($this->siswa, 'sanctum');

        $year1Row = $this->makeAttendance($this->s1Id, $this->classAId, $this->year1Id, '2026-09-03', 'hadir');
        $year2Row = $this->makeAttendance($this->s1Id, $this->classBId, $this->year2Id, '2025-09-03', 'izin');

        // Default = active year (2026/2027) -> only year-1 row.
        $default = $this->getJson('/api/student/attendance')->assertOk();
        $this->assertSame([$year1Row->id], collect($default->json('data'))->pluck('id')->all());

        // Explicit other year -> only that year's row.
        $explicit = $this->getJson("/api/student/attendance?academic_year_id={$this->year2Id}")->assertOk();
        $this->assertSame([$year2Row->id], collect($explicit->json('data'))->pluck('id')->all());

        $summary = $this->getJson('/api/student/attendance/summary')->assertOk();
        $this->assertSame($this->year1Id, (int) $summary->json('data.academic_year_id'));
        $this->assertSame(1, (int) $summary->json('data.total_days'));
    }

    public function test_f_reports_are_year_isolated(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->makeAttendance($this->s1Id, $this->classAId, $this->year1Id, '2026-09-03', 'hadir');
        $this->makeAttendance($this->s2Id, $this->classAId, $this->year1Id, '2026-09-03', 'hadir');
        // Same date but other year, other class, opposite status.
        $this->makeAttendance($this->s1Id, $this->classBId, $this->year2Id, '2026-09-03', 'sakit');

        $default = $this->getJson('/api/reports/attendance/daily?date=2026-09-03')->assertOk();
        $default->assertJsonPath('data.academic_year_id', $this->year1Id);
        $this->assertSame(2, (int) $default->json('data.totals.hadir'));
        $this->assertSame(0, (int) $default->json('data.totals.sakit'));

        $other = $this->getJson("/api/reports/attendance/daily?date=2026-09-03&academic_year_id={$this->year2Id}")->assertOk();
        $this->assertSame(1, (int) $other->json('data.totals.sakit'));
        $this->assertSame(0, (int) $other->json('data.totals.hadir'));

        $studentDefault = $this->getJson('/api/reports/attendance/student-summary')->assertOk();
        $rows = collect($studentDefault->json('data'))->keyBy('student_id');
        $this->assertSame(1, (int) $rows[$this->s1Id]['total_days']);
        $this->assertSame(1, (int) $rows[$this->s1Id]['hadir']);
        $this->assertArrayNotHasKey($this->s3Id, $rows->all());

        $studentOther = $this->getJson("/api/reports/attendance/student-summary?academic_year_id={$this->year2Id}")->assertOk();
        $otherRows = collect($studentOther->json('data'))->keyBy('student_id');
        $this->assertArrayHasKey($this->s1Id, $otherRows->all());
        $this->assertSame((float) 0, (float) $otherRows[$this->s1Id]['hadir']);
        $this->assertSame(1, (int) $otherRows[$this->s1Id]['sakit']);
    }

    public function test_g_alpa_status_is_recognized_in_reports(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->makeAttendance($this->s1Id, $this->classAId, $this->year1Id, '2026-09-03', 'hadir');
        $this->makeAttendance($this->s1Id, $this->classAId, $this->year1Id, '2026-09-04', 'alpa');

        $daily = $this->getJson('/api/reports/attendance/daily?date=2026-09-04')->assertOk();
        $this->assertSame(1, (int) $daily->json('data.totals.alpa'));
        $this->assertArrayNotHasKey('alfa', $daily->json('data.totals'));

        $summary = $this->getJson('/api/reports/attendance/student-summary')->assertOk();
        $rows = collect($summary->json('data'))->keyBy('student_id');
        $this->assertSame(1, (int) $rows[$this->s1Id]['alpa']);
        $this->assertArrayNotHasKey('alfa', $rows->first());

        $this->actingAs($this->siswa, 'sanctum');
        $this->getJson('/api/student/attendance?status=alpa')->assertOk()->assertJsonFragment(['status' => 'alpa']);
    }

    public function test_h_dead_route_removed_canonical_routes_stay(): void
    {
        $this->actingAs($this->guru, 'sanctum');
        $this->getJson('/api/reports/attendance')->assertNotFound();

        $this->actingAs($this->admin, 'sanctum');
        $this->getJson('/api/reports/attendance/daily?date=2026-09-03')->assertOk();
        $this->getJson('/api/reports/attendance/student-summary')->assertOk();
    }

    public function test_i_update_is_bound_to_existing_academic_context(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $row = $this->makeAttendance($this->s1Id, $this->classAId, $this->year1Id, '2026-09-07', 'hadir');

        // Omitting the year falls back to the record's own year (not the active-year default).
        $this->putJson("/api/attendance/{$row->id}", [
            'student_id' => $this->s1Id,
            'class_id' => $this->classAId,
            'date' => '2026-09-07',
            'status' => 'sakit',
        ])->assertOk();

        $this->assertSame($this->year1Id, (int) Attendance::find($row->id)->academic_year_id);
        $this->assertSame('sakit', Attendance::find($row->id)->status);

        // Repeating the same identity (ignore self) is accepted.
        $this->putJson("/api/attendance/{$row->id}", [
            'student_id' => $this->s1Id,
            'class_id' => $this->classAId,
            'academic_year_id' => $this->year1Id,
            'date' => '2026-09-07',
            'status' => 'hadir',
        ])->assertOk();

        // Moving the record onto an occupied slot is rejected.
        $this->makeAttendance($this->s2Id, $this->classAId, $this->year1Id, '2026-09-07', 'izin');
        $this->putJson("/api/attendance/{$row->id}", [
            'student_id' => $this->s2Id,
            'class_id' => $this->classAId,
            'academic_year_id' => $this->year1Id,
            'date' => '2026-09-07',
            'status' => 'hadir',
        ])->assertStatus(422);
    }

    public function test_j_migration_artifacts_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('attendances', 'academic_year_id'));
        $this->assertTrue(Schema::hasIndex('attendances', 'uniq_attendance_slot'));
    }
}