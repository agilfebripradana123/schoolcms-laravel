<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Students\StoreStudentHistoryRequest;
use App\Http\Requests\Api\Students\UpdateStudentHistoryRequest;
use App\Http\Resources\Students\StudentHistoryResource;
use App\Models\Students\StudentHistory;
use App\Services\Students\StudentHistoryFinalizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StudentHistory::query()->with(['student', 'schoolClass', 'academicYear']);

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->input('class_id'));
        }

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', $request->input('academic_year_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $histories = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Student histories retrieved successfully',
            'data' => StudentHistoryResource::collection($histories),
            'meta' => [
                'current_page' => $histories->currentPage(),
                'per_page' => $histories->perPage(),
                'total' => $histories->total(),
                'last_page' => $histories->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $history = StudentHistory::with(['student', 'schoolClass', 'academicYear'])->find($id);

        if (! $history) {
            return response()->json([
                'success' => false,
                'message' => 'Student history not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Student history retrieved successfully',
            'data' => new StudentHistoryResource($history),
        ]);
    }

    public function store(StoreStudentHistoryRequest $request): JsonResponse
    {
        $history = StudentHistory::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Student history created successfully',
            'data' => new StudentHistoryResource($history->load(['student', 'schoolClass', 'academicYear'])),
        ], 201);
    }

    public function update(UpdateStudentHistoryRequest $request, int $id): JsonResponse
    {
        $history = StudentHistory::find($id);

        if (! $history) {
            return response()->json([
                'success' => false,
                'message' => 'Student history not found',
                'data' => null,
            ], 404);
        }

        if ($history->is_final) {
            return $this->finalized('A finalized student history cannot be modified.');
        }

        $history->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Student history updated successfully',
            'data' => new StudentHistoryResource($history->load(['student', 'schoolClass', 'academicYear'])),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $history = StudentHistory::find($id);

        if (! $history) {
            return response()->json([
                'success' => false,
                'message' => 'Student history not found',
                'data' => null,
            ], 404);
        }

        if ($history->is_final) {
            return $this->finalized('A finalized student history cannot be deleted.');
        }

        $history->delete();

        return response()->json([
            'success' => true,
            'message' => 'Student history deleted successfully',
            'data' => null,
        ]);
    }

    public function finalize(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['notes' => 'nullable|string|max:255']);

        $history = StudentHistory::find($id);

        if (! $history) {
            return response()->json([
                'success' => false,
                'message' => 'Student history not found',
                'data' => null,
            ], 404);
        }

        $history = app(StudentHistoryFinalizationService::class)->finalize(
            $history,
            $request->user(),
            $validated['notes'] ?? null,
            $request->ip(),
            $request->userAgent(),
        );
        $history->load(['student', 'schoolClass', 'academicYear']);

        return response()->json([
            'success' => true,
            'message' => 'Student history finalized successfully',
            'data' => new StudentHistoryResource($history),
        ]);
    }

    private function finalized(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => ['student_history' => ['A finalized student history is locked.']],
            'data' => null,
        ], 422);
    }
}
