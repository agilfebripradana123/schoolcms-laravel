<?php

namespace App\Http\Controllers\Api\Examination;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Examination\StoreExamParticipantRequest;
use App\Http\Requests\Api\Examination\UpdateExamParticipantRequest;
use App\Http\Resources\Examination\ExamParticipantResource;
use App\Models\Examination\ExamParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExamParticipantController extends Controller
{
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $query = ExamParticipant::query()->with(['exam', 'student']);

        if ($request->filled('exam_id')) {
            $query->where('exam_id', $request->input('exam_id'));
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $participants = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Exam participants retrieved successfully',
            'data' => ExamParticipantResource::collection($participants),
            'meta' => [
                'current_page' => $participants->currentPage(),
                'per_page' => $participants->perPage(),
                'total' => $participants->total(),
                'last_page' => $participants->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $participant = ExamParticipant::with(['exam', 'student'])->find($id);

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'Exam participant not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Exam participant retrieved successfully',
            'data' => new ExamParticipantResource($participant),
        ]);
    }

    public function store(StoreExamParticipantRequest $request): JsonResponse
    {
        $participant = ExamParticipant::create($request->validated());
        $participant->load(['exam', 'student']);

        \App\Models\System\AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'exam_participant_created',
            'model' => ExamParticipant::class,
            'model_id' => $participant->id,
            'description' => json_encode([
                'participant_id' => $participant->id,
                'exam_id' => $participant->exam_id,
                'student_id' => $participant->student_id,
                'schedule_id' => $participant->schedule_id,
                'exam_card_number' => $participant->exam_card_number,
            ]),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Exam participant created successfully',
            'data' => new ExamParticipantResource($participant),
        ], 201);
    }

    public function update(UpdateExamParticipantRequest $request, int $id): JsonResponse
    {
        $participant = ExamParticipant::find($id);

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'Exam participant not found',
                'data' => null,
            ], 404);
        }

        $before = [
            'status' => $participant->status,
            'schedule_id' => $participant->schedule_id,
            'exam_card_number' => $participant->exam_card_number,
            'login_allowed' => $participant->login_allowed,
            'is_blocked' => $participant->is_blocked,
        ];

        $participant->update($request->validated());
        $participant->load(['exam', 'student']);

        $changes = [];
        foreach ($before as $key => $oldValue) {
            if ($oldValue != $participant->getAttribute($key)) {
                $changes[] = [
                    'field' => $key,
                    'before' => $oldValue,
                    'after' => $participant->getAttribute($key),
                ];
            }
        }

        if (! empty($changes)) {
            \App\Models\System\AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'exam_participant_updated',
                'model' => ExamParticipant::class,
                'model_id' => $participant->id,
                'description' => json_encode([
                    'participant_id' => $participant->id,
                    'exam_id' => $participant->exam_id,
                    'changes' => $changes,
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Exam participant updated successfully',
            'data' => new ExamParticipantResource($participant),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $participant = ExamParticipant::where('id', $id)->lockForUpdate()->first();

            if (!$participant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam participant not found',
                    'data' => null,
                ], 404);
            }

            // Deleting a participant cascades attempts, answers, snapshots and
            // results. Once examination activity exists, history must be
            // preserved: reject deletion instead of destroying it.
            if ($participant->attempts()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Participant has examination activity and cannot be deleted.',
                    'data' => null,
                ], 422);
            }

            $participantSnapshot = [
                'participant_id' => $participant->id,
                'exam_id' => $participant->exam_id,
                'student_id' => $participant->student_id,
            ];

            $participant->delete();

            // Captured before deletion because the target row no longer exists.
            \App\Models\System\AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'exam_participant_deleted',
                'model' => ExamParticipant::class,
                'model_id' => $id,
                'description' => json_encode($participantSnapshot),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Exam participant deleted successfully',
                'data' => null,
            ]);
        });
    }
}
