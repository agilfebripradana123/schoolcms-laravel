<?php

namespace App\Http\Controllers\Api\Examination;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Examination\StoreExamRequest;
use App\Http\Requests\Api\Examination\UpdateExamRequest;
use App\Http\Resources\Examination\ExamResource;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamParticipant;
use App\Models\Examination\ExamResult;
use App\Models\Examination\ExamSchedule;
use App\Models\System\AuditLog;
use App\Services\Examination\ExamLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        // B20-F4: identity/context fields (subject, class, academic year,
        // semester, exam type) are locked once an exam leaves draft — they
        // decide how existing attempts/results map to academic history and
        // grades, so changing them post-hoc would rewrite that interpretation.
        $identityFields = ['subject_id', 'class_id', 'academic_year_id', 'semester_id', 'exam_type'];

        return DB::transaction(function () use ($request, $id, $identityFields) {
            $exam = Exam::where('id', $id)->lockForUpdate()->first();

            if (!$exam) {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam not found',
                    'data' => null,
                ], 404);
            }

            $validated = $request->validated();

            // Determine whether this request would change an identity field and
            // whether it lands the exam outside draft (current OR target status).
            $identityChanged = false;
            foreach ($identityFields as $field) {
                if (array_key_exists($field, $validated) && $validated[$field] != $exam->getAttribute($field)) {
                    $identityChanged = true;
                    break;
                }
            }

            $targetStatus = $validated['status'] ?? $exam->status;
            if ($identityChanged && $targetStatus !== 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam identity fields cannot be modified when the exam is not in draft status.',
                    'data' => null,
                ], 422);
            }

            // Capture pre-update state for a bounded audit diff.
            $tracked = [
                'title', 'subject_id', 'description', 'duration_minutes', 'total_questions',
                'passing_score', 'max_attempts', 'shuffle_questions', 'shuffle_options',
                'show_result', 'status', 'class_id', 'academic_year_id', 'semester_id', 'exam_type',
            ];
            $before = [];
            foreach ($tracked as $key) {
                $before[$key] = $exam->getAttribute($key);
            }

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

            // Audit meaningful persisted changes only (bounded payload; no answer
            // keys, no option content, no PII).
            $changed = [];
            foreach ($tracked as $key) {
                if ($before[$key] != $exam->getAttribute($key)) {
                    $changed[] = $key;
                }
            }

            if (! empty($changed)) {
                \App\Models\System\AuditLog::create([
                    'user_id' => $request->user()?->id,
                    'action' => 'exam_updated',
                    'model' => Exam::class,
                    'model_id' => $exam->id,
                    'description' => json_encode(array_filter([
                        'status_before' => $before['status'],
                        'status_after' => $exam->status,
                        'changed_fields' => $changed,
                    ])),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Exam updated successfully',
                'data' => new ExamResource($exam->load('subject')),
            ]);
        });
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        // B20-F4: lifecycle/dependency guard. Draft exams may only be soft
        // deleted when nothing operational depends on them; published/ongoing/
        // completed exams are never deletable; archived exams keep the existing
        // soft-delete semantics (never hard-delete).
        return DB::transaction(function () use ($request, $id) {
            $exam = Exam::where('id', $id)->lockForUpdate()->first();

            if (! $exam) {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam not found',
                    'data' => null,
                ], 404);
            }

            $status = $exam->status;

            if (in_array($status, ['published', 'ongoing', 'completed'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Operational exam cannot be deleted; archive it instead.',
                    'data' => null,
                ], 422);
            }

            if ($status === 'draft') {
                $hasDependencies = ExamSchedule::where('exam_id', $id)->exists()
                    || ExamParticipant::where('exam_id', $id)->exists()
                    || ExamAttempt::where('exam_id', $id)->exists()
                    || ExamResult::whereIn(
                        'participant_id',
                        ExamParticipant::where('exam_id', $id)->select('id')
                    )->exists();

                if ($hasDependencies) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Exam cannot be deleted because it has operational data.',
                        'data' => null,
                    ], 422);
                }
            }

            // Soft delete regardless of state (archived included); dependent
            // rows are never cascaded from the controller.
            $exam->delete();

            \App\Models\System\AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'exam_deleted',
                'model' => Exam::class,
                'model_id' => $id,
                'description' => json_encode([
                    'exam_id' => $id,
                    'title' => $exam->title,
                    'status_before' => $status,
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Exam deleted successfully',
                'data' => null,
            ]);
        });
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