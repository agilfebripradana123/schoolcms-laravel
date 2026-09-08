<?php

namespace App\Http\Controllers\Api\Teachers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Teachers\TeacherClassResource;
use App\Http\Resources\Teachers\TeacherClassStudentResource;
use App\Models\Academic\ClassStudent;
use App\Models\Academic\SchoolClass;
use App\Models\Staff\TeacherAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Teacher self-service: Kelas & Siswa (Phase 4).
 *
 * Data scope is resolved server-side from the authenticated user ->
 * linked Teacher record -> teacher_assignments. A client-supplied teacher_id
 * is never used to determine scope.
 */
class TeacherClassController extends Controller
{
    /**
     * Resolve the Teacher bound to the authenticated user. Returns null when
     * the user is not linked to a teacher record.
     */
    private function resolveTeacher(Request $request)
    {
        return $request->user()?->teacherProfile;
    }

    /**
     * GET /api/teacher/classes
     * Distinct classes the authenticated teacher teaches (via teacher_assignments),
     * each with an active-student count. Read-only, permission-gated.
     */
    public function myClasses(Request $request): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        if (!$teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
                'data' => null,
            ], 403);
        }

        $classIds = TeacherAssignment::where('teacher_id', $teacher->id)
            ->pluck('class_id')
            ->unique()
            ->values();

        $classes = SchoolClass::whereIn('id', $classIds)
            ->with('teacher')
            ->withCount(['classStudents as students_count' => function ($q) {
                $q->where('status', 'active');
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Teacher classes retrieved successfully',
            'data' => TeacherClassResource::collection($classes),
        ]);
    }

    /**
     * GET /api/teacher/classes/{class}/students
     * Active students enrolled in one of the teacher's classes (server-scoped).
     * Supports server-side search + pagination.
     */
    public function myClassStudents(Request $request, int $classId): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        if (!$teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
                'data' => null,
            ], 403);
        }

        $authorized = TeacherAssignment::where('teacher_id', $teacher->id)
            ->where('class_id', $classId)
            ->exists();

        if (!$authorized) {
            return response()->json([
                'success' => false,
                'message' => 'Class not found',
                'data' => null,
            ], 404);
        }

        $query = ClassStudent::with('student')
            ->where('class_id', $classId)
            ->where('status', 'active');

        if ($request->filled('q')) {
            $search = $request->input('q');
            $query->whereHas('student', function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('nis', 'LIKE', "%{$search}%")
                    ->orWhere('nisn', 'LIKE', "%{$search}%");
            });
        }

        $students = $query->orderBy('id', 'asc')->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'message' => 'Teacher class students retrieved successfully',
            'data' => TeacherClassStudentResource::collection($students),
            'meta' => [
                'current_page' => $students->currentPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
                'last_page' => $students->lastPage(),
            ],
        ]);
    }
}
