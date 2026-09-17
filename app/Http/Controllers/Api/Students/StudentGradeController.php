<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Controller;
use App\Models\Academic\AcademicYear;
use App\Models\Academic\Grade;
use App\Models\Academic\Semester;
use App\Services\Academic\GradeAggregationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Student Portal — Grades (read-only, identity scoped).
 *
 * Returns only grades belonging to the authenticated student.
 */
class StudentGradeController extends Controller
{
    private GradeAggregationService $aggregation;

    public function __construct(GradeAggregationService $aggregation)
    {
        $this->aggregation = $aggregation;
    }

    public function index(Request $request): JsonResponse
    {
        $student = $request->attributes->get('student_profile');

        $validated = $request->validate([
            'semester' => 'nullable|string|in:1,2',
            'academic_year' => 'nullable|string',
            'semester_id' => 'nullable|integer',
            'academic_year_id' => 'nullable|integer',
        ]);

        [$academicYear, $semester] = $this->periodFilters($validated);

        $grades = Grade::with(['subject', 'schoolClass'])
            ->where('student_id', $student->id)
            ->when($academicYear !== null, fn ($query) => $query->where($academicYear[0], $academicYear[1]))
            ->when($semester !== null, fn ($query) => $query->where($semester[0], $semester[1]))
            ->orderBy('subject_id')
            ->get();

        // Group by subject: pivot tugas/uts/uas per subject+semester+year
        $rows = collect();
        $grouped = $grades->groupBy(fn ($g) => $g->subject_id.'|'.$g->semester.'|'.$g->academic_year);

        foreach ($grouped as $group) {
            $first = $group->first();
            $row = [
                'id' => $first->id,
                'subject_id' => $first->subject_id,
                'subject_name' => $first->subject->name ?? '-',
                'class_name' => $first->schoolClass->name ?? '-',
                'semester' => $first->semester,
                'academic_year' => $first->academic_year,
                'tugas' => null,
                'uts' => null,
                'uas' => null,
                'final_score' => null,
            ];

            foreach ($group as $g) {
                $row[$g->type] = (float) $g->score;
            }

            // Final score: assessment-derived canonical weighted final (Phase 2L-5).
            // Read-only derivation from grade_assessments; never falls back to
            // grades.score, which remains the persisted bucket-value source above.
            $derived = $this->aggregation->weightedFinalScore(
                $student->id,
                $first->subject_id,
                $first->class_id,
                $first->academic_year_id,
                $first->semester_id
            );

            $row['final_score'] = $derived['weighted_final_score'];

            $rows->push($row);
        }

        return response()->json([
            'success' => true,
            'message' => 'Grades retrieved successfully',
            'data' => $rows,
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $student = $request->attributes->get('student_profile');

        $validated = $request->validate([
            'semester' => 'nullable|string|in:1,2',
            'academic_year' => 'nullable|string',
            'semester_id' => 'nullable|integer',
            'academic_year_id' => 'nullable|integer',
        ]);

        [$academicYear, $semester] = $this->periodFilters($validated);

        [$academicYearId, $semesterId] = $this->canonicalPeriodIds($academicYear, $semester);

        $grades = Grade::where('student_id', $student->id)
            ->when($academicYear !== null, fn ($query) => $query->where($academicYear[0], $academicYear[1]))
            ->when($semester !== null, fn ($query) => $query->where($semester[0], $semester[1]))
            ->get();

        // Canonical identities from the filtered grade rows. Multiple class or
        // period identities for one subject are separate academic records.
        $identityKeys = $grades
            ->map(fn (Grade $grade) => $this->identityKey($grade))
            ->unique()
            ->values();

        $finals = $this->aggregation->weightedFinalScoresForStudent(
            $student->id,
            $academicYearId,
            $semesterId,
        );

        $contributed = [];

        foreach ($identityKeys as $key) {
            $final = $finals[$key] ?? null;

            if ($final !== null) {
                $contributed[] = (float) $final;
            }
        }

        $totalSubjects = count($contributed);
        $average = $totalSubjects > 0 ? round(array_sum($contributed) / $totalSubjects, 2) : 0;
        $highest = $totalSubjects > 0 ? max($contributed) : 0;

        return response()->json([
            'success' => true,
            'message' => 'Grade summary retrieved successfully',
            'data' => [
                'average' => $average,
                'highest' => $highest,
                'total_subjects' => $totalSubjects,
            ],
        ]);
    }

    /**
     * Resolve canonical period filters. IDs take precedence; legacy strings are
     * translated to the alias columns; contradictory id/string combos are rejected.
     *
     * @return array{0: ?array{0: string, 1: mixed}, 1: ?array{0: string, 1: mixed}}
     */
    private function periodFilters(array $validated): array
    {
        if (! empty($validated['academic_year_id']) && ! empty($validated['academic_year'])) {
            $name = AcademicYear::where('id', $validated['academic_year_id'])->value('name');

            if ($name !== null && $name !== $validated['academic_year']) {
                throw ValidationException::withMessages([
                    'academic_year' => ['The academic_year value conflicts with academic_year_id.'],
                ]);
            }
        }

        if (! empty($validated['semester_id']) && ! empty($validated['semester'])) {
            $name = Semester::where('id', $validated['semester_id'])->value('name');

            if ($name !== null && $name !== $validated['semester']) {
                throw ValidationException::withMessages([
                    'semester' => ['The semester value conflicts with semester_id.'],
                ]);
            }
        }

        if (! empty($validated['academic_year_id'])) {
            $academicYear = ['academic_year_id', $validated['academic_year_id']];
        } elseif (! empty($validated['academic_year'])) {
            $academicYear = ['academic_year', $validated['academic_year']];
        } else {
            $academicYear = null;
        }

        if (! empty($validated['semester_id'])) {
            $semester = ['semester_id', $validated['semester_id']];
        } elseif (! empty($validated['semester'])) {
            $semester = ['semester', $validated['semester']];
        } else {
            $semester = null;
        }

        return [$academicYear, $semester];
    }

    /**
     * Resolve canonical period ids for assessment scoping.
     *
     * Legacy string aliases are mapped to ids only when unambiguous (semester
     * name requires a known academic year); otherwise null is returned so the
     * batch load covers the student scope and canonical identity grouping
     * against the filtered grade rows selects the exact period.
     *
     * @return array{0: int|null, 1: int|null} academic_year_id, semester_id
     */
    private function canonicalPeriodIds(?array $academicYear, ?array $semester): array
    {
        $academicYearId = null;

        if ($academicYear !== null) {
            $academicYearId = $academicYear[0] === 'academic_year_id'
                ? (int) $academicYear[1]
                : AcademicYear::where('name', $academicYear[1])->value('id');
        }

        $semesterId = null;

        if ($semester !== null) {
            if ($semester[0] === 'semester_id') {
                $semesterId = (int) $semester[1];
            } elseif ($academicYearId !== null) {
                $semesterId = Semester::where('academic_year_id', $academicYearId)
                    ->where('name', $semester[1])
                    ->value('id');
            }
        }

        return [
            $academicYearId !== null ? (int) $academicYearId : null,
            $semesterId !== null ? (int) $semesterId : null,
        ];
    }

    /**
     * Canonical assessment identity key for one grade row.
     * Mirrors GradeAggregationService::identityKey().
     */
    private function identityKey(Grade $grade): string
    {
        return implode('|', [
            $grade->subject_id,
            $grade->class_id,
            $grade->academic_year_id,
            $grade->semester_id,
        ]);
    }
}
