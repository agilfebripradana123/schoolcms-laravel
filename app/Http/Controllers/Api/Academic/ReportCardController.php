<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Academic\StoreReportCardRequest;
use App\Http\Requests\Api\Academic\UpdateReportCardRequest;
use App\Http\Resources\Academic\ReportCardResource;
use App\Models\Academic\ReportCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportCardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ReportCard::query()->with(['student', 'schoolClass', 'academicYear', 'semester']);

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->input('class_id'));
        }

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', $request->input('academic_year_id'));
        }

        if ($request->filled('semester_id')) {
            $query->where('semester_id', $request->input('semester_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $reportCards = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Report cards retrieved successfully',
            'data' => ReportCardResource::collection($reportCards),
            'meta' => [
                'current_page' => $reportCards->currentPage(),
                'per_page' => $reportCards->perPage(),
                'total' => $reportCards->total(),
                'last_page' => $reportCards->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $reportCard = ReportCard::with(['student', 'schoolClass', 'academicYear', 'semester'])->find($id);

        if (! $reportCard) {
            return response()->json([
                'success' => false,
                'message' => 'Report card not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Report card retrieved successfully',
            'data' => new ReportCardResource($reportCard),
        ]);
    }

    public function store(StoreReportCardRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (($validated['status'] ?? 'draft') === 'published') {
            $validated['published_at'] = now();
        } else {
            $validated['published_at'] = null;
        }

        $reportCard = ReportCard::create($validated);
        $reportCard->load(['student', 'schoolClass', 'academicYear', 'semester']);

        return response()->json([
            'success' => true,
            'message' => 'Report card created successfully',
            'data' => new ReportCardResource($reportCard),
        ], 201);
    }

    public function update(UpdateReportCardRequest $request, int $id): JsonResponse
    {
        $reportCard = ReportCard::find($id);

        if (! $reportCard) {
            return response()->json([
                'success' => false,
                'message' => 'Report card not found',
                'data' => null,
            ], 404);
        }

        $validated = $request->validated();

        if ($reportCard->status === 'published') {
            foreach (['student_id', 'class_id', 'academic_year_id', 'semester_id', 'published_at'] as $field) {
                if (array_key_exists($field, $validated)) {
                    return $this->locked('A published report card has an immutable identity and timestamp.');
                }
            }

            if (isset($validated['status']) && $validated['status'] !== 'published') {
                return $this->locked('A published report card cannot transition back to draft.');
            }

            $reportCard->update(['teacher_notes' => $validated['teacher_notes'] ?? $reportCard->teacher_notes]);
        } else {
            unset($validated['published_at']);

            if (($validated['status'] ?? 'draft') === 'published') {
                $validated['published_at'] = now();
            } else {
                $validated['published_at'] = null;
            }

            $reportCard->update($validated);
        }

        $reportCard->load(['student', 'schoolClass', 'academicYear', 'semester']);

        return response()->json([
            'success' => true,
            'message' => 'Report card updated successfully',
            'data' => new ReportCardResource($reportCard),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $reportCard = ReportCard::find($id);

        if (! $reportCard) {
            return response()->json([
                'success' => false,
                'message' => 'Report card not found',
                'data' => null,
            ], 404);
        }

        if ($reportCard->status === 'published') {
            return $this->locked('A published report card cannot be deleted.');
        }

        $reportCard->delete();

        return response()->json([
            'success' => true,
            'message' => 'Report card deleted successfully',
            'data' => null,
        ]);
    }

    private function locked(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => ['report_card' => ['A published report card is locked.']],
            'data' => null,
        ], 422);
    }
}
