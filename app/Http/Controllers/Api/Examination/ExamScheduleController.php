<?php

namespace App\Http\Controllers\Api\Examination;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Examination\StoreExamScheduleRequest;
use App\Http\Requests\Api\Examination\UpdateExamScheduleRequest;
use App\Http\Resources\Examination\ExamScheduleResource;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamSchedule;
use Illuminate\Http\JsonResponse;

class ExamScheduleController extends Controller
{
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $query = ExamSchedule::query()->with(['exam', 'room', 'session']);

        if ($request->filled('exam_id')) {
            $query->where('exam_id', $request->input('exam_id'));
        }

        if ($request->filled('room_id')) {
            $query->where('room_id', $request->input('room_id'));
        }

        if ($request->filled('session_id')) {
            $query->where('session_id', $request->input('session_id'));
        }

        if ($request->filled('exam_date')) {
            $query->whereDate('exam_date', $request->input('exam_date'));
        }

        $schedules = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Exam schedules retrieved successfully',
            'data' => ExamScheduleResource::collection($schedules),
            'meta' => [
                'current_page' => $schedules->currentPage(),
                'per_page' => $schedules->perPage(),
                'total' => $schedules->total(),
                'last_page' => $schedules->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $schedule = ExamSchedule::with(['exam', 'room', 'session'])->find($id);

        if (!$schedule) {
            return response()->json([
                'success' => false,
                'message' => 'Exam schedule not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Exam schedule retrieved successfully',
            'data' => new ExamScheduleResource($schedule),
        ]);
    }

    public function store(StoreExamScheduleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $exam = Exam::find($validated['exam_id']);
        if (!$exam) {
            return $this->notFound('Exam not found.');
        }

        if (! $this->isSchedulable($exam)) {
            return $this->unprocessable(sprintf("Exam with status '%s' cannot be scheduled.", $exam->status));
        }

        $check = $this->validateWindow($validated);
        if (! $check['ok']) {
            return $this->unprocessable($check['message']);
        }

        $schedule = ExamSchedule::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Exam schedule created successfully',
            'data' => new ExamScheduleResource($schedule->load(['exam', 'room', 'session'])),
        ], 201);
    }

    public function update(UpdateExamScheduleRequest $request, int $id): JsonResponse
    {
        $schedule = ExamSchedule::find($id);

        if (!$schedule) {
            return $this->notFound('Exam schedule not found.');
        }

        if (! $this->isSchedulable($schedule->exam)) {
            return $this->unprocessable(sprintf("Exam with status '%s' cannot be rescheduled.", $schedule->exam->status));
        }

        $check = $this->validateWindow($request->validated());
        if (! $check['ok']) {
            return $this->unprocessable($check['message']);
        }

        $schedule->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Exam schedule updated successfully',
            'data' => new ExamScheduleResource($schedule->load(['exam', 'room', 'session'])),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $schedule = ExamSchedule::find($id);

        if (!$schedule) {
            return $this->notFound('Exam schedule not found.');
        }

        if (! $this->isSchedulable($schedule->exam)) {
            return $this->unprocessable(sprintf("Exam with status '%s' cannot have its schedule removed.", $schedule->exam->status));
        }

        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Exam schedule deleted successfully',
            'data' => null,
        ]);
    }

    /**
     * A schedule may only be created/updated/removed while the exam is still
     * draft or published. Once an exam is running (ongoing) or finished
     * (completed/archived) its execution window is historical.
     */
    private function isSchedulable(Exam $exam): bool
    {
        return in_array($exam->status, ['draft', 'published'], true);
    }

    /**
     * Explicit window datetimes are authoritative when provided; both must be
     * present together and end must be strictly after start.
     */
    private function validateWindow(array $validated): array
    {
        $start = $validated['start_datetime'] ?? null;
        $end = $validated['end_datetime'] ?? null;

        if ($start === null && $end === null) {
            return ['ok' => true, 'message' => ''];
        }

        if ($start === null || $end === null) {
            return ['ok' => false, 'message' => 'start_datetime and end_datetime must be provided together.'];
        }

        if (strtotime($end) <= strtotime($start)) {
            return ['ok' => false, 'message' => 'end_datetime must be after start_datetime.'];
        }

        return ['ok' => true, 'message' => ''];
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
        ], 404);
    }

    private function unprocessable(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
        ], 422);
    }
}
