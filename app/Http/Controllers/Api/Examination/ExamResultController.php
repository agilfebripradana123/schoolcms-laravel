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
use Illuminate\Http\Exceptions\HttpResponseException;
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
        $auditContext = [
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        return DB::transaction(function () use ($request, $id, $auditContext) {
            // B20-F2-F7: the recompute decision and every related guard run under
            // a row lock so they cannot interleave with a concurrent finalize or
            // delete (both of which acquire the same lock).
            $result = ExamResult::where('id', $id)->lockForUpdate()->first();

            if (! $result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam result not found',
                    'data' => null,
                ], 404);
            }

            // A finalized result is immutable; reject before any scoring runs and
            // never write a recompute audit for a rejected request.
            if ($result->is_final) {
                return response()->json([
                    'success' => false,
                    'message' => 'Result is finalized and cannot be modified.',
                    'data' => null,
                ], 422);
            }

            $attempt = $result->attempt;
            if (! $attempt) {
                return response()->json([
                    'success' => false,
                    'message' => 'Legacy results without an attempt are not mutable.',
                    'data' => null,
                ], 422);
            }

            $scoring = app(ExamScoringService::class);
            $integration = app(ExamGradeIntegrationService::class);

            // B20-F2-F1: remember whether this result already feeds an academic
            // grade before the recompute runs, so it can be propagated after.
            $wasSynced = $integration->isSynced($result);

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

            // B20-F2-F1: a synced result that stays effective is re-synchronized
            // so the academic value never goes stale; a locked Grade slot is never
            // overwritten and the recompute is left in place.
            if ($wasSynced && $scoring->isEffectiveResult($result)) {
                $state = $this->resyncGradeIfMutable($integration, $result, $auditContext);

                if ($state === 'locked') {
                    \App\Models\System\AuditLog::create([
                        'user_id' => $request->user()?->id,
                        'action' => 'exam_grade_resync_locked',
                        'model' => ExamResult::class,
                        'model_id' => $result->id,
                        'description' => json_encode([
                            'result_id' => $result->id,
                            'reason' => 'Academic grade slot is locked; resync skipped.',
                        ]),
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Exam result updated successfully',
                'data' => new ExamResultResource($result),
            ]);
        });
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        // B20-F2-F3/F4: deletion now runs in a transaction against a locked row,
        // so it can never delete a result that a concurrent finalize is locking.
        return DB::transaction(function () use ($request, $id) {
            $result = ExamResult::where('id', $id)->lockForUpdate()->first();

            if (! $result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam result not found',
                    'data' => null,
                ], 404);
            }

            // A finalized result is immutable and must never be deleted through the
            // normal mutation path (no grade/assessment change, no deletion audit).
            if ($result->is_final) {
                return response()->json([
                    'success' => false,
                    'message' => 'Result is finalized and cannot be deleted.',
                    'data' => null,
                ], 422);
            }

            // B20-F2-F3: a synced result is the live source of an academic grade;
            // deleting it would leave a dangling provenance, so it is rejected.
            if (app(ExamGradeIntegrationService::class)->isSynced($result)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Result is synchronized to an academic grade and cannot be deleted.',
                    'data' => null,
                ], 422);
            }

            // Provenance captured before deletion; the row object still carries
            // them after delete(), so building the audit here is safe.
            $participant = $result->participant;
            $attempt = $result->attempt;

            $result->delete();

            // B20-F5: bounded domain audit for a successful result deletion so
            // provenance forensics never depend on the generic delete listener.
            \App\Models\System\AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'exam_result_deleted',
                'model' => ExamResult::class,
                'model_id' => $result->id,
                'description' => json_encode(array_filter([
                    'result_id' => $result->id,
                    'attempt_id' => $result->exam_attempt_id,
                    'student_id' => $participant?->student_id,
                    'exam_id' => $attempt?->exam_id,
                ])),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Exam result deleted successfully',
                'data' => null,
            ]);
        });
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

    /**
     * POST /api/exam-results/{exam_result}/finalize   (admin, manage-exams)
     * Lock a result as final. Idempotent: re-finalizing never rewrites the
     * original finalized_at / finalized_by. A finalized result becomes immutable
     * through the normal mutation paths (recompute, delete, re-score).
     */
    public function finalize(Request $request, int $id): JsonResponse
    {
        $auditContext = [
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        return DB::transaction(function () use ($request, $id, $auditContext) {
            $result = ExamResult::where('id', $id)->lockForUpdate()->first();

            if (! $result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam result not found',
                    'data' => null,
                ], 404);
            }

            // B20-F5: a result whose parent exam is soft-deleted (archived into
            // non-operational history) must not be newly finalized. Existing
            // finalized history stays untouched; the rule only blocks NEW locks.
            $attempt = $result->attempt;
            if ($attempt !== null && $attempt->exam === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Result belongs to a non-operational exam and cannot be finalized.',
                    'data' => null,
                ], 422);
            }

            $scoring = app(ExamScoringService::class);
            $integration = app(ExamGradeIntegrationService::class);

            // B20-F2-F5: capture the effective result BEFORE the lock flips, so a
            // finalization that changes what the academic grade must reflect can
            // be propagated explicitly afterwards.
            $effectiveBefore = $scoring->effectiveResult((int) $result->participant_id);

            $alreadyFinal = (bool) $result->is_final;

            if (! $alreadyFinal) {
                $result->is_final = true;
                $result->finalized_at = now();
                $result->save();
            }

            // Finalized-first semantics mean this result is now the effective one
            // for its participant. Only a finalization that actually CHANGED the
            // effective result needs grade re-synchronization (and only when the
            // Grade slot stays mutable — locked slots are never overwritten).
            $effectiveChanged = $effectiveBefore === null || (int) $effectiveBefore->id !== (int) $result->id;
            $gradeResync = 'none';
            if ($effectiveChanged && ! $alreadyFinal) {
                $gradeResync = $this->resyncGradeIfMutable($integration, $result, $auditContext);
            }

            \App\Models\System\AuditLog::create([
                'user_id' => $auditContext['user_id'],
                'action' => 'exam_result_finalized',
                'model' => ExamResult::class,
                'model_id' => $result->id,
                'description' => json_encode([
                    'result_id' => $result->id,
                    'exam_attempt_id' => $result->exam_attempt_id,
                    'participant_id' => $result->participant_id,
                    'is_final' => true,
                    'effective_changed' => $effectiveChanged,
                    'grade_resync' => $gradeResync,
                    // Actor identity rides on the audit user_id; the schema has
                    // no finalized_by column to stamp, so it is quoted here too
                    // for bounded traceability.
                    'finalized_by' => $auditContext['user_id'],
                    'was_already_final' => $alreadyFinal,
                ]),
                'ip_address' => $auditContext['ip_address'],
                'user_agent' => $auditContext['user_agent'],
            ]);

            return response()->json([
                'success' => true,
                'message' => $alreadyFinal ? 'Exam result is already finalized.' : 'Exam result finalized successfully.',
                'data' => new ExamResultResource($result->load(['participant', 'attempt'])),
            ]);
        });
    }

    /**
     * B20-F2-F1/F5 shared resynchronization hook.
     *
     * Reuses ExamGradeIntegrationService::sync() verbatim — no grade-sync logic is
     * duplicated here. Outcomes: 'done' (resynced + exam_grade_resynced audit),
     * 'locked' (mutable guard refused, muted), 'ineligible' or 'absent' (no sync).
     */
    private function resyncGradeIfMutable(ExamGradeIntegrationService $integration, ExamResult $result, array $auditContext): string
    {
        try {
            $outcome = $integration->sync($result, $auditContext);
        } catch (HttpResponseException $e) {
            return 'locked';
        }

        if (! ($outcome['ok'] ?? false)) {
            return 'ineligible';
        }

        \App\Models\System\AuditLog::create([
            'user_id' => $auditContext['user_id'] ?? null,
            'action' => 'exam_grade_resynced',
            'model' => ExamResult::class,
            'model_id' => $result->id,
            'description' => json_encode([
                'result_id' => $result->id,
                'exam_attempt_id' => $result->exam_attempt_id,
                'grade_id' => $outcome['grade']->id ?? null,
                'reason' => 'effective result resynchronization',
            ]),
            'ip_address' => $auditContext['ip_address'] ?? null,
            'user_agent' => $auditContext['user_agent'] ?? null,
        ]);

        return 'done';
    }
}
