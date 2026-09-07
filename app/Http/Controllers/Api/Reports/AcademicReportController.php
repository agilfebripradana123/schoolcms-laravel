<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AcademicReportController extends Controller
{
    public function gradesSummary(\Illuminate\Http\Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_id' => 'nullable|integer',
            'subject_id' => 'nullable|integer',
            'semester' => 'nullable|string|in:1,2',
            'academic_year' => 'nullable|string',
            'semester_id' => 'nullable|integer',
            'academic_year_id' => 'nullable|integer',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = DB::table('grades')
            ->join('students', 'grades.student_id', '=', 'students.id')
            ->join('subjects', 'grades.subject_id', '=', 'subjects.id')
            ->whereNull('students.deleted_at')
            ->whereNull('subjects.deleted_at');

        if (!empty($validated['class_id'])) {
            $query->where('grades.class_id', $validated['class_id']);
        }

        if (!empty($validated['subject_id'])) {
            $query->where('grades.subject_id', $validated['subject_id']);
        }

        if (!empty($validated['semester_id'])) {
            $query->where('grades.semester_id', $validated['semester_id']);
        } elseif (!empty($validated['semester'])) {
            $query->where('grades.semester', $validated['semester']);
        }

        if (!empty($validated['academic_year_id'])) {
            $query->where('grades.academic_year_id', $validated['academic_year_id']);
        } elseif (!empty($validated['academic_year'])) {
            $query->where('grades.academic_year', $validated['academic_year']);
        }

        $rows = $query->select(
            'grades.student_id AS student_id',
            'students.name AS student_name'
        )
            ->addSelect(DB::raw('AVG(grades.score) AS average_score'))
            ->addSelect(DB::raw('COUNT(*) AS total_grades'))
            ->groupBy('grades.student_id', 'students.name')
            ->orderBy('average_score', 'desc')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'message' => 'Academic grade summary retrieved successfully',
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }
}
