<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Controller;
use App\Models\Academic\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentScheduleController extends Controller
{
    /**
     * GET /api/student/schedules
     *
     * Schedule scope is the authenticated student's authoritative class
     * enrollment (class_students.status = active), matched on BOTH class_id
     * and academic_year_id. The legacy nullable `students.class_id` column is
     * NOT used as a schedule source. Semester is intentionally not used as a
     * filter because class_students is year-scoped only.
     */
    public function index(Request $request): JsonResponse
    {
        $student = $request->attributes->get('student_profile');

        $validated = $request->validate([
            'day' => 'nullable|string',
        ]);

        $query = Schedule::with(['schoolClass', 'subject', 'teacher', 'period'])
            ->whereExists(function ($enrollment) use ($student) {
                $enrollment->select('class_students.id')
                    ->from('class_students')
                    ->whereColumn('class_students.class_id', 'schedules.class_id')
                    ->whereColumn('class_students.academic_year_id', 'schedules.academic_year_id')
                    ->where('class_students.student_id', $student->id)
                    ->where('class_students.status', 'active');
            });

        if (!empty($validated['day'])) {
            $query->where('day', $validated['day']);
        }

        $schedules = $query->orderBy('day', 'asc')->get();

        $daysOrder = ['senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4, 'jumat' => 5, 'sabtu' => 6];

        $formatted = [];
        foreach ($schedules as $schedule) {
            $period = $schedule->period;
            $formatted[] = [
                'id' => $schedule->id,
                'day' => $schedule->day,
                'start_time' => $period->start_time ?? null,
                'end_time' => $period->end_time ?? null,
                'subject_name' => $schedule->subject->name ?? '-',
                'teacher_name' => $schedule->teacher->full_name ?? null,
                'room_name' => $schedule->schoolClass->name ?? '-',
            ];
        }

        usort($formatted, function ($a, $b) use ($daysOrder) {
            $dayCmp = ($daysOrder[$a['day']] ?? 99) <=> ($daysOrder[$b['day']] ?? 99);
            if ($dayCmp !== 0) {
                return $dayCmp;
            }
            $startA = $a['start_time'] ?? '00:00';
            $startB = $b['start_time'] ?? '00:00';
            return strcmp($startA, $startB);
        });

        return response()->json([
            'success' => true,
            'message' => 'Schedules retrieved successfully',
            'data' => $formatted,
        ]);
    }
}