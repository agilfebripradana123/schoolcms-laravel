<?php

namespace App\Http\Controllers\Api\Teachers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Examination\ExamAttemptMonitoringResource;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamAttempt;
use App\Models\Staff\TeacherAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TeacherExamMonitoringController extends Controller
{
    private function teacher(Request $request)
    {
        return $request->user()?->teacherProfile;
    }

    private function subjectIds(int $teacherId): array
    {
        return TeacherAssignment::where('teacher_id', $teacherId)
            ->pluck('subject_id')
            ->unique()
            ->all();
    }

    private function examIds(array $subjectIds): array
    {
        return Exam::whereIn('subject_id', $subjectIds)
            ->pluck('id')
            ->all();
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Forbidden',
            'data' => null,
        ], 403);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Attempt not found',
            'data' => null,
        ], 404);
    }

    public function index(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $subjectIds = $this->subjectIds($teacher->id);
        $examIds = $this->examIds($subjectIds);

        $query = ExamAttempt::with([
            'exam.subject',
            'participant.student',
            'answers',
            'events',
        ])->whereIn('exam_id', $examIds);

        if ($request->filled('exam_id')) {
            $query->where('exam_id', $request->input('exam_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('participant.student', function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('nis', 'LIKE', "%{$search}%");
            });
        }

        $attempts = $query->orderBy('id', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Exam attempts retrieved successfully',
            'data' => ExamAttemptMonitoringResource::collection($attempts),
            'meta' => [
                'current_page' => $attempts->currentPage(),
                'per_page' => $attempts->perPage(),
                'total' => $attempts->total(),
                'last_page' => $attempts->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $attemptId): JsonResponse
    {
        $teacher = $this->teacher($request);

        if (!$teacher) {
            return $this->forbidden();
        }

        $subjectIds = $this->subjectIds($teacher->id);
        $examIds = $this->examIds($subjectIds);

        $attempt = ExamAttempt::with([
            'exam.subject',
            'participant.student',
            'answers.question',
            'answers.selectedOption',
            'events',
        ])
            ->whereIn('exam_id', $examIds)
            ->where('id', $attemptId)
            ->first();

        if (!$attempt) {
            return $this->notFound();
        }

        $eventSummary = $attempt->events->groupBy('event_type')->map(fn($group) => $group->count())->all();
        $eventTimeline = $attempt->events->sortBy('occurred_at')->map(function ($event) {
            return [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'occurred_at' => $event->occurred_at?->toISOString(),
                'metadata' => $event->metadata,
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'message' => 'Attempt detail retrieved successfully',
            'data' => [
                'attempt' => new ExamAttemptMonitoringResource($attempt),
                'event_summary' => $eventSummary,
                'event_timeline' => $eventTimeline,
            ],
        ]);
    }
}
