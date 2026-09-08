<?php

namespace App\Http\Controllers\Api\Teachers;

use App\Http\Controllers\Controller;
use App\Models\Academic\ClassStudent;
use App\Models\Staff\TeacherAssignment;
use App\Models\Students\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Teacher self-service: Kehadiran Siswa (Phase 6).
 *
 * Scope berasal dari authenticated user -> teacherProfile -> TeacherAssignment
 * -> authorized class. `class_id` dipakai sebagai filter/request, tetapi selalu
 * diverifikasi ada dalam scope Guru (bukan hanya penentu authorization).
 * `teacher_id` dari client tidak pernah digunakan.
 */
class TeacherAttendanceController extends Controller
{
    private function teacher(Request $request)
    {
        return $request->user()?->teacherProfile;
    }

    private function className($teacher, int $classId)
    {
        return TeacherAssignment::where('teacher_id', $teacher->id)
            ->where('class_id', $classId)
            ->exists();
    }

    /**
     * GET /api/teacher/attendance?date=Y-m-d&class_id=ID
     * Roster siswa aktif di kelas (dalam scope guru) + status kehadiran pada tanggal tsb.
     */
    public function roster(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'class_id' => ['required', 'integer'],
        ]);

        $classId = (int) $validated['class_id'];
        $date = $validated['date'];

        if (!$this->className($teacher, $classId)) {
            return response()->json([
                'success' => false,
                'message' => 'Class not found',
                'data' => null,
            ], 404);
        }

        $enrollments = ClassStudent::where('class_id', $classId)
            ->where('status', 'active')
            ->whereHas('student')
            ->with('student')
            ->get();

        $studentIds = $enrollments->pluck('student_id')->all();

        $attendances = Attendance::where('class_id', $classId)
            ->where('date', $date)
            ->whereIn('student_id', $studentIds)
            ->get()
            ->keyBy('student_id');

        $students = $enrollments->map(function ($enrollment) use ($attendances) {
            $student = $enrollment->student;
            $attendance = $attendances->get($enrollment->student_id);

            return [
                'student_id' => $student->id,
                'nis' => $student->nis,
                'nisn' => $student->nisn,
                'name' => $student->name,
                'gender' => $student->gender,
                'status' => $attendance?->status ?? null,
                'note' => $attendance?->note ?? null,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Teacher attendance roster retrieved successfully',
            'data' => [
                'class_id' => $classId,
                'date' => $date,
                'students' => $students,
            ],
        ]);
    }

    /**
     * POST /api/teacher/attendance
     * Menyimpan kehadiran massal untuk satu kelas+tanggal (idempotent per
     * student+class+date). Setiap student_div sudah dicek milik kelas scope.
     */
    public function store(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'class_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.student_id' => ['required', 'integer'],
            'items.*.status' => ['required', Rule::in(['hadir', 'sakit', 'izin', 'alpa'])],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $classId = (int) $validated['class_id'];
        $date = $validated['date'];

        if (!$this->className($teacher, $classId)) {
            return response()->json([
                'success' => false,
                'message' => 'Class not found',
                'data' => null,
            ], 404);
        }

        // Hanya siswa yang benar-benar aktif di kelas scope dapat diinput.
        $allowedStudentIds = ClassStudent::where('class_id', $classId)
            ->where('status', 'active')
            ->pluck('student_id')
            ->all();

        $allowedSet = array_flip($allowedStudentIds);

        DB::transaction(function () use ($validated, $classId, $date, $allowedSet) {
            foreach ($validated['items'] as $item) {
                $studentId = (int) $item['student_id'];

                if (!isset($allowedSet[$studentId])) {
                    abort(422, "Siswa #{$studentId} bukan anggota kelas yang diizinkan.");
                }

                Attendance::updateOrCreate(
                    [
                        'student_id' => $studentId,
                        'class_id' => $classId,
                        'date' => $date,
                    ],
                    [
                        'status' => $item['status'],
                        'note' => $item['note'] ?? null,
                    ]
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Attendance saved successfully',
            'data' => null,
        ]);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Forbidden',
            'data' => null,
        ], 403);
    }
}
