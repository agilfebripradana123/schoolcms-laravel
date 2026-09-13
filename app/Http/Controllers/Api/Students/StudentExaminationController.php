<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Controller;
use App\Http\Resources\Examination\StudentExamAnswerResource;
use App\Http\Resources\Examination\StudentExamParticipantResource;
use App\Http\Resources\Examination\StudentExamResultResource;
use App\Models\Examination\Exam;
use App\Models\Examination\ExamInstruction;
use App\Models\Examination\ExamParticipant;
use App\Models\Examination\ExamResult;
use App\Models\Examination\ExamSchedule;
use App\Models\Examination\ExamSession;
use App\Models\Examination\ExamAnswer;
use App\Models\Examination\QuestionBank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentExaminationController extends Controller
{
    public function exams(Request $request): JsonResponse
    {
        $student = $request->attributes->get('student_profile');
        $examIds = ExamParticipant::where('student_id', $student->id)->pluck('exam_id');
        if ($examIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No exams found',
                'data' => [],
            ]);
        }
        $exams = Exam::whereIn('id', $examIds)->get();
        return response()->json([
            'success' => true,
            'message' => 'Exams retrieved successfully',
            'data' => $exams,
        ]);
    }

    public function examSchedules(Request $request): JsonResponse
    {
        $student = $request->attributes->get('student_profile');
        $examIds = ExamParticipant::where('student_id', $student->id)->pluck('exam_id');
        if ($examIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Exam schedules retrieved successfully',
                'data' => [],
            ]);
        }
        $schedules = ExamSchedule::whereIn('exam_id', $examIds)->with(['exam', 'session'])->get();
        return response()->json([
            'success' => true,
            'message' => 'Exam schedules retrieved successfully',
            'data' => $schedules,
        ]);
    }

    public function examSessions(Request $request): JsonResponse
    {
        $student = $request->attributes->get('student_profile');
        $examIds = ExamParticipant::where('student_id', $student->id)->pluck('exam_id');
        if ($examIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Exam sessions retrieved successfully',
                'data' => [],
            ]);
        }
        // ExamSession is standalone (no exam_id), return all sessions for valid exams
        $sessionIds = ExamSchedule::whereIn('exam_id', $examIds)->pluck('session_id')->unique();
        $sessions = ExamSession::whereIn('id', $sessionIds)->get();
        return response()->json([
            'success' => true,
            'message' => 'Exam sessions retrieved successfully',
            'data' => $sessions,
        ]);
    }

    public function examInstructions(Request $request): JsonResponse
    {
        // ExamInstruction is global (no exam_id), return all active
        $instructions = ExamInstruction::where('is_active', 1)->get();
        return response()->json([
            'success' => true,
            'message' => 'Exam instructions retrieved successfully',
            'data' => $instructions,
        ]);
    }

    public function examParticipants(Request $request): JsonResponse
    {
        $student = $request->attributes->get('student_profile');
        $participants = ExamParticipant::where('student_id', $student->id)
            ->with(['exam.subject', 'result'])
            ->get()
            ->map(fn (ExamParticipant $p) => new StudentExamParticipantResource($p));

        return response()->json([
            'success' => true,
            'message' => 'Exam participants retrieved successfully',
            'data' => $participants,
        ]);
    }

    public function examResults(Request $request): JsonResponse
    {
        $student = $request->attributes->get('student_profile');
        $participantIds = ExamParticipant::where('student_id', $student->id)->pluck('id');
        $results = ExamResult::whereIn('participant_id', $participantIds)
            ->with(['participant.exam.subject'])
            ->get()
            ->map(fn (ExamResult $result) => new StudentExamResultResource($result));

        return response()->json([
            'success' => true,
            'message' => 'Exam results retrieved successfully',
            'data' => $results,
        ]);
    }

    public function examAnswers(Request $request): JsonResponse
    {
        $student = $request->attributes->get('student_profile');
        $participantIds = ExamParticipant::where('student_id', $student->id)->pluck('id');
        $answers = ExamAnswer::whereIn('participant_id', $participantIds)
            ->get()
            ->map(fn (ExamAnswer $answer) => new StudentExamAnswerResource($answer));

        return response()->json([
            'success' => true,
            'message' => 'Exam answers retrieved successfully',
            'data' => $answers,
        ]);
    }
}