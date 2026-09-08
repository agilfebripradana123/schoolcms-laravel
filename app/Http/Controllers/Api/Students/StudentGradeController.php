<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Controller;
use App\Models\Academic\AcademicYear;
use App\Models\Academic\Grade;
use App\Models\Academic\Semester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Student Portal — Grades (read-only, identity scoped).
 *
 * Returns only grades belonging to the authenticated student.
 */
class StudentGradeController extends Controller
{
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
        $grouped = $grades->groupBy(fn ($g) => $g->subject_id . '|' . $g->semester . '|' . $g->academic_year);

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

            // Final score: average of all present scores
            $scores = array_filter([$row['tugas'], $row['uts'], $row['uas']], fn ($v) => $v !== null);
            $row['final_score'] = count($scores) > 0 ? round(array_sum($scores) / count($scores), 2) : null;

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

        $grades = Grade::where('student_id', $student->id)
            ->when($academicYear !== null, fn ($query) => $query->where($academicYear[0], $academicYear[1]))
            ->when($semester !== null, fn ($query) => $query->where($semester[0], $semester[1]))
            ->get();

        // Group by subject to compute per-subject final scores
        $grouped = $grades->groupBy(fn ($g) => $g->subject_id);
        $finalScores = [];

        foreach ($grouped as $group) {
            $scores = $group->pluck('score')->filter()->values();
            if ($scores->isNotEmpty()) {
                $finalScores[] = round($scores->sum() / $scores->count(), 2);
            }
        }

        $totalSubjects = count($finalScores);
        $average = $totalSubjects > 0 ? round(array_sum($finalScores) / $totalSubjects, 2) : 0;
        $highest = $totalSubjects > 0 ? max($finalScores) : 0;

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
        if (!empty($validated['academic_year_id']) && !empty($validated['academic_year'])) {
            $name = AcademicYear::where('id', $validated['academic_year_id'])->value('name');

            if ($name !== null && $name !== $validated['academic_year']) {
                throw ValidationException::withMessages([
                    'academic_year' => ['The academic_year value conflicts with academic_year_id.'],
                ]);
            }
        }

        if (!empty($validated['semester_id']) && !empty($validated['semester'])) {
            $name = Semester::where('id', $validated['semester_id'])->value('name');

            if ($name !== null && $name !== $validated['semester']) {
                throw ValidationException::withMessages([
                    'semester' => ['The semester value conflicts with semester_id.'],
                ]);
            }
        }

        if (!empty($validated['academic_year_id'])) {
            $academicYear = ['academic_year_id', $validated['academic_year_id']];
        } elseif (!empty($validated['academic_year'])) {
            $academicYear = ['academic_year', $validated['academic_year']];
        } else {
            $academicYear = null;
        }

        if (!empty($validated['semester_id'])) {
            $semester = ['semester_id', $validated['semester_id']];
        } elseif (!empty($validated['semester'])) {
            $semester = ['semester', $validated['semester']];
        } else {
            $semester = null;
        }

        return [$academicYear, $semester];
    }
}