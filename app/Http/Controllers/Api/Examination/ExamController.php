<?php

namespace App\Http\Controllers\Api\Examination;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Examination\StoreExamRequest;
use App\Http\Requests\Api\Examination\UpdateExamRequest;
use App\Http\Resources\Examination\ExamResource;
use App\Models\Examination\Exam;
use App\Services\Examination\ExamLifecycleService;
use Illuminate\Http\JsonResponse;

class ExamController extends Controller
{
    private ExamLifecycleService $lifecycle;

    public function __construct(ExamLifecycleService $lifecycle)
    {
        $this->lifecycle = $lifecycle;
    }

    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $query = Exam::query()->with('subject');

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->input('subject_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        $exams = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Exams retrieved successfully',
            'data' => ExamResource::collection($exams),
            'meta' => [
                'current_page' => $exams->currentPage(),
                'per_page' => $exams->perPage(),
                'total' => $exams->total(),
                'last_page' => $exams->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $exam = Exam::with('subject')->find($id);

        if (!$exam) {
            return response()->json([
                'success' => false,
                'message' => 'Exam not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Exam retrieved successfully',
            'data' => new ExamResource($exam),
        ]);
    }

    public function store(StoreExamRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Phase 2E lifecycle: a new exam always starts as `draft`. Publishing is
        // a validated transition (composition required), so a client-supplied
        // `status` is never honoured on create.
        $validated['status'] = 'draft';

        $exam = Exam::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Exam created successfully',
            'data' => new ExamResource($exam->load('subject')),
        ], 201);
    }

    public function update(UpdateExamRequest $request, int $id): JsonResponse
    {
        $exam = Exam::find($id);

        if (!$exam) {
            return response()->json([
                'success' => false,
                'message' => 'Exam not found',
                'data' => null,
            ], 404);
        }

        $validated = $request->validated();

        if (array_key_exists('status', $validated) && $validated['status'] !== $exam->status) {
            $target = $validated['status'];

            if (! $this->lifecycle->isAllowedTransition($exam->status, $target)) {
                return $this->unprocessable(
                    sprintf("Transition from '%s' to '%s' is not allowed.", $exam->status, $target)
                );
            }

            if ($exam->status === 'draft' && $target === 'published') {
                $check = $this->lifecycle->validatePublishable($exam);
                if (! $check['ok']) {
                    return $this->unprocessable('Exam cannot be published.', $check['errors']);
                }
            }
        }

        $exam->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Exam updated successfully',
            'data' => new ExamResource($exam->load('subject')),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $exam = Exam::find($id);

        if (!$exam) {
            return response()->json([
                'success' => false,
                'message' => 'Exam not found',
                'data' => null,
            ], 404);
        }

        $exam->delete();

        return response()->json([
            'success' => true,
            'message' => 'Exam deleted successfully',
            'data' => null,
        ]);
    }

    private function unprocessable(string $message, array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors ?: null,
            'data' => null,
        ], 422);
    }
}