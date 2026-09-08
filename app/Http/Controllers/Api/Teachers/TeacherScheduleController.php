<?php

namespace App\Http\Controllers\Api\Teachers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Teachers\TeacherScheduleResource;
use App\Models\Academic\Schedule;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Teacher self-service: Jadwal Mengajar (Phase 5).
 *
 * Data scope is resolved server-side from the authenticated user -> linked
 * Teacher record -> schedules.teacher_id. A client-supplied teacher_id is never
 * used to determine scope. All optional filters stay within the teacher scope.
 */
class TeacherScheduleController extends Controller
{
    /**
     * GET /api/teacher/schedules
     * Schedules belonging to the authenticated teacher, optionally filtered by
     * day / academic_year_id / semester_id / class_id / subject_id — all applied
     * on top of the mandatory teacher scope.
     */
    public function mySchedules(Request $request): JsonResponse
    {
        $teacher = $request->user()?->teacherProfile;

        if (!$teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
                'data' => null,
            ], 403);
        }

        $query = Schedule::with(['schoolClass', 'subject', 'period', 'academicYear', 'semester'])
            ->where('teacher_id', $teacher->id);

        if ($request->filled('day')) {
            $query->where('day', $request->input('day'));
        }

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', $request->input('academic_year_id'));
        }

        if ($request->filled('semester_id')) {
            $query->where('semester_id', $request->input('semester_id'));
        }

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->input('class_id'));
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->input('subject_id'));
        }

        $schedules = $query->orderBy('day')->orderBy('period_id')->get();

        return response()->json([
            'success' => true,
            'message' => 'Teacher schedules retrieved successfully',
            'data' => TeacherScheduleResource::collection($schedules),
        ]);
    }
}
