<?php

namespace App\Http\Controllers\Api\Examination;

use App\Http\Controllers\Controller;
use App\Models\Examination\Exam;
use App\Services\Examination\ExamReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only Examination reporting (Phase B15).
 *
 * Admin access follows the existing `manage-exams` administrative pattern;
 * teacher access is restricted to the teacher's scope (Exam::scopeTeacherAccessible:
 * classless = subject-only, class-scoped = matching TeacherAssignment triple),
 * mirroring the other teacher examination controllers (out-of-scope -> 404).
 * Reporting never mutates examination data.
 */
class ExamReportController extends Controller
{
    private function authorizeExam(Request $request, Exam $exam): ?JsonResponse
    {
        $teacher = $request->user()?->teacherProfile;

        if ($teacher) {
            if (! $exam->accessibleByTeacher($teacher->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam report not found.',
                    'data' => null,
                ], 404);
            }

            return null;
        }

        // Non-teacher callers must be administrative (route is additionally
        // gated by `manage-exams` / `view-exam-results`, and Admin/Administrator
        // roles have no teacherProfile).
        $role = strtolower((string) $request->user()?->role?->name);
        if (! in_array($role, ['admin', 'administrator'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
                'data' => null,
            ], 403);
        }

        return null;
    }

    /**
     * GET /api/exam-reports/{exam}  |  GET /api/teacher/exam-reports/{exam}
     */
    public function index(Request $request, int $exam): JsonResponse
    {
        $examModel = Exam::with('subject')->find($exam);

        if (! $examModel) {
            return $this->notFound();
        }

        $guard = $this->authorizeExam($request, $examModel);
        if ($guard !== null) {
            return $guard;
        }

        return response()->json([
            'success' => true,
            'message' => 'Exam report retrieved successfully.',
            'data' => app(ExamReportingService::class)->examSummary($examModel->id),
        ]);
    }

    /**
     * GET /api/exam-reports/{exam}/questions  |  GET /api/teacher/exam-reports/{exam}/questions
     */
    public function questions(Request $request, int $exam): JsonResponse
    {
        $examModel = Exam::find($exam);

        if (! $examModel) {
            return $this->notFound();
        }

        $guard = $this->authorizeExam($request, $examModel);
        if ($guard !== null) {
            return $guard;
        }

        return response()->json([
            'success' => true,
            'message' => 'Exam question report retrieved successfully.',
            'data' => app(ExamReportingService::class)->questionSummary($examModel->id),
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Exam report not found.',
            'data' => null,
        ], 404);
    }
}