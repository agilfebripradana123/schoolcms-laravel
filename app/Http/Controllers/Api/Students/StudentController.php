<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Students\UpdateStudentAccountRequest;
use App\Http\Requests\Api\Students\UpdateStudentRequest;
use App\Models\Students\Student;
use App\Services\Students\StudentAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentController extends Controller
{
    /**
     * Menampilkan semua siswa.
     */
    public function index()
    {
        $students = Student::with('user')->latest()->get();

        return response()->json([
            'message' => 'Data siswa berhasil diambil.',
            'data' => $students,
        ]);
    }

    /**
     * Menampilkan detail siswa.
     */
    public function show($id)
    {
        $student = Student::with(['user', 'schoolClass', 'parent', 'guardians'])
            ->find($id);

        if (!$student) {
            return response()->json([
                'message' => 'Data siswa tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'message' => 'Detail siswa berhasil diambil.',
            'data' => $student,
        ]);
    }

    /**
     * Menambahkan siswa.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'nisn' => ['required', 'string', 'max:20', 'unique:students,nisn'],
            'nis' => ['required', 'string', 'max:20', 'unique:students,nis'],
            'name' => ['required', 'string', 'max:100'],
            'gender' => ['required', 'in:L,P'],
            'birth_place' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date'],
            'address' => ['required', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'photo' => ['nullable', 'string', 'max:255'],
        ]);

        $student = Student::create($validated);

        return response()->json([
            'message' => 'Siswa berhasil ditambahkan.',
            'data' => $student,
        ], 201);
    }

    /**
     * Mengubah data siswa.
     */
    public function update(
        UpdateStudentRequest $request,
        $id,
        StudentAccountService $accountService
    ) {
        $student = Student::find($id);

        if (!$student) {
            return response()->json([
                'message' => 'Data siswa tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validated();
        $oldNisn = $student->nisn;

        DB::transaction(function () use ($student, $validated, $oldNisn, $accountService) {
            $student->update($validated);

            $newNisn = (string) ($validated['nisn'] ?? $student->nisn);

            if ($newNisn !== $oldNisn) {
                $accountService->syncNisnUsername($student, $oldNisn, $newNisn);
            }
        });

        return response()->json([
            'message' => 'Data siswa berhasil diperbarui.',
            'data' => $student->load('user'),
        ]);
    }

    /**
     * Menghapus siswa.
     */
    public function destroy($id)
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json([
                'message' => 'Data siswa tidak ditemukan.',
            ], 404);
        }

        $student->delete();

        return response()->json([
            'message' => 'Siswa berhasil dihapus.',
        ]);
    }

    /**
     * PUT /api/students/{student}/account
     * Aktifkan/matikan akun login siswa (provision bila belum ada User).
     */
    public function updateAccount(
        UpdateStudentAccountRequest $request,
        $id,
        StudentAccountService $accountService
    ): JsonResponse {
        $student = Student::with('user')->find($id);

        if (!$student) {
            return response()->json([
                'message' => 'Data siswa tidak ditemukan.',
            ], 404);
        }

        $hadUser = $student->user !== null;
        $isActive = $request->boolean('is_active');

        $student = $accountService->applyStatus(
            $student,
            $isActive,
            $request->input('email'),
            $request->input('password'),
            $request->user(),
            $request->ip(),
            $request->userAgent()
        );

        $user = $student->user;

        if ($isActive) {
            $message = $hadUser
                ? 'Akun siswa berhasil diaktifkan.'
                : 'Akun siswa berhasil dibuat dan diaktifkan.';
        } else {
            $message = $hadUser
                ? 'Akun siswa berhasil dinonaktifkan.'
                : 'Siswa belum memiliki akun login.';
        }

        return response()->json([
            'message' => $message,
            'data' => [
                'is_active' => $user ? (bool) $user->is_active : false,
                'user' => $user ? [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'is_active' => (bool) $user->is_active,
                ] : null,
            ],
        ]);
    }

    /**
     * GET /api/teacher/my-students
     * Siswa yang diajar guru (scope via teacher_assignments).
     */
    public function myStudents(Request $request): JsonResponse
    {
        $user = $request->user()->load('teacherProfile.teacherAssignments');
        $teacher = $user->teacherProfile;

        if (!$teacher) {
            return response()->json(['success' => true, 'message' => 'Data ditemukan', 'data' => []], 200);
        }

        $classIds = $teacher->teacherAssignments->pluck('class_id')->unique()->filter();

        $students = Student::whereIn('class_id', $classIds)
            ->with(['user', 'schoolClass'])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Data ditemukan',
            'data' => $students->map(fn($s) => [
                'id' => $s->id,
                'nis' => $s->nis,
                'name' => $s->user?->name ?? $s->name,
                'email' => $s->user?->email,
                'class_name' => $s->schoolClass?->name,
            ]),
        ], 200);
    }
}