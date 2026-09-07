<?php

namespace Database\Seeders;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassSubject;
use App\Models\Academic\Grade;
use App\Models\Academic\Period;
use App\Models\Academic\Schedule;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Staff\Teacher;
use App\Models\Students\Student;
use Illuminate\Database\Seeder;

/**
 * Seeds realistic ClassSubject, Grade and Schedule rows for the academic module
 * so that its tables do not appear empty.
 *
 * - ClassSubject: assigns each real subject to each real class (idempotent).
 * - Grades: created for every student that has a class assigned, for each
 *   subject assigned to that class, for each semester of the active academic
 *   year, across all three grade types (tugas, uts, uas).
 * - Schedules: created for each class/subject pair using real periods and a
 *   teacher (preferring the class subject's teacher, otherwise a random one).
 *
 * Idempotent: rows are only inserted when their unique tuple does not already
 * exist, so re-running never duplicates data.
 */
class AcademicGradeScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $academicYear = AcademicYear::query()
            ->where('is_active', true)
            ->whereHas('semesters')
            ->orderBy('id', 'desc')
            ->first() ?? AcademicYear::query()
            ->whereHas('semesters')
            ->orderBy('id', 'desc')
            ->first();

        if (!$academicYear) {
            $this->command?->warn('No academic year with semesters found; nothing to seed.');

            return;
        }

        $semesters = Semester::query()
            ->where('academic_year_id', $academicYear->id)
            ->orderBy('id')
            ->get();

        if ($semesters->isEmpty()) {
            $this->command?->warn(
                "Academic year '{$academicYear->name}' has no semesters; nothing to seed."
            );

            return;
        }

        $this->seedClassSubjects($academicYear, $semesters);
        $this->seedGrades($academicYear, $semesters);
        $this->seedSchedules($academicYear, $semesters);

        $this->command?->info(
            sprintf(
                'Done. class_subjects=%d, grades=%d, schedules=%d (academic year "%s").',
                ClassSubject::count(),
                Grade::count(),
                Schedule::count(),
                $academicYear->name
            )
        );
    }

    private function seedClassSubjects(AcademicYear $academicYear, $semesters): void
    {
        $classes = \App\Models\Academic\SchoolClass::query()
            ->whereNull('deleted_at')
            ->get();

        $subjects = Subject::query()
            ->whereNull('deleted_at')
            ->get();

        if ($classes->isEmpty() || $subjects->isEmpty()) {
            $this->command?->warn('No classes or subjects found; skipped class-subjects.');

            return;
        }

        foreach ($classes as $class) {
            foreach ($subjects as $subject) {
                ClassSubject::query()->firstOrCreate(
                    ['class_id' => $class->id, 'subject_id' => $subject->id],
                    []
                );
            }
        }
    }

    private function seedGrades(AcademicYear $academicYear, $semesters): void
    {
        $students = Student::query()
            ->whereNotNull('class_id')
            ->whereNull('deleted_at')
            ->get();

        if ($students->isEmpty()) {
            $this->command?->warn('No students with an assigned class; skipped grades.');

            return;
        }

        $scores = ['tugas' => 90, 'uts' => 85, 'uas' => 88];

        foreach ($students as $student) {
            $subjectIds = ClassSubject::query()
                ->where('class_id', $student->class_id)
                ->pluck('subject_id');

            if ($subjectIds->isEmpty()) {
                continue;
            }

            foreach ($semesters as $semester) {
                foreach ($subjectIds as $subjectId) {
                    foreach (['tugas', 'uts', 'uas'] as $type) {
                        Grade::query()->firstOrCreate(
                            [
                                'student_id' => $student->id,
                                'subject_id' => $subjectId,
                                'class_id' => $student->class_id,
                                'type' => $type,
                                'semester_id' => $semester->id,
                                'academic_year_id' => $academicYear->id,
                            ],
                            [
                                'score' => rand($scores[$type] - 10, $scores[$type]),
                                'semester' => $semester->name,
                                'academic_year' => $academicYear->name,
                            ]
                        );
                    }
                }
            }
        }
    }

    private function seedSchedules(AcademicYear $academicYear, $semesters): void
    {
        $periods = Period::query()->orderBy('id')->get();

        if ($periods->isEmpty()) {
            $this->command?->warn('No periods found; skipped schedules.');

            return;
        }

        $classSubjects = ClassSubject::query()->get();

        if ($classSubjects->isEmpty()) {
            $this->command?->warn('No class-subject assignments found; skipped schedules.');

            return;
        }

        $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $teachers = Teacher::query()->whereNull('deleted_at')->pluck('id')->all();
        $semester = $semesters->first();

        foreach ($classSubjects as $classSubject) {
            // One schedule per class+subject+semester. Existence is checked on
            // the stable identity so re-running never duplicates schedules.
            $exists = Schedule::query()
                ->where('class_id', $classSubject->class_id)
                ->where('subject_id', $classSubject->subject_id)
                ->where('academic_year_id', $academicYear->id)
                ->where('semester_id', $semester->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $teacherId = $classSubject->teacher_id;
            if (($teacherId === null || $teacherId === 0) && !empty($teachers)) {
                $teacherId = $teachers[array_rand($teachers)];
            }

            Schedule::query()->create([
                'class_id' => $classSubject->class_id,
                'subject_id' => $classSubject->subject_id,
                'day' => $days[array_rand($days)],
                'period_id' => $periods->random()->id,
                'teacher_id' => $teacherId ?: null,
                'academic_year_id' => $academicYear->id,
                'semester_id' => $semester->id,
            ]);
        }
    }
}
