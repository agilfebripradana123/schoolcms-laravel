<?php

namespace App\Http\Controllers\Api\Teachers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Examination\ExamScheduleResource;
use App\Models\Examination\ExamSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Teacher self-service: Jadwal Ujian (Phase 9).
 *
 * Scope: authenticated user -> teacherProfile -> teacher.id -> TeacherAssignment.
 * Jadwal di-scope melalui exam milik guru (lihat Exam::scopeTeacherAccessible).
 */
class TeacherExamScheduleController extends Controller
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
            'message' => 'Exam schedule not found',
            'data' => null,
        ], 404);
    }

    /**
     * GET /api/teacher/exam-schedules
     * Jadwal ujian pada mata pelajaran scope mengajar guru.
     */
    public function index(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $query = ExamSchedule::with(['exam', 'room', 'session'])
            ->whereHas('exam', fn ($q) => $q->teacherAccessible($teacher->id));

        if ($request->filled('exam_id')) {
            $query->where('exam_id', $request->input('exam_id'));
        }

        if ($request->filled('room_id')) {
            $query->where('room_id', $request->input('room_id'));
        }

        if ($request->filled('session_id')) {
            $query->where('session_id', $request->input('session_id'));
        }

        if ($request->filled('exam_date')) {
            $query->whereDate('exam_date', $request->input('exam_date'));
        }

        $schedules = $query->orderBy('id', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Exam schedules retrieved successfully',
            'data' => ExamScheduleResource::collection($schedules),
            'meta' => [
                'current_page' => $schedules->currentPage(),
                'per_page' => $schedules->perPage(),
                'total' => $schedules->total(),
                'last_page' => $schedules->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/teacher/exam-schedules/{exam_schedule}
     * Hanya jadwal ujian pada scope mengajar guru; lainnya -> 404.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $schedule = ExamSchedule::with(['exam', 'room', 'session'])
            ->where('id', $id)
            ->whereHas('exam', fn ($q) => $q->teacherAccessible($teacher->id))
            ->first();

        if (!$schedule) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'message' => 'Exam schedule retrieved successfully',
            'data' => new ExamScheduleResource($schedule),
        ]);
    }
}
