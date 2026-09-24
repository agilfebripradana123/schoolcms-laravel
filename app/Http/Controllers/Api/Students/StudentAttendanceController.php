<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Controller;
use App\Models\Academic\AcademicYear;
use App\Models\Students\Attendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentAttendanceController extends Controller
{
    /**
     * Resolve the academic year used to scope a student's history. An explicit
     * `academic_year_id` param wins; otherwise fall back to the active academic
     * year (server-authoritative, cf. TeacherGradeController). History must not
     * silently aggregate unrelated academic years.
     */
    private function resolveYearId(Request $request): int
    {
        $validated = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);

        return (int) ($validated['academic_year_id'] ?? AcademicYear::where('is_active', true)
            ->orderBy('id')
            ->value('id'));
    }

    /**
     * Summary of attendance for the authenticated student (within one academic
     * year).
     */
    public function summary(Request $request): JsonResponse
    {
        $student = $request->attributes->get('student_profile');

        $yearId = $this->resolveYearId($request);

        $yearQuery = function ($query) use ($student, $yearId) {
            $query->where('student_id', $student->id)
                ->where('academic_year_id', $yearId);
        };

        $total = Attendance::where(fn ($q) => $yearQuery($q))->count();
        $present = Attendance::where(fn ($q) => $yearQuery($q))->where('status', 'hadir')->count();
        $sick = Attendance::where(fn ($q) => $yearQuery($q))->where('status', 'sakit')->count();
        $permission = Attendance::where(fn ($q) => $yearQuery($q))->where('status', 'izin')->count();
        $absent = Attendance::where(fn ($q) => $yearQuery($q))->where('status', 'alpa')->count();

        $percentage = $total > 0 ? round(($present / $total) * 100, 2) : 0;

        return response()->json([
            'success' => true,
            'message' => 'Attendance summary retrieved successfully',
            'data' => [
                'academic_year_id' => $yearId,
                'total_days' => $total,
                'present' => $present,
                'sick' => $sick,
                'permission' => $permission,
                'absent' => $absent,
                'percentage' => $percentage,
            ],
        ]);
    }

    /**
     * List attendance records for the authenticated student (within one
     * academic year).
     */
    public function index(Request $request): JsonResponse
    {
        $student = $request->attributes->get('student_profile');

        $validated = $request->validate([
            'status' => 'nullable|string|in:hadir,sakit,izin,alpa',
            'academic_year_id' => 'nullable|integer|exists:academic_years,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $yearId = (int) ($validated['academic_year_id'] ?? AcademicYear::where('is_active', true)
            ->orderBy('id')
            ->value('id'));

        $query = Attendance::where('student_id', $student->id)
            ->where('academic_year_id', $yearId)
            ->with(['schoolClass', 'academicYear'])
            ->latest('date');

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $perPage = $validated['per_page'] ?? 20;
        $attendances = $query->paginate($perPage, ['*'], 'page', $validated['page'] ?? 1);

        $formatted = $attendances->map(function ($item) {
            return [
                'id' => $item->id,
                'date' => $item->date->format('Y-m-d'),
                'status' => $item->status,
                'note' => $item->note,
                'class_name' => $item->schoolClass->name ?? '-',
                'academic_year_id' => $item->academic_year_id,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Attendance retrieved successfully',
            'data' => $formatted,
            'meta' => [
                'current_page' => $attendances->currentPage(),
                'per_page' => $attendances->perPage(),
                'total' => $attendances->total(),
                'last_page' => $attendances->lastPage(),
            ],
        ]);
    }
}