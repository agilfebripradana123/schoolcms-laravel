<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Academic\AggregateGradeScoreRequest;
use App\Http\Requests\Api\Academic\StoreGradeAssessmentRequest;
use App\Http\Requests\Api\Academic\UpdateGradeAssessmentRequest;
use App\Http\Resources\Academic\GradeAssessmentResource;
use App\Models\Academic\Grade;
use App\Models\Academic\GradeAssessment;
use App\Services\Academic\GradeAggregationService;
use App\Services\Academic\GradeAssessmentService;
use App\Services\Academic\GradeMutationGuard;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradeAssessmentController extends Controller
{
    private $service;

    private GradeAggregationService $aggregation;

    public function __construct(GradeAssessmentService $service, GradeAggregationService $aggregation)
    {
        $this->service = $service;
        $this->aggregation = $aggregation;
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

        app(GradeMutationGuard::class)->assertMutable($this->resolveMutationGrade(
            (int) $validated['student_id'],
            (int) $validated['subject_id'],
            (int) $validated['class_id'],
            (int) $validated['academic_year_id'],
            (int) $validated['semester_id'],
            $validated['assessment_category'],
        ));

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

        if (! $assessment) {
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

        if (! $assessment) {
            return response()->json([
                'success' => false,
                'message' => 'Asesmen tidak ditemukan',
                'data' => null,
            ], 404);
        }

        $validated = $request->validated();

        app(GradeMutationGuard::class)->assertMutable($this->resolveMutationGrade(
            $assessment->student_id,
            $assessment->subject_id,
            $assessment->class_id,
            $assessment->academic_year_id,
            $assessment->semester_id,
            $assessment->assessment_category,
        ));

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

        if (! $assessment) {
            return response()->json([
                'success' => false,
                'message' => 'Asesmen tidak ditemukan',
                'data' => null,
            ], 404);
        }

        app(GradeMutationGuard::class)->assertMutable($this->resolveMutationGrade(
            $assessment->student_id,
            $assessment->subject_id,
            $assessment->class_id,
            $assessment->academic_year_id,
            $assessment->semester_id,
            $assessment->assessment_category,
        ));

        $assessment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Assessment deleted successfully',
            'data' => null,
        ]);
    }

    /**
     * POST /api/grade-assessments/aggregate
     * Explicitly synchronize the derived bucket scores into the matching Grade
     * rows for one canonical identity (Phase 2L-3 Stage 3D).
     *
     * Identity is the 5 canonical ids only; the aggregation formula is never
     * accepted from the client. Locked targets surface the existing 422
     * GradeMutationGuard envelope untouched.
     */
    public function aggregate(AggregateGradeScoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $studentId = (int) $validated['student_id'];
        $subjectId = (int) $validated['subject_id'];
        $classId = (int) $validated['class_id'];
        $academicYearId = (int) $validated['academic_year_id'];
        $semesterId = (int) $validated['semester_id'];

        $derived = $this->aggregation->synchronizeScore(
            $studentId,
            $subjectId,
            $classId,
            $academicYearId,
            $semesterId,
        );

        $present = array_keys($derived);
        $updated = 0;

        if ($present !== []) {
            $updated = Grade::where('student_id', $studentId)
                ->where('subject_id', $subjectId)
                ->where('class_id', $classId)
                ->where('academic_year_id', $academicYearId)
                ->where('semester_id', $semesterId)
                ->whereIn('type', $present)
                ->count();
        }

        $skipped = count($present) - $updated;

        return response()->json([
            'success' => true,
            'message' => 'Grade scores aggregated successfully',
            'data' => [
                'derived' => $derived,
                'grades_updated' => $updated,
                'grades_skipped' => $skipped,
            ],
        ]);
    }

    /**
     * Resolve the academic Grade guarding an assessment's canonical slot.
     *
     * Returns the existing bucket Grade row when present, otherwise a minimal
     * detached Grade shell carrying the slot identity so a published ReportCard
     * (a subject-widthless lock on student/class/year/semester) still rejects
     * the mutation. Unsupported categories fail safe with a 422.
     */
    private function resolveMutationGrade(
        int $studentId,
        int $subjectId,
        int $classId,
        int $academicYearId,
        int $semesterId,
        string $assessmentCategory,
    ): Grade {
        $bucket = $this->aggregation->bucketForCategory($assessmentCategory);

        if ($bucket === null) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Assessment cannot be mutated for this category.',
                'errors' => ['assessment_category' => ['Unsupported assessment category.']],
                'data' => null,
            ], 422));
        }

        $grade = Grade::where('student_id', $studentId)
            ->where('subject_id', $subjectId)
            ->where('class_id', $classId)
            ->where('type', $bucket)
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->first();

        if ($grade !== null) {
            return $grade;
        }

        $shell = new Grade;
        $shell->student_id = $studentId;
        $shell->class_id = $classId;
        $shell->academic_year_id = $academicYearId;
        $shell->semester_id = $semesterId;

        return $shell;
    }
}
