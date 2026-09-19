<?php

namespace App\Http\Controllers\Api\Examination;

use App\Http\Controllers\Controller;
use App\Http\Resources\Examination\ExamAttemptOptionResource;
use App\Models\Examination\ExamAttempt;
use App\Models\System\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin-scoped read support for selecting eligible exam attempts when
 * generating an ExamResult (Phase B11). Admin-only; strictly read.
 *
 * The endpoint returns only attempts that can legitimately produce a result
 * (status submitted|expired), matching the source submission semantics. It
 * deliberately exposes no answers, tokens, ordering, events, or score fields.
 */
class ExamAttemptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exam_id' => 'nullable|integer',
            'participant_id' => 'nullable|integer',
            'status' => ['nullable', Rule::in(['submitted', 'expired'])],
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:200',
            'page' => 'nullable|integer|min:1',
        ]);

        // Active attempts are never eligible for result creation and the
        // status filter can never broaden the set beyond submitted|expired.
        $statuses = ['submitted', 'expired'];
        if (! empty($validated['status'])) {
            $statuses = [$validated['status']];
        }

        $query = ExamAttempt::with(['exam.subject', 'participant.student'])
            ->withExists('result as has_result')
            ->whereIn('status', $statuses);

        if (! empty($validated['exam_id'])) {
            $query->where('exam_id', $validated['exam_id']);
        }

        if (! empty($validated['participant_id'])) {
            $query->where('exam_participant_id', $validated['participant_id']);
        }

        if (! empty($validated['search'])) {
            $needle = '%'.trim($validated['search']).'%';
            $query->whereHas('participant.student', function ($q) use ($needle) {
                $q->where('name', 'LIKE', $needle)
                    ->orWhere('nis', 'LIKE', $needle);
            });
        }

        $attempts = $query->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'message' => 'Exam attempts retrieved successfully',
            'data' => ExamAttemptOptionResource::collection($attempts),
            'meta' => [
                'current_page' => $attempts->currentPage(),
                'per_page' => $attempts->perPage(),
                'total' => $attempts->total(),
                'last_page' => $attempts->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/exam-attempts/{exam_attempt}/expire   (admin, manage-exams)
     *
     * B20-F6 operational control: manually expire an ACTIVE attempt. Only the
     * attempt status changes; answers, snapshots, and any existing result are
     * never deleted or rewritten, and no result is auto-submitted.
     */
    public function expire(Request $request, int $attemptId): JsonResponse
    {
        return DB::transaction(function () use ($request, $attemptId) {
            $attempt = ExamAttempt::where('id', $attemptId)->lockForUpdate()->first();

            if (! $attempt) {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam attempt not found',
                    'data' => null,
                ], 404);
            }

            if ($attempt->status === ExamAttempt::STATUS_SUBMITTED) {
                return response()->json([
                    'success' => false,
                    'message' => 'A submitted attempt cannot be expired.',
                    'data' => null,
                ], 422);
            }

            if ($attempt->status === ExamAttempt::STATUS_EXPIRED) {
                return response()->json([
                    'success' => true,
                    'message' => 'Attempt is already expired.',
                    'data' => null,
                ]);
            }

            $attempt->status = ExamAttempt::STATUS_EXPIRED;
            $attempt->save();

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'exam_attempt_manually_expired',
                'model' => ExamAttempt::class,
                'model_id' => $attempt->id,
                'description' => json_encode([
                    'attempt_id' => $attempt->id,
                    'exam_participant_id' => $attempt->exam_participant_id,
                    'exam_id' => $attempt->exam_id,
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Exam attempt expired successfully',
                'data' => null,
            ]);
        });
    }

    /**
     * POST /api/exam-attempts/{exam_attempt}/extend   (admin, manage-exams)
     *
     * B20-F6 operational control: extend an ACTIVE attempt by a number of
     * minutes (1..120). Only expires_at changes; started_at, attempt_number,
     * snapshots, answers, result and participant are untouched. An attempt whose
     * expires_at already passed (lazy expiry would have turned it expired) is
     * never revived.
     */
    public function extend(Request $request, int $attemptId): JsonResponse
    {
        $validated = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        return DB::transaction(function () use ($request, $attemptId, $validated) {
            $attempt = ExamAttempt::where('id', $attemptId)->lockForUpdate()->first();

            if (! $attempt) {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam attempt not found',
                    'data' => null,
                ], 404);
            }

            if ($attempt->status === ExamAttempt::STATUS_SUBMITTED) {
                return response()->json([
                    'success' => false,
                    'message' => 'A submitted attempt cannot be extended.',
                    'data' => null,
                ], 422);
            }

            if ($attempt->status === ExamAttempt::STATUS_EXPIRED) {
                return response()->json([
                    'success' => false,
                    'message' => 'An expired attempt cannot be extended.',
                    'data' => null,
                ], 422);
            }

            // Never revive an attempt whose deadline already passed (status is
            // still active only because lazy expiry has not run yet).
            if ($attempt->expires_at !== null && $attempt->expires_at->lt(now())) {
                return response()->json([
                    'success' => false,
                    'message' => 'An expired attempt cannot be extended.',
                    'data' => null,
                ], 422);
            }

            $minutes = (int) $validated['minutes'];
            $previousExpiresAt = $attempt->expires_at?->copy();
            $attempt->expires_at = $attempt->expires_at?->addMinutes($minutes);
            $attempt->save();

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'exam_attempt_extended',
                'model' => ExamAttempt::class,
                'model_id' => $attempt->id,
                'description' => json_encode([
                    'attempt_id' => $attempt->id,
                    'exam_participant_id' => $attempt->exam_participant_id,
                    'exam_id' => $attempt->exam_id,
                    'minutes' => $minutes,
                    'previous_expires_at' => $previousExpiresAt?->toISOString(),
                    'new_expires_at' => $attempt->expires_at->toISOString(),
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Exam attempt extended successfully',
                'data' => null,
            ]);
        });
    }
}