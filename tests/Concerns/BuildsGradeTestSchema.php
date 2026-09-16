<?php

namespace Tests\Concerns;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassSubject;
use App\Models\Academic\Grade;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hermetic grade-test database: builds an isolated sqlite :memory: schema +
 * a minimal baseline fixture in setUp — the same pattern used by
 * GradeFinalizationTest / SecureExamAttemptTest. These tests never touch the
 * development MySQL database.
 */
trait BuildsGradeTestSchema
{
    protected function buildGradeSchema(): void
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
            $t->string('photo')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('academic_years', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
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
            $t->unique(['academic_year_id', 'name']);
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
        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->string('type')->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('class_subjects', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('teacher_id')->nullable();
            $t->timestamps();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->string('nisn')->nullable();
            $t->string('nis')->nullable();
            $t->string('name');
            $t->string('gender', 1)->nullable();
            $t->string('birth_place')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('address')->nullable();
            $t->string('ngalam')->nullable();
            $t->string('photo')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
            $t->softDeletes();
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
    }

    /**
     * Minimal shared baseline: roles + one user per role, classes, active
     * subjects, the academic years + semesters used by the grade tests, and
     * one class-subject link for the primary class/subject (subject 1 → class 1).
     */
    protected function seedGradeBaseline(): void
    {
        $adminRole = Role::create(['name' => 'Admin']);
        $administratorRole = Role::create(['name' => 'Administrator']);
        $guruRole = Role::create(['name' => 'Guru']);
        $siswaRole = Role::create(['name' => 'Siswa']);

        User::create(['name' => 'Admin Base', 'email' => 'admin.base@test.local', 'username' => 'adminbase', 'password' => 'password', 'is_active' => true, 'role_id' => $adminRole->id]);
        User::create(['name' => 'Administrator Base', 'email' => 'administrator.base@test.local', 'username' => 'adminstrator', 'password' => 'password', 'is_active' => true, 'role_id' => $administratorRole->id]);
        User::create(['name' => 'Guru Base', 'email' => 'guru.base@test.local', 'username' => 'gurubase', 'password' => 'password', 'is_active' => true, 'role_id' => $guruRole->id]);
        User::create(['name' => 'Siswa Base', 'email' => 'siswa.base@test.local', 'username' => 'siswabase', 'password' => 'password', 'is_active' => true, 'role_id' => $siswaRole->id]);

        $class1 = SchoolClass::create(['name' => '10A', 'level' => '10', 'academic_year' => '2026/2027']);
        SchoolClass::create(['name' => '10B', 'level' => '10', 'academic_year' => '2026/2027']);
        SchoolClass::create(['name' => '11A', 'level' => '11', 'academic_year' => '2026/2027']);
        SchoolClass::create(['name' => '11B', 'level' => '11', 'academic_year' => '2026/2027']);

        $subject1 = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        Subject::create(['code' => 'PAI', 'name' => 'Pendidikan Agama']);
        Subject::create(['code' => 'PKN', 'name' => 'Pendidikan Pancasila']);
        Subject::create(['code' => 'BIN', 'name' => 'Bahasa Indonesia']);

        ClassSubject::create(['class_id' => $class1->id, 'subject_id' => $subject1->id]);

        foreach ([
            ['2024/2025', ['1', '2']],
            ['2025/2026', ['1', '2']],
            ['2026/2027', ['1', '2']],
        ] as [$yearName, $semesterNames]) {
            $year = AcademicYear::create(['name' => $yearName, 'is_active' => $yearName === '2026/2027']);
            foreach ($semesterNames as $semesterName) {
                Semester::create(['academic_year_id' => $year->id, 'name' => $semesterName]);
            }
        }
    }
}