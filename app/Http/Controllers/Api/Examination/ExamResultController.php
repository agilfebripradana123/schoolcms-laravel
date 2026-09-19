<?php

namespace App\Http\Controllers\Api\Examination;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Examination\StoreExamResultRequest;
use App\Http\Requests\Api\Examination\UpdateExamResultRequest;
use App\Http\Resources\Examination\ExamResultResource;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamResult;
use App\Services\Examination\ExamGradeIntegrationService;
use App\Services\Examination\ExamScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExamResultController extends Controller
{
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $query = ExamResult::query()->with(['participant', 'attempt']);

        if ($request->filled('participant_id')) {
            $query->where('participant_id', $request->input('participant_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $results = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Exam results retrieved successfully',
            'data' => ExamResultResource::collection($results),
            'meta' => [
                'current_page' => $results->currentPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'last_page' => $results->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $result = ExamResult::with(['participant', 'attempt'])->find($id);

        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => 'Exam result not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Exam result retrieved successfully',
            'data' => new ExamResultResource($result),
        ]);
    }

    public function store(StoreExamResultRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $attemptId = (int) $request->validated()['exam_attempt_id'];

            if (ExamResult::where('exam_attempt_id', $attemptId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This attempt already has a result.',
                    'data' => null,
                ], 422);
            }

            $attempt = ExamAttempt::find($attemptId);
            if (! $attempt) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attempt not found.',
                    'data' => null,
                ], 404);
            }

            // Only attempts that can legitimately produce a result (submitted or
            // expired) are eligible at the admin result-creation boundary.
            if (! in_array($attempt->status, ['submitted', 'expired'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attempt must be submitted or expired before a result can be created.',
                    'data' => null,
                ], 422);
            }

            // Results are generated exclusively by the scoring service; identity is
            // bound to the attempt. Client-provided score fields are never honored.
            app(ExamScoringService::class)->scoreAttempt($attempt);
            $result = ExamResult::with(['participant', 'attempt'])->where('exam_attempt_id', $attemptId)->firstOrFail();

            \App\Models\System\AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'exam_result_generated',
                'model' => ExamResult::class,
                'model_id' => $result->id,
                'description' => json_encode([
                    'result_id' => $result->id,
                    'exam_attempt_id' => $result->exam_attempt_id,
                    'participant_id' => $result->participant_id,
                    'total_score' => $result->total_score,
                    'percentage' => $result->percentage,
                    'grade' => $result->grade,
                    'status' => $result->status,
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Exam result created successfully',
                'data' => new ExamResultResource($result),
            ], 201);
        });
    }

    public function update(UpdateExamResultRequest $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $result = ExamResult::with('attempt')->find($id);

            if (! $result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam result not found',
                    'data' => null,
                ], 404);
            }

            $attempt = $result->attempt;
            if (! $attempt) {
                return response()->json([
                    'success' => false,
                    'message' => 'Legacy results without an attempt are not mutable.',
                    'data' => null,
                ], 422);
            }

            $before = [
                'total_score' => $result->total_score,
                'percentage' => $result->percentage,
                'grade' => $result->grade,
                'status' => $result->status,
            ];

            // Recompute through the authoritative scoring service only.
            app(ExamScoringService::class)->scoreAttempt($attempt);
            $result->refresh()->load(['participant', 'attempt']);

            \App\Models\System\AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'exam_result_recomputed',
                'model' => ExamResult::class,
                'model_id' => $result->id,
                'description' => json_encode([
                    'result_id' => $result->id,
                    'exam_attempt_id' => $result->exam_attempt_id,
                    'score_before' => $before['total_score'],
                    'score_after' => $result->total_score,
                    'percentage_before' => $before['percentage'],
                    'percentage_after' => $result->percentage,
                    'status_before' => $before['status'],
                    'status_after' => $result->status,
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Exam result updated successfully',
                'data' => new ExamResultResource($result),
            ]);
        });
    }

    public function destroy(int $id): JsonResponse
    {
        $result = ExamResult::find($id);

        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => 'Exam result not found',
                'data' => null,
            ], 404);
        }

        $result->delete();

        return response()->json([
            'success' => true,
            'message' => 'Exam result deleted successfully',
            'data' => null,
        ]);
    }

    /**
     * POST /api/exam-results/{exam_result}/grade-sync   (admin, manage-exams)
     * Synchronize an eligible examination result into the Academic Grade.
     * Academic identity is 100% server-derived; the request accepts no body.
     */
    public function syncToGrade(Request $request, int $id): JsonResponse
    {
        $result = ExamResult::with(['participant', 'participant.student'])->find($id);

        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => 'Exam result not found',
                'data' => null,
            ], 404);
        }

        if (! app(ExamScoringService::class)->isEffectiveResult($result)) {
            return response()->json([
                'success' => false,
                'message' => 'Exam result is not eligible for synchronization.',
                'data' => null,
            ], 422);
        }

        $outcome = app(ExamGradeIntegrationService::class)->sync($result, [
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        if (! $outcome['ok']) {
            return response()->json([
                'success' => false,
                'message' => $outcome['message'],
                'data' => null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $outcome['message'],
            'data' => [
                'grade_id' => $outcome['grade']->id,
                'grade' => $outcome['grade'],
            ],
        ]);
    }
}
