<?php

namespace App\Http\Controllers\Api\Development;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Development\StoreCounselingRequest;
use App\Http\Requests\Api\Development\UpdateCounselingRequest;
use App\Http\Resources\Development\CounselingResource;
use App\Models\Development\Counseling;
use App\Models\Staff\TeacherAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CounselingController extends Controller
{
    private function classIds(Request $request)
    {
        $teacher = $request->user()?->teacherProfile;
        return $teacher
            ? TeacherAssignment::where('teacher_id', $teacher->id)->pluck('class_id')->unique()->filter()
            : collect();
    }

    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $query = Counseling::query()->with(['student', 'counselor']);

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }

        if ($request->filled('counselor_id')) {
            $query->where('counselor_id', $request->input('counselor_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where('topic', 'LIKE', "%{$q}%");
        }

        $counselings = $query->orderBy('counseling_date', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Counselings retrieved successfully',
            'data' => CounselingResource::collection($counselings),
            'meta' => [
                'current_page' => $counselings->currentPage(),
                'per_page' => $counselings->perPage(),
                'total' => $counselings->total(),
                'last_page' => $counselings->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $counseling = Counseling::with(['student', 'counselor'])->find($id);

        if (!$counseling) {
            return response()->json([
                'success' => false,
                'message' => 'Counseling not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Counseling retrieved successfully',
            'data' => new CounselingResource($counseling),
        ]);
    }

    public function store(StoreCounselingRequest $request): JsonResponse
    {
        $counseling = Counseling::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Counseling created successfully',
            'data' => new CounselingResource($counseling->load(['student', 'counselor'])),
        ], 201);
    }

    public function update(UpdateCounselingRequest $request, int $id): JsonResponse
    {
        $counseling = Counseling::find($id);

        if (!$counseling) {
            return response()->json([
                'success' => false,
                'message' => 'Counseling not found',
                'data' => null,
            ], 404);
        }

        $counseling->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Counseling updated successfully',
            'data' => new CounselingResource($counseling->load(['student', 'counselor'])),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $counseling = Counseling::find($id);

        if (!$counseling) {
            return response()->json([
                'success' => false,
                'message' => 'Counseling not found',
                'data' => null,
            ], 404);
        }

        $counseling->delete();

        return response()->json([
            'success' => true,
            'message' => 'Counseling deleted successfully',
            'data' => null,
        ]);
    }

    public function myCounselings(Request $request): JsonResponse
    {
        $classIds = $this->classIds($request);

        if ($classIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
                'data' => null,
            ], 403);
        }

        $query = Counseling::query()
            ->with(['student', 'counselor'])
            ->whereHas('student', fn($q) => $q->whereIn('class_id', $classIds));

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }

        if ($request->filled('counselor_id')) {
            $query->where('counselor_id', $request->input('counselor_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where('topic', 'LIKE', "%{$q}%");
        }

        $counselings = $query->orderBy('counseling_date', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Counselings retrieved successfully',
            'data' => CounselingResource::collection($counselings),
            'meta' => [
                'current_page' => $counselings->currentPage(),
                'per_page' => $counselings->perPage(),
                'total' => $counselings->total(),
                'last_page' => $counselings->lastPage(),
            ],
        ]);
    }
}
