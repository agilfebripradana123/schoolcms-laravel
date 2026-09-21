<?php

namespace Database\Seeders;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassSubject;
use App\Models\Academic\Period;
use App\Models\Academic\Schedule;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Staff\Teacher;
use App\Models\Staff\TeacherAssignment;
use Illuminate\Database\Seeder;

/**
 * Seeds a self-contained dummy schedule dataset (additive, scoped, idempotent)
 * so the relation academic year -> semester -> subject -> class -> teacher ->
 * schedule can be exercised from the admin UI without touching existing data.
 *
 * - Reuses an existing academic year ("2026/2027" when present, otherwise the
 *   same fallback the AcademicGradeScheduleSeeder uses).
 * - Restores the canonical subjects that exist soft-deleted (PAI, IND, MAT,
 *   BIN, PEN, TIK) and creates only the missing dummy ones (IPA, IPS).
 * - Builds the six target classes (X-1 .. XII-2), every class-subject
 *   relation, a teacher assignment per class+subject and one schedule per
 *   class+subject across Senin-Sabtu with deterministic slots so re-running
 *   never duplicates rows nor introduces class/teacher slot conflicts.
 *
 * Idempotent: each relation is guarded by its real unique tuple or the
 * de-facto (name, level, academic_year) identity for classes.
 */
class AcademicDummySeeder extends Seeder
{
    /**
     * Target subjects presented in a fixed order; the schedule slot layout is
     * derived from each entry's index, so the order must stay stable.
     */
    private const TARGET_SUBJECTS = [
        ['code' => 'PAI', 'name' => 'Pendidikan Agama dan Budi Pekerti'],
        ['code' => 'IND', 'name' => 'Bahasa Indonesia'],
        ['code' => 'MAT', 'name' => 'Matematika'],
        ['code' => 'BIN', 'name' => 'Bahasa Inggris'],
        ['code' => 'PEN', 'name' => 'Pendidikan Jasmani, Olahraga, dan Kesehatan'],
        ['code' => 'TIK', 'name' => 'Informatika'],
        ['code' => 'IPA', 'name' => 'Ilmu Pengetahuan Alam'],
        ['code' => 'IPS', 'name' => 'Ilmu Pengetahuan Sosial'],
    ];

    private const TARGET_CLASSES = [
        ['name' => 'X-1', 'level' => '10'],
        ['name' => 'X-2', 'level' => '10'],
        ['name' => 'XI-1', 'level' => '11'],
        ['name' => 'XI-2', 'level' => '11'],
        ['name' => 'XII-1', 'level' => '12'],
        ['name' => 'XII-2', 'level' => '12'],
    ];

    private const DAYS = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu'];

    public function run(): void
    {
        $academicYear = $this->resolveAcademicYear();

        if (!$academicYear) {
            $this->command?->warn('No academic year with semesters found; nothing to seed.');

            return;
        }

        $semester = Semester::query()
            ->where('academic_year_id', $academicYear->id)
            ->orderBy('id')
            ->first();

        if (!$semester) {
            $this->command?->warn(
                "Academic year '{$academicYear->name}' has no semesters; nothing to seed."
            );

            return;
        }

        $classes = $this->seedClasses($academicYear);
        $subjects = $this->seedSubjects();

        if ($classes->isEmpty() || $subjects->isEmpty()) {
            $this->command?->warn('Classes or subjects are empty; nothing else to seed.');

            return;
        }

        $teachers = Teacher::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $periods = Period::query()->orderBy('id')->pluck('id')->all();

        if (count($periods) < 8) {
            $this->command?->warn('Fewer than 8 periods found; schedule layout requires 8.');

            return;
        }

        $teacherCount = count($teachers);

        $classSubjectsCount = 0;
        $teacherAssignmentsCount = 0;
        $schedulesCount = 0;

        // Book the slots that already exist for the target academic year so a
        // dummy schedule never collides with a pre-existing row (same class
        // slot or the same teacher teaching two classes at once).
        $usedClassSlots = $this->existingSlots($academicYear->id, 'class');
        $usedTeacherSlots = $this->existingSlots($academicYear->id, 'teacher');

        foreach ($classes as $classIndex => $class) {
            foreach ($subjects as $subjectIndex => $subject) {
                $teacherId = $teacherCount > 0
                    ? $teachers[$subjectIndex % $teacherCount]
                    : null;

                ClassSubject::query()->updateOrCreate(
                    ['class_id' => $class->id, 'subject_id' => $subject->id],
                    ['teacher_id' => $teacherId]
                );
                $classSubjectsCount++;

                if ($teacherId) {
                    TeacherAssignment::query()->firstOrCreate(
                        [
                            'teacher_id' => $teacherId,
                            'class_id' => $class->id,
                            'subject_id' => $subject->id,
                            'academic_year_id' => $academicYear->id,
                        ],
                        []
                    );
                    $teacherAssignmentsCount++;
                }

                $scheduleExists = Schedule::query()
                    ->where('class_id', $class->id)
                    ->where('subject_id', $subject->id)
                    ->where('academic_year_id', $academicYear->id)
                    ->where('semester_id', $semester->id)
                    ->exists();

                if ($scheduleExists) {
                    continue;
                }

                [$day, $periodId] = $this->pickSlot(
                    $class->id,
                    $teacherId,
                    $classIndex,
                    $subjectIndex,
                    $periods,
                    $usedClassSlots,
                    $usedTeacherSlots
                );

                Schedule::query()->create([
                    'class_id' => $class->id,
                    'subject_id' => $subject->id,
                    'teacher_id' => $teacherId,
                    'day' => $day,
                    'period_id' => $periodId,
                    'academic_year_id' => $academicYear->id,
                    'semester_id' => $semester->id,
                ]);
                $schedulesCount++;

                $usedClassSlots[$class->id][$day][$periodId] = true;
                if ($teacherId) {
                    $usedTeacherSlots[$teacherId][$day][$periodId] = true;
                }
            }
        }

        $this->command?->info(
            sprintf(
                'Academic dummy data done (year "%s", semester "%s"): classes=%d, subjects=%d, class_subjects=%d, teacher_assignments=%d, schedules=%d.',
                $academicYear->name,
                $semester->name,
                $classes->count(),
                $subjects->count(),
                $classSubjectsCount,
                $teacherAssignmentsCount,
                $schedulesCount
            )
        );
    }

    private function resolveAcademicYear(): ?AcademicYear
    {
        $byName = AcademicYear::query()->where('name', '2026/2027')->first();

        if ($byName) {
            return $byName;
        }

        return AcademicYear::query()
            ->where('is_active', true)
            ->whereHas('semesters')
            ->orderBy('id', 'desc')
            ->first() ?? AcademicYear::query()
            ->whereHas('semesters')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Slots already occupied by existing schedules of the target academic year.
     *
     * @param string $scope 'class' or 'teacher'
     */
    private function existingSlots(int $academicYearId, string $scope): array
    {
        $slots = [];

        Schedule::query()
            ->where('academic_year_id', $academicYearId)
            ->get(['class_id', 'teacher_id', 'day', 'period_id'])
            ->each(function (Schedule $schedule) use ($scope, &$slots): void {
                $owner = $scope === 'teacher' ? $schedule->teacher_id : $schedule->class_id;

                if ($owner === null) {
                    return;
                }

                $slots[$owner][$schedule->day][$schedule->period_id] = true;
            });

        return $slots;
    }

    /**
     * Picks a free (day, period) for the dummy schedule. Scanning order is
     * deterministic so re-running on a clean build produces the same layout.
     *
     * @return array{0: string, 1: int} [day, periodId]
     */
    private function pickSlot(
        int $classId,
        ?int $teacherId,
        int $classIndex,
        int $subjectIndex,
        array $periods,
        array $usedClassSlots,
        array $usedTeacherSlots
    ): array {
        $base = ($classIndex + $subjectIndex) % 8;

        for ($dayStep = 0; $dayStep < count(self::DAYS); $dayStep++) {
            for ($periodStep = 0; $periodStep < 8; $periodStep++) {
                $day = self::DAYS[($base + $dayStep) % count(self::DAYS)];
                $periodId = $periods[($base + $periodStep) % 8];

                if (!empty($usedClassSlots[$classId][$day][$periodId])) {
                    continue;
                }

                if ($teacherId !== null && !empty($usedTeacherSlots[$teacherId][$day][$periodId])) {
                    continue;
                }

                return [$day, $periodId];
            }
        }

        throw new \RuntimeException("No free schedule slot for class #{$classId}, teacher #{$teacherId}.");
    }

    private function seedClasses(AcademicYear $academicYear)
    {
        foreach (self::TARGET_CLASSES as $classDef) {
            SchoolClass::query()->firstOrCreate(
                [
                    'name' => $classDef['name'],
                    'level' => $classDef['level'],
                    'academic_year' => $academicYear->name,
                ],
                ['teacher_id' => null]
            );
        }

        return SchoolClass::query()
            ->whereIn('name', array_column(self::TARGET_CLASSES, 'name'))
            ->where('academic_year', $academicYear->name)
            ->orderBy('id')
            ->get();
    }

    private function seedSubjects()
    {
        foreach (self::TARGET_SUBJECTS as $subjectDef) {
            // The subject may already exist but be soft-deleted; the canonical
            // row is restored instead of inserting a duplicate (the code column
            // is unique across both live and trashed rows).
            $subject = Subject::withTrashed()->where('code', $subjectDef['code'])->first();

            if (!$subject) {
                Subject::query()->create([
                    'code' => $subjectDef['code'],
                    'name' => $subjectDef['name'],
                    'type' => 'wajib',
                    'description' => null,
                ]);

                continue;
            }

            if ($subject->trashed()) {
                $subject->restore();
            }
        }

        $codes = array_column(self::TARGET_SUBJECTS, 'code');

        return Subject::query()
            ->whereIn('code', $codes)
            ->whereNull('deleted_at')
            ->orderByRaw('FIELD(code, '.implode(',', array_map(fn ($c) => "'{$c}'", $codes)).')')
            ->get();
    }
}