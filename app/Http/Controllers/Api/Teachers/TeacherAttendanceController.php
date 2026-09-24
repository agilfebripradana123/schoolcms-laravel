<?php

namespace App\Http\Controllers\Api\Teachers;

use App\Http\Controllers\Controller;
use App\Models\Academic\AcademicYear;
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
 *
 * Academic context (academic_year_id) is server-authoritative: it is part of
 * every teacher read/write and is resolved from the active academic year when
 * the client omits it (cf. TeacherGradeController). The lookup/update identity
 * therefore includes the year, so a teacher can never overwrite a record that
 * belongs to another academic year.
 */
class TeacherAttendanceController extends Controller
{
    private function teacher(Request $request)
    {
        return $request->user()?->teacherProfile;
    }

    private function className($teacher, int $classId, int $academicYearId)
    {
        return TeacherAssignment::where('teacher_id', $teacher->id)
            ->where('class_id', $classId)
            ->where('academic_year_id', $academicYearId)
            ->exists();
    }

    /**
     * GET /api/teacher/attendance?date=Y-m-d&class_id=ID[&academic_year_id=ID]
     * Roster siswa aktif di kelas (dalam scope guru, untuk tahun ajaran terpilih)
     * + status kehadiran pada tanggal tsb.
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
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);

        $classId = (int) $validated['class_id'];
        $date = $validated['date'];
        $yearId = (int) ($validated['academic_year_id'] ?? AcademicYear::where('is_active', true)
            ->orderBy('id')
            ->value('id'));

        if (!$this->className($teacher, $classId, $yearId)) {
            return response()->json([
                'success' => false,
                'message' => 'Class not found',
                'data' => null,
            ], 404);
        }

        $enrollments = ClassStudent::where('class_id', $classId)
            ->where('academic_year_id', $yearId)
            ->where('status', 'active')
            ->whereHas('student')
            ->with('student')
            ->get();

        $studentIds = $enrollments->pluck('student_id')->all();

        $attendances = Attendance::where('class_id', $classId)
            ->where('academic_year_id', $yearId)
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
                'academic_year_id' => $yearId,
                'date' => $date,
                'students' => $students,
            ],
        ]);
    }

    /**
     * POST /api/teacher/attendance
     * Menyimpan kehadiran massal untuk satu kelas+tanggal+tahun ajaran
     * (idempotent per student+class+date+academic_year_id). Setiap student
     * sudah dicek anggota kelas pada tahun ajaran tsb.
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
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.student_id' => ['required', 'integer'],
            'items.*.status' => ['required', Rule::in(['hadir', 'sakit', 'izin', 'alpa'])],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $classId = (int) $validated['class_id'];
        $date = $validated['date'];
        $yearId = (int) ($validated['academic_year_id'] ?? AcademicYear::where('is_active', true)
            ->orderBy('id')
            ->value('id'));

        if (!$this->className($teacher, $classId, $yearId)) {
            return response()->json([
                'success' => false,
                'message' => 'Class not found',
                'data' => null,
            ], 404);
        }

        // Hanya siswa yang benar-benar aktif di kelas scope (pada tahun ajaran tsb)
        // dapat diinput.
        $allowedStudentIds = ClassStudent::where('class_id', $classId)
            ->where('academic_year_id', $yearId)
            ->where('status', 'active')
            ->pluck('student_id')
            ->all();

        $allowedSet = array_flip($allowedStudentIds);

        DB::transaction(function () use ($validated, $classId, $date, $yearId, $allowedSet) {
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
                        'academic_year_id' => $yearId,
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