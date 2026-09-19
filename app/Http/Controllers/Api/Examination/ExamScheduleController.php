<?php

namespace App\Http\Controllers\Api\Examination;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Examination\StoreExamScheduleRequest;
use App\Http\Requests\Api\Examination\UpdateExamScheduleRequest;
use App\Http\Resources\Examination\ExamScheduleResource;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamSchedule;
use App\Models\System\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $schedule = DB::transaction(function () use ($request, $validated) {
            $schedule = ExamSchedule::create($validated);

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'exam_schedule_created',
                'model' => ExamSchedule::class,
                'model_id' => $schedule->id,
                'description' => json_encode($this->scheduleContext($schedule)),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $schedule;
        });

        return response()->json([
            'success' => true,
            'message' => 'Exam schedule created successfully',
            'data' => new ExamScheduleResource($schedule->load(['exam', 'room', 'session'])),
        ], 201);
    }

    public function update(UpdateExamScheduleRequest $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $schedule = ExamSchedule::where('id', $id)->lockForUpdate()->first();

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

            $tracked = ['exam_date', 'room_id', 'session_id', 'start_datetime', 'end_datetime'];
            $before = [];
            foreach ($tracked as $field) {
                $before[$field] = $this->fieldValue($schedule, $field);
            }

            $schedule->update($request->validated());

            $changed = [];
            foreach ($tracked as $field) {
                if ($before[$field] != $this->fieldValue($schedule, $field)) {
                    $changed[] = $field;
                }
            }

            if (! empty($changed)) {
                AuditLog::create([
                    'user_id' => $request->user()?->id,
                    'action' => 'exam_schedule_updated',
                    'model' => ExamSchedule::class,
                    'model_id' => $schedule->id,
                    'description' => json_encode(array_merge($this->scheduleContext($schedule), [
                        'changed_fields' => $changed,
                        'before' => $before,
                    ])),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Exam schedule updated successfully',
                'data' => new ExamScheduleResource($schedule->load(['exam', 'room', 'session'])),
            ]);
        });
    }

    private function fieldValue(ExamSchedule $schedule, string $field)
    {
        $value = $schedule->getAttribute($field);

        return $value instanceof \DateTimeInterface ? $value->toISOString() : $value;
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $schedule = ExamSchedule::where('id', $id)->lockForUpdate()->first();

            if (!$schedule) {
                return $this->notFound('Exam schedule not found.');
            }

            if (! $this->isSchedulable($schedule->exam)) {
                return $this->unprocessable(sprintf("Exam with status '%s' cannot have its schedule removed.", $schedule->exam->status));
            }

            $context = $this->scheduleContext($schedule);

            $schedule->delete();

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'exam_schedule_deleted',
                'model' => ExamSchedule::class,
                'model_id' => $id,
                'description' => json_encode($context),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Exam schedule deleted successfully',
                'data' => null,
            ]);
        });
    }

    private function scheduleContext(ExamSchedule $schedule): array
    {
        return array_filter([
            'schedule_id' => $schedule->id,
            'exam_id' => $schedule->exam_id,
            'exam_date' => $schedule->exam_date?->toDateString(),
            'start_datetime' => $schedule->start_datetime?->toISOString(),
            'end_datetime' => $schedule->end_datetime?->toISOString(),
            'session_id' => $schedule->session_id,
            'room_id' => $schedule->room_id,
        ], fn ($v) => $v !== null);
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
