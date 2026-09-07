<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Academic\StoreGradeRequest;
use App\Http\Requests\Api\Academic\UpdateGradeRequest;
use App\Http\Resources\Academic\GradeResource;
use App\Models\Academic\AcademicYear;
use App\Models\Academic\Grade;
use App\Models\Academic\Semester;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GradeController extends Controller
{
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'nullable|integer',
            'subject_id' => 'nullable|integer',
            'class_id' => 'nullable|integer',
            'type' => 'nullable|string|in:tugas,uts,uas',
            'semester' => 'nullable|string|in:1,2',
            'academic_year' => 'nullable|string',
            'semester_id' => 'nullable|integer',
            'academic_year_id' => 'nullable|integer',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = Grade::with(['student', 'subject', 'schoolClass']);

        if (!empty($validated['student_id'])) {
            $query->where('student_id', $validated['student_id']);
        }

        if (!empty($validated['subject_id'])) {
            $query->where('subject_id', $validated['subject_id']);
        }

        if (!empty($validated['class_id'])) {
            $query->where('class_id', $validated['class_id']);
        }

        if (!empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $this->appendPeriodFilters($query, $validated);

        $perPage = $validated['per_page'] ?? 10;
        $grades = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Grades retrieved successfully',
            'data' => GradeResource::collection($grades),
            'meta' => [
                'current_page' => $grades->currentPage(),
                'per_page' => $grades->perPage(),
                'total' => $grades->total(),
                'last_page' => $grades->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $grade = Grade::with(['student', 'subject', 'schoolClass'])
            ->find($id);

        if (!$grade) {
            return response()->json([
                'success' => false,
                'message' => 'Grade not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Grade retrieved successfully',
            'data' => new GradeResource($grade),
        ]);
    }

    public function store(StoreGradeRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated = array_merge($validated, $request->period());

        $grade = DB::transaction(function () use ($validated) {
            $exists = Grade::where('student_id', $validated['student_id'])
                ->where('subject_id', $validated['subject_id'])
                ->where('class_id', $validated['class_id'])
                ->where('type', $validated['type'])
                ->where('semester_id', $validated['semester_id'])
                ->where('academic_year_id', $validated['academic_year_id'])
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'student_id' => ['A grade for this student, subject, class, type, semester, and academic year already exists.'],
                ]);
            }

            return Grade::create($validated);
        });

        $grade->load(['student', 'subject', 'schoolClass']);

        return response()->json([
            'success' => true,
            'message' => 'Grade created successfully',
            'data' => new GradeResource($grade),
        ], 201);
    }

    public function update(UpdateGradeRequest $request, int $id): JsonResponse
    {
        $grade = Grade::find($id);

        if (!$grade) {
            return response()->json([
                'success' => false,
                'message' => 'Grade not found',
                'data' => null,
            ], 404);
        }

        $validated = $request->validated();

        if ($request->period() !== null) {
            $validated = array_merge($validated, $request->period());
        }

        DB::transaction(function () use ($grade, $validated) {
            $studentId = $validated['student_id'] ?? $grade->student_id;
            $subjectId = $validated['subject_id'] ?? $grade->subject_id;
            $classId = $validated['class_id'] ?? $grade->class_id;
            $type = $validated['type'] ?? $grade->type;
            $semesterId = $validated['semester_id'] ?? $grade->semester_id;
            $academicYearId = $validated['academic_year_id'] ?? $grade->academic_year_id;

            $exists = Grade::where('student_id', $studentId)
                ->where('subject_id', $subjectId)
                ->where('class_id', $classId)
                ->where('type', $type)
                ->where('semester_id', $semesterId)
                ->where('academic_year_id', $academicYearId)
                ->where('id', '!=', $grade->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'student_id' => ['A grade for this student, subject, class, type, semester, and academic year already exists.'],
                ]);
            }

            $grade->update($validated);
        });

        $grade->load(['student', 'subject', 'schoolClass']);

        return response()->json([
            'success' => true,
            'message' => 'Grade updated successfully',
            'data' => new GradeResource($grade),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $grade = Grade::find($id);

        if (!$grade) {
            return response()->json([
                'success' => false,
                'message' => 'Grade not found',
                'data' => null,
            ], 404);
        }

        $grade->delete();

        return response()->json([
            'success' => true,
            'message' => 'Grade deleted successfully',
            'data' => null,
        ]);
    }

    private function appendPeriodFilters($query, array $validated): void
    {
        if (!empty($validated['academic_year_id']) && !empty($validated['academic_year'])) {
            $name = AcademicYear::where('id', $validated['academic_year_id'])->value('name');

            if ($name !== null && $name !== $validated['academic_year']) {
                throw ValidationException::withMessages([
                    'academic_year' => ['The academic_year value conflicts with academic_year_id.'],
                ]);
            }
        }

        if (!empty($validated['semester_id']) && !empty($validated['semester'])) {
            $name = Semester::where('id', $validated['semester_id'])->value('name');

            if ($name !== null && $name !== $validated['semester']) {
                throw ValidationException::withMessages([
                    'semester' => ['The semester value conflicts with semester_id.'],
                ]);
            }
        }

        if (!empty($validated['academic_year_id'])) {
            $query->where('academic_year_id', $validated['academic_year_id']);
        } elseif (!empty($validated['academic_year'])) {
            $query->where('academic_year', $validated['academic_year']);
        }

        if (!empty($validated['semester_id'])) {
            $query->where('semester_id', $validated['semester_id']);
        } elseif (!empty($validated['semester'])) {
            $query->where('semester', $validated['semester']);
        }
    }
}