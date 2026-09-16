<?php

namespace App\Http\Controllers\Api\Development;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Development\StoreViolationRequest;
use App\Http\Requests\Api\Development\UpdateViolationRequest;
use App\Http\Resources\Development\ViolationResource;
use App\Models\Development\Violation;
use App\Models\Staff\TeacherAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViolationController extends Controller
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
        $query = Violation::query()->with(['student', 'handledBy']);

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('handled_by')) {
            $query->where('handled_by', $request->input('handled_by'));
        }

        $violations = $query->orderBy('violated_at', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Violations retrieved successfully',
            'data' => ViolationResource::collection($violations),
            'meta' => [
                'current_page' => $violations->currentPage(),
                'per_page' => $violations->perPage(),
                'total' => $violations->total(),
                'last_page' => $violations->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $violation = Violation::with(['student', 'handledBy'])->find($id);

        if (!$violation) {
            return response()->json([
                'success' => false,
                'message' => 'Violation not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Violation retrieved successfully',
            'data' => new ViolationResource($violation),
        ]);
    }

    public function store(StoreViolationRequest $request): JsonResponse
    {
        $violation = Violation::create($request->validated());
        $violation->load(['student', 'handledBy']);

        return response()->json([
            'success' => true,
            'message' => 'Violation created successfully',
            'data' => new ViolationResource($violation),
        ], 201);
    }

    public function update(UpdateViolationRequest $request, int $id): JsonResponse
    {
        $violation = Violation::find($id);

        if (!$violation) {
            return response()->json([
                'success' => false,
                'message' => 'Violation not found',
                'data' => null,
            ], 404);
        }

        $violation->update($request->validated());
        $violation->load(['student', 'handledBy']);

        return response()->json([
            'success' => true,
            'message' => 'Violation updated successfully',
            'data' => new ViolationResource($violation),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $violation = Violation::find($id);

        if (!$violation) {
            return response()->json([
                'success' => false,
                'message' => 'Violation not found',
                'data' => null,
            ], 404);
        }

        $violation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Violation deleted successfully',
            'data' => null,
        ]);
    }

    public function myViolations(Request $request): JsonResponse
    {
        $classIds = $this->classIds($request);

        if ($classIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
                'data' => null,
            ], 403);
        }

        $query = Violation::query()
            ->with(['student', 'handledBy'])
            ->whereHas('student', fn($q) => $q->whereIn('class_id', $classIds));

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('handled_by')) {
            $query->where('handled_by', $request->input('handled_by'));
        }

        $violations = $query->orderBy('violated_at', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Violations retrieved successfully',
            'data' => ViolationResource::collection($violations),
            'meta' => [
                'current_page' => $violations->currentPage(),
                'per_page' => $violations->perPage(),
                'total' => $violations->total(),
                'last_page' => $violations->lastPage(),
            ],
        ]);
    }
}
