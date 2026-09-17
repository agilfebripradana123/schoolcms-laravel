<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\Academic\AcademicYear;
use App\Models\Academic\Semester;
use App\Services\Academic\GradeAggregationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AcademicReportController extends Controller
{
    public function __construct(private GradeAggregationService $aggregation) {}

    public function gradesSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_id' => 'nullable|integer',
            'subject_id' => 'nullable|integer',
            'semester' => 'nullable|string|in:1,2',
            'academic_year' => 'nullable|string',
            'semester_id' => 'nullable|integer',
            'academic_year_id' => 'nullable|integer',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 15);

        $classId = isset($validated['class_id']) ? (int) $validated['class_id'] : null;
        $subjectId = isset($validated['subject_id']) ? (int) $validated['subject_id'] : null;

        [$academicYearFilter, $semesterFilter] = $this->periodFilters($validated);
        [$academicYearId, $semesterId] = $this->canonicalPeriodIds($academicYearFilter, $semesterFilter);

        $identityQuery = DB::table('grades')
            ->join('students', 'grades.student_id', '=', 'students.id')
            ->whereNull('students.deleted_at')
            ->select(
                'grades.student_id',
                'students.name AS student_name',
                'grades.subject_id',
                'grades.class_id',
                'grades.academic_year_id',
                'grades.semester_id',
            );

        if ($classId !== null) {
            $identityQuery->where('grades.class_id', $classId);
        }

        if ($subjectId !== null) {
            $identityQuery->where('grades.subject_id', $subjectId);
        }

        if ($academicYearFilter !== null) {
            $identityQuery->where('grades.'.$academicYearFilter[0], $academicYearFilter[1]);
        }

        if ($semesterFilter !== null) {
            $identityQuery->where('grades.'.$semesterFilter[0], $semesterFilter[1]);
        }

        $identityRows = $identityQuery->get();

        $population = [];
        $identityKeys = [];

        foreach ($identityRows as $row) {
            $population[(int) $row->student_id] = $row->student_name;

            $identityKeys[$this->identityKey(
                (int) $row->student_id,
                (int) $row->subject_id,
                (int) $row->class_id,
                (int) $row->academic_year_id,
                (int) $row->semester_id,
            )] = (int) $row->student_id;
        }

        $studentIds = array_keys($population);

        $finals = $this->aggregation->weightedFinalScoresForStudentPopulation(
            $studentIds,
            $classId,
            $subjectId,
            $academicYearId,
            $semesterId,
        );

        $finalsByStudent = [];

        foreach ($identityKeys as $key => $studentId) {
            $final = $finals[$key] ?? null;

            if ($final === null) {
                continue;
            }

            $finalsByStudent[$studentId][] = (float) $final;
        }

        $rows = [];

        foreach ($population as $studentId => $studentName) {
            $finals = $finalsByStudent[$studentId] ?? [];

            $totalGrades = count($finals);
            $averageScore = $totalGrades > 0 ? round(array_sum($finals) / $totalGrades, 2) : 0;

            $rows[] = [
                'student_id' => $studentId,
                'student_name' => $studentName,
                'average_score' => $averageScore,
                'total_grades' => $totalGrades,
            ];
        }

        usort($rows, function (array $a, array $b) {
            return $b['average_score'] <=> $a['average_score']
                ?: $a['student_id'] <=> $b['student_id'];
        });

        $total = count($rows);
        $sliced = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'success' => true,
            'message' => 'Academic grade summary retrieved successfully',
            'data' => $sliced,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $total > 0 ? (int) ceil($total / $perPage) : 1,
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
                return $this->periodConflict();
            }
        }

        if (! empty($validated['semester_id']) && ! empty($validated['semester'])) {
            $name = Semester::where('id', $validated['semester_id'])->value('name');

            if ($name !== null && $name !== $validated['semester']) {
                return $this->periodConflict();
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
     * @return never
     */
    private function periodConflict(): array
    {
        abort(422, 'The id and string period filters conflict.');
    }

    private function identityKey(int $studentId, int $subjectId, int $classId, int $academicYearId, int $semesterId): string
    {
        return implode('|', [$studentId, $subjectId, $classId, $academicYearId, $semesterId]);
    }
}
