<?php

namespace App\Http\Controllers\Api\Teachers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Academic\AssignmentResource;
use App\Models\Academic\AcademicYear;
use App\Models\Academic\Assignment;
use App\Models\Staff\TeacherAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Teacher self-service: Tugas (Phase 8).
 *
 * Scope: authenticated user -> teacherProfile -> teacher.id.
 * Assignment yang dikelola harus memiliki teacher_id = authenticated teacher, dan
 * kombinasi class/subject/academic_year harus merupakan TeacherAssignment milik guru.
 * teacher_id dari payload tidak pernah dipercaya sebagai authorization.
 */
class TeacherAssignmentController extends Controller
{
    private function teacher(Request $request)
    {
        return $request->user()?->teacherProfile;
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Forbidden',
            'data' => null,
        ], 403);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Assignment not found',
            'data' => null,
        ], 404);
    }

    private function inTeachingScope(int $teacherId, int $classId, int $subjectId, int $academicYearId): bool
    {
        return TeacherAssignment::where('teacher_id', $teacherId)
            ->where('class_id', $classId)
            ->where('subject_id', $subjectId)
            ->where('academic_year_id', $academicYearId)
            ->exists();
    }

    /**
     * GET /api/teacher/assignments
     * Assignment milik authenticated teacher (dengan filter server-side).
     */
    public function index(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $query = Assignment::with(['subject', 'schoolClass', 'teacher', 'academicYear'])
            ->where('teacher_id', $teacher->id);

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->input('class_id'));
        }
        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->input('subject_id'));
        }
        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', $request->input('academic_year_id'));
        }
        if ($request->filled('q')) {
            $query->where('title', 'LIKE', '%' . $request->input('q') . '%');
        }

        $assignments = $query->orderBy('id', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Assignments retrieved successfully',
            'data' => AssignmentResource::collection($assignments),
            'meta' => [
                'current_page' => $assignments->currentPage(),
                'per_page' => $assignments->perPage(),
                'total' => $assignments->total(),
                'last_page' => $assignments->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/teacher/assignments/{assignment}
     * Hanya assignment milik teacher login; milik guru lain -> 404.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $assignment = Assignment::with(['subject', 'schoolClass', 'teacher', 'academicYear'])
            ->where('id', $id)
            ->where('teacher_id', $teacher->id)
            ->first();

        if (!$assignment) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'message' => 'Assignment retrieved successfully',
            'data' => new AssignmentResource($assignment),
        ]);
    }

    /**
     * POST /api/teacher/assignments
     * teacher ditentukan dari authenticated user; class/subject/year harus dalam
     * TeacherAssignment milik guru.
     */
    public function store(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'due_date' => ['nullable', 'date'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ]);

        if (!$this->inTeachingScope(
            $teacher->id,
            (int) $validated['class_id'],
            (int) $validated['subject_id'],
            (int) $validated['academic_year_id']
        )) {
            return $this->notFound();
        }

        $assignment = Assignment::create(array_merge($validated, [
            'teacher_id' => $teacher->id,
        ]));
        $assignment->load(['subject', 'schoolClass', 'teacher', 'academicYear']);

        return response()->json([
            'success' => true,
            'message' => 'Assignment created successfully',
            'data' => new AssignmentResource($assignment),
        ], 201);
    }

    /**
     * PUT/PATCH /api/teacher/assignments/{assignment}
     * Hanya assignment milik teacher; jika class/subject/year berubah, validasi ulang scope.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $assignment = Assignment::where('id', $id)->where('teacher_id', $teacher->id)->first();

        if (!$assignment) {
            return $this->notFound();
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'subject_id' => ['sometimes', 'integer', 'exists:subjects,id'],
            'class_id' => ['sometimes', 'integer', 'exists:classes,id'],
            'due_date' => ['nullable', 'date'],
            'academic_year_id' => ['sometimes', 'integer', 'exists:academic_years,id'],
        ]);

        $nextClass = $validated['class_id'] ?? $assignment->class_id;
        $nextSubject = $validated['subject_id'] ?? $assignment->subject_id;
        $nextYear = $validated['academic_year_id'] ?? $assignment->academic_year_id;

        if (!$this->inTeachingScope($teacher->id, (int) $nextClass, (int) $nextSubject, (int) $nextYear)) {
            return $this->notFound();
        }

        $assignment->update($validated);
        $assignment->load(['subject', 'schoolClass', 'teacher', 'academicYear']);

        return response()->json([
            'success' => true,
            'message' => 'Assignment updated successfully',
            'data' => new AssignmentResource($assignment),
        ]);
    }

    /**
     * DELETE /api/teacher/assignments/{assignment}
     * Hanya assignment milik teacher login.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $assignment = Assignment::where('id', $id)->where('teacher_id', $teacher->id)->first();

        if (!$assignment) {
            return $this->notFound();
        }

        $assignment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Assignment deleted successfully',
            'data' => null,
        ], 200);
    }
}
