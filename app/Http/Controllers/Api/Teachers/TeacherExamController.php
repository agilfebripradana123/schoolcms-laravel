<?php

namespace App\Http\Controllers\Api\Teachers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Examination\ExamResource;
use App\Models\Examination\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Teacher self-service: Ujian (Phase 9).
 *
 * Scope: authenticated user -> teacherProfile -> teacher.id -> TeacherAssignment.
 * Classless exams are scoped by the subject a teacher teaches (subject-only);
 * class-scoped exams additionally require a TeacherAssignment for that class,
 * subject and academic year (see Exam::scopeTeacherAccessible). Client never
 * sends teacher_id as scope.
 */
class TeacherExamController extends Controller
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
            'message' => 'Exam not found',
            'data' => null,
        ], 404);
    }

    /**
     * GET /api/teacher/exams
     * Ujian pada mata pelajaran yang menjadi scope mengajar guru.
     */
    public function index(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $query = Exam::with('subject')->teacherAccessible($teacher->id);

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->input('subject_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        $exams = $query->orderBy('id', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Exams retrieved successfully',
            'data' => ExamResource::collection($exams),
            'meta' => [
                'current_page' => $exams->currentPage(),
                'per_page' => $exams->perPage(),
                'total' => $exams->total(),
                'last_page' => $exams->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/teacher/exams/{exam}
     * Hanya ujian pada mata pelajaran scope mengajar guru; lainnya -> 404.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $exam = Exam::with('subject')
            ->where('id', $id)
            ->teacherAccessible($teacher->id)
            ->first();

        if (!$exam) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'message' => 'Exam retrieved successfully',
            'data' => new ExamResource($exam),
        ]);
    }
}
