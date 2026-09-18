<?php

namespace App\Http\Controllers\Api\Examination;

use App\Http\Controllers\Controller;
use App\Http\Resources\Examination\ExamAttemptOptionResource;
use App\Models\Examination\ExamAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}