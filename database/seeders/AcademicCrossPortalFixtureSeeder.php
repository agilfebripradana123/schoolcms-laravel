<?php

namespace Database\Seeders;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Assignment;
use App\Models\Academic\ClassStudent;
use App\Models\Academic\ClassSubject;
use App\Models\Academic\Grade;
use App\Models\Academic\Period;
use App\Models\Academic\Schedule;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Staff\Teacher;
use App\Models\Staff\TeacherAssignment;
use App\Models\Students\Attendance;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use App\Services\Students\StudentAccountService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * QA academic cross-portal fixture.
 *
 * Creates ONE isolated, internally-consistent academic context (class,
 * subject, teacher + Guru login, student + Siswa login, enrollment,
 * class_subjects, teacher_assignment, schedule, assignment, grade,
 * attendance) pinned to a single existing academic year and semester so the
 * same dataset can be verified from Admin, Teacher and Student portals.
 *
 * Idempotent: identifies every QA record by its unique natural key; never
 * updates or deletes unrelated rows; creates portal accounts only when the
 * respective profile has no linked user. Fails fast if the required academic
 * context does not resolve to the expected records.
 */
class AcademicCrossPortalFixtureSeeder extends Seeder
{
    private const YEAR_NAME = '2025/2026';
    private const YEAR_ID = 2;
    private const SEMESTER_ID = 103;
    private const SEMESTER_NAME = '1';
    private const PERIOD_ID = 101;

    private const CLASS_NAME = 'QA Cross Portal X';
    private const CLASS_LEVEL = 'XII';
    private const SUBJECT_CODE = 'QA-CROSS-PORTAL';
    private const SUBJECT_NAME = 'QA Cross Portal Subject';
    private const SUBJECT_TYPE = 'wajib';
    private const TEACHER_NIP = 'QA-CROSS-PORTAL-T';
    private const TEACHER_NAME = 'QA Cross Portal Teacher';
    private const STUDENT_NISN = 'QA-CROSS-PORTAL-ST';
    private const STUDENT_NIS = 'QA-CROSS-PORTAL-ST';
    private const STUDENT_NAME = 'QA Cross Portal Student';
    private const ASSIGNMENT_TITLE = 'QA Cross Portal Assignment';
    private const SCHEDULE_DAY = 'senin';
    private const GRADE_TYPE = 'tugas';
    private const GRADE_SCORE = 90;
    private const ATTENDANCE_DATE = '2026-02-10';
    private const ATTENDANCE_STATUS = 'hadir';

    private const GURU_ACCOUNT_EMAIL = 'qa.teacher.crossportal@schoolcms.test';
    private const SISWA_ACCOUNT_EMAIL = 'qa.student.crossportal@schoolcms.test';

    public function run(): void
    {
        DB::transaction(function () {
            $this->resolveContext();

            $teacher = $this->teacher();
            $subject = $this->subject();
            $class = $this->schoolClass($teacher);
            $student = $this->student($class);

            (new StudentAccountService())->applyStatus(
                $student,
                true,
                self::SISWA_ACCOUNT_EMAIL,
                $this->fixturePassword(),
                null,
                '0.0.0.0',
                self::class,
            );

            $this->enrollment($class, $student);
            $this->classSubject($class, $subject, $teacher);
            $this->teacherAssignment($teacher, $class, $subject);
            $this->schedule($class, $subject, $teacher);
            $this->assignment($class, $subject, $teacher);
            $this->grade($class, $subject, $student);
            $this->attendance($class, $student);
        });
    }

    /**
     * Resolve and pin the academic context. Fails loudly instead of silently
     * falling back to any other year/semester/period.
     */
    private function resolveContext(): void
    {
        $year = AcademicYear::query()
            ->where('id', self::YEAR_ID)
            ->whereNull('deleted_at')
            ->first();

        if (! $year) {
            throw new RuntimeException('Academic year id=2 was not found. Required fixture context missing.');
        }

        if ($year->name !== self::YEAR_NAME) {
            throw new RuntimeException(
                "Academic year id=2 resolved to '{$year->name}' instead of '".self::YEAR_NAME."'. Refusing to continue."
            );
        }

        $semester = Semester::query()
            ->where('id', self::SEMESTER_ID)
            ->where('academic_year_id', $year->id)
            ->first();

        if (! $semester) {
            throw new RuntimeException('Semester id=103 not found for academic_year_id=2. Required fixture context missing.');
        }

        $period = Period::query()->where('id', self::PERIOD_ID)->first();

        if (! $period) {
            throw new RuntimeException('Period id=101 not found. Required fixture context missing.');
        }
    }

    private function fixturePassword(): string
    {
        return implode('.', ['qa', 'cross', 'portal', '2026']);
    }

    private function teacher(): Teacher
    {
        $teacher = Teacher::query()->where('nip', self::TEACHER_NIP)->first()
            ?? Teacher::create([
                'teacher_code' => self::TEACHER_NIP,
                'nip' => self::TEACHER_NIP,
                'full_name' => self::TEACHER_NAME,
                'gender' => 'L',
                'is_active' => true,
            ]);

        if ($teacher->user_id === null) {
            $guruRoleId = Role::query()->where('name', 'Guru')->value('id');

            if (! $guruRoleId) {
                throw new RuntimeException('Role "Guru" not found. Cannot create the QA Guru login account.');
            }

            $user = User::create([
                'role_id' => $guruRoleId,
                'username' => self::TEACHER_NIP,
                'name' => $teacher->full_name ?: self::TEACHER_NAME,
                'email' => self::GURU_ACCOUNT_EMAIL,
                'password' => $this->fixturePassword(),
                'is_active' => true,
            ]);

            $teacher->update(['user_id' => $user->id]);
        }

        return $teacher;
    }

    private function subject(): Subject
    {
        return Subject::query()->where('code', self::SUBJECT_CODE)->first()
            ?? Subject::create([
                'code' => self::SUBJECT_CODE,
                'name' => self::SUBJECT_NAME,
                'type' => self::SUBJECT_TYPE,
                'description' => 'QA cross-portal fixture subject.',
            ]);
    }

    private function schoolClass(Teacher $teacher): SchoolClass
    {
        return SchoolClass::query()->where('name', self::CLASS_NAME)->first()
            ?? SchoolClass::create([
                'name' => self::CLASS_NAME,
                'teacher_id' => $teacher->id,
                'level' => self::CLASS_LEVEL,
                'academic_year' => self::YEAR_NAME,
            ]);
    }

    private function student(SchoolClass $class): Student
    {
        $student = Student::query()->where('nisn', self::STUDENT_NISN)->first()
            ?? Student::create([
                'nisn' => self::STUDENT_NISN,
                'nis' => self::STUDENT_NIS,
                'name' => self::STUDENT_NAME,
                'gender' => 'L',
                'birth_place' => 'QA City',
                'birth_date' => '2010-05-01',
                'address' => 'QA Address',
                'kps_recipient' => false,
                'kip_recipient' => false,
                'pip_eligible' => false,
            ]);

        // Legacy compatibility alias only; authoritative membership is class_students.
        if ((int) $student->class_id !== (int) $class->id) {
            $student->update(['class_id' => $class->id]);
        }

        return $student;
    }

    private function enrollment(SchoolClass $class, Student $student): void
    {
        ClassStudent::firstOrCreate(
            [
                'class_id' => $class->id,
                'student_id' => $student->id,
                'academic_year_id' => self::YEAR_ID,
            ],
            ['status' => 'active']
        );
    }

    private function classSubject(SchoolClass $class, Subject $subject, Teacher $teacher): void
    {
        ClassSubject::firstOrCreate(
            ['class_id' => $class->id, 'subject_id' => $subject->id],
            ['teacher_id' => $teacher->id]
        );
    }

    private function teacherAssignment(Teacher $teacher, SchoolClass $class, Subject $subject): void
    {
        TeacherAssignment::firstOrCreate(
            [
                'teacher_id' => $teacher->id,
                'class_id' => $class->id,
                'subject_id' => $subject->id,
                'academic_year_id' => self::YEAR_ID,
            ]
        );
    }

    private function schedule(SchoolClass $class, Subject $subject, Teacher $teacher): void
    {
        Schedule::firstOrCreate(
            [
                'class_id' => $class->id,
                'day' => self::SCHEDULE_DAY,
                'period_id' => self::PERIOD_ID,
                'academic_year_id' => self::YEAR_ID,
                'semester_id' => self::SEMESTER_ID,
            ],
            [
                'subject_id' => $subject->id,
                'teacher_id' => $teacher->id,
            ]
        );
    }

    private function assignment(SchoolClass $class, Subject $subject, Teacher $teacher): void
    {
        Assignment::firstOrCreate(
            [
                'title' => self::ASSIGNMENT_TITLE,
                'class_id' => $class->id,
                'subject_id' => $subject->id,
                'teacher_id' => $teacher->id,
                'academic_year_id' => self::YEAR_ID,
            ],
            [
                'description' => 'QA cross-portal fixture assignment.',
                'due_date' => '2026-03-20',
            ]
        );
    }

    private function grade(SchoolClass $class, Subject $subject, Student $student): void
    {
        Grade::firstOrCreate(
            [
                'student_id' => $student->id,
                'subject_id' => $subject->id,
                'class_id' => $class->id,
                'type' => self::GRADE_TYPE,
                'academic_year_id' => self::YEAR_ID,
                'semester_id' => self::SEMESTER_ID,
            ],
            [
                'score' => self::GRADE_SCORE,
                'semester' => self::SEMESTER_NAME,
                'academic_year' => self::YEAR_NAME,
            ]
        );
    }

    private function attendance(SchoolClass $class, Student $student): void
    {
        Attendance::firstOrCreate(
            [
                'student_id' => $student->id,
                'class_id' => $class->id,
                'date' => self::ATTENDANCE_DATE,
            ],
            [
                'status' => self::ATTENDANCE_STATUS,
                'academic_year_id' => self::YEAR_ID,
                'note' => 'QA cross-portal fixture attendance.',
            ]
        );
    }
}