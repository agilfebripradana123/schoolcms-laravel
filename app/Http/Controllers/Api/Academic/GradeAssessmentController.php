<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Academic\StoreGradeAssessmentRequest;
use App\Http\Requests\Api\Academic\UpdateGradeAssessmentRequest;
use App\Http\Resources\Academic\GradeAssessmentResource;
use App\Models\Academic\GradeAssessment;
use App\Services\Academic\GradeAssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradeAssessmentController extends Controller
{
    private $service;

    public function __construct(GradeAssessmentService $service)
    {
        $this->service = $service;
    }

    /**
     * List assessments with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = GradeAssessment::with(['student', 'subject', 'schoolClass', 'academicYear', 'semester']);

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->input('subject_id'));
        }

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->input('class_id'));
        }

        if ($request->filled('assessment_category')) {
            $query->where('assessment_category', $request->input('assessment_category'));
        }

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', $request->input('academic_year_id'));
        }

        if ($request->filled('semester_id')) {
            $query->where('semester_id', $request->input('semester_id'));
        }

        $perPage = $request->input('per_page', 20);
        $assessments = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Assessments retrieved successfully',
            'data' => GradeAssessmentResource::collection($assessments),
            'meta' => [
                'current_page' => $assessments->currentPage(),
                'per_page' => $assessments->perPage(),
                'total' => $assessments->total(),
                'last_page' => $assessments->lastPage(),
            ],
        ]);
    }

    /**
     * Store a new assessment.
     */
    public function store(StoreGradeAssessmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $assessment = $this->service->create($validated);

        $assessment->load(['student', 'subject', 'schoolClass', 'academicYear', 'semester']);

        return response()->json([
            'success' => true,
            'message' => 'Assessment created successfully',
            'data' => new GradeAssessmentResource($assessment),
        ], 201);
    }

    /**
     * Display the specified assessment.
     */
    public function show(int $id): JsonResponse
    {
        $assessment = GradeAssessment::with(['student', 'subject', 'schoolClass', 'academicYear', 'semester'])
            ->find($id);

        if (!$assessment) {
            return response()->json([
                'success' => false,
                'message' => 'Asesmen tidak ditemukan',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Asesmen retrieved successfully',
            'data' => new GradeAssessmentResource($assessment),
        ]);
    }

    /**
     * Update an assessment.
     */
    public function update(UpdateGradeAssessmentRequest $request, int $id): JsonResponse
    {
        $assessment = GradeAssessment::find($id);

        if (!$assessment) {
            return response()->json([
                'success' => false,
                'message' => 'Asesmen tidak ditemukan',
                'data' => null,
            ], 404);
        }

        $validated = $request->validated();

        $updated = $this->service->update($assessment, $validated);

        $updated->load(['student', 'subject', 'schoolClass', 'academicYear', 'semester']);

        return response()->json([
            'success' => true,
            'message' => 'Assessment updated successfully',
            'data' => new GradeAssessmentResource($updated),
        ]);
    }

    /**
     * Remove the specified assessment.
     */
    public function destroy(int $id): JsonResponse
    {
        $assessment = GradeAssessment::find($id);

        if (!$assessment) {
            return response()->json([
                'success' => false,
                'message' => 'Asesmen tidak ditemukan',
                'data' => null,
            ], 404);
        }

        $canDelete = $this->service->delete($assessment);

        if (!$canDelete) {
            return response()->json([
                'success' => false,
                'message' => 'Asesmen tidak dapat dihapus karena sudah finalisasi.',
                'data' => null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Assessment deleted successfully',
            'data' => null,
        ]);
    }
}