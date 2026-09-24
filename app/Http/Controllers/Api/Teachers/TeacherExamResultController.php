<?php

namespace App\Http\Controllers\Api\Teachers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Examination\ExamResultResource;
use App\Models\Examination\ExamResult;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Teacher self-service: Hasil Ujian (Phase 9).
 *
 * Scope: authenticated user -> teacherProfile -> teacher.id -> TeacherAssignment.
 * Hasil di-scope melalui participant.exam milik guru (lihat
 * Exam::scopeTeacherAccessible).
 */
class TeacherExamResultController extends Controller
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
            'message' => 'Exam result not found',
            'data' => null,
        ], 404);
    }

    /**
     * GET /api/teacher/exam-results
     * Hasil ujian pada mata pelajaran scope mengajar guru.
     */
    public function index(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $query = ExamResult::with(['participant.exam', 'participant.student', 'attempt'])
            ->whereHas('participant.exam', fn ($q) => $q->teacherAccessible($teacher->id));

        if ($request->filled('participant_id')) {
            $query->where('participant_id', $request->input('participant_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $results = $query->orderBy('id', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Exam results retrieved successfully',
            'data' => ExamResultResource::collection($results),
            'meta' => [
                'current_page' => $results->currentPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'last_page' => $results->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/teacher/exam-results/{exam_result}
     * Hanya hasil ujian pada scope mengajar guru; lainnya -> 404.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $result = ExamResult::with(['participant.exam', 'participant.student', 'attempt'])
            ->where('id', $id)
            ->whereHas('participant.exam', fn ($q) => $q->teacherAccessible($teacher->id))
            ->first();

        if (!$result) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'message' => 'Exam result retrieved successfully',
            'data' => new ExamResultResource($result),
        ]);
    }
}
