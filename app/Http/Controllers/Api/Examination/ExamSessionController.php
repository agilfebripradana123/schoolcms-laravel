<?php

namespace App\Http\Controllers\Api\Examination;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Examination\StoreExamSessionRequest;
use App\Http\Requests\Api\Examination\UpdateExamSessionRequest;
use App\Http\Resources\Examination\ExamSessionResource;
use App\Models\Examination\ExamSchedule;
use App\Models\Examination\ExamSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ExamSessionController extends Controller
{
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $sessions = ExamSession::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where('name', 'LIKE', '%' . $request->input('search') . '%');
            })
            ->orderBy('id', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Exam sessions retrieved successfully',
            'data' => ExamSessionResource::collection($sessions),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
                'last_page' => $sessions->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $session = ExamSession::find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Exam session not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Exam session retrieved successfully',
            'data' => new ExamSessionResource($session),
        ]);
    }

    public function store(StoreExamSessionRequest $request): JsonResponse
    {
        $session = ExamSession::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Exam session created successfully',
            'data' => new ExamSessionResource($session),
        ], 201);
    }

    public function update(UpdateExamSessionRequest $request, int $id): JsonResponse
    {
        $session = ExamSession::find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Exam session not found',
                'data' => null,
            ], 404);
        }

        $session->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Exam session updated successfully',
            'data' => new ExamSessionResource($session),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        // B20-F4: a session referenced by an exam schedule must never be deleted —
        // the FK cascade would silently remove the schedule (execution window)
        // even for ongoing/completed exams.
        return DB::transaction(function () use ($id) {
            $session = ExamSession::where('id', $id)->lockForUpdate()->first();

            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam session not found',
                    'data' => null,
                ], 404);
            }

            if (ExamSchedule::where('session_id', $id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam session is referenced by an exam schedule and cannot be deleted.',
                    'data' => null,
                ], 422);
            }

            $session->delete();

            return response()->json([
                'success' => true,
                'message' => 'Exam session deleted successfully',
                'data' => null,
            ]);
        });
    }
}
