<?php

namespace App\Http\Controllers\Api\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Administration\StoreOutgoingLetterRequest;
use App\Http\Requests\Api\Administration\UpdateOutgoingLetterRequest;
use App\Http\Resources\Administration\OutgoingLetterResource;
use App\Models\Administration\OutgoingLetter;
use Illuminate\Http\JsonResponse;

class OutgoingLetterController extends Controller
{
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $query = OutgoingLetter::query();

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('letter_number', 'LIKE', "%{$q}%")
                    ->orWhere('recipient', 'LIKE', "%{$q}%")
                    ->orWhere('subject', 'LIKE', "%{$q}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $letters = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Outgoing letters retrieved successfully',
            'data' => OutgoingLetterResource::collection($letters),
            'meta' => [
                'current_page' => $letters->currentPage(),
                'per_page' => $letters->perPage(),
                'total' => $letters->total(),
                'last_page' => $letters->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $letter = OutgoingLetter::find($id);

        if (!$letter) {
            return response()->json([
                'success' => false,
                'message' => 'Outgoing letter not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Outgoing letter retrieved successfully',
            'data' => new OutgoingLetterResource($letter),
        ]);
    }

    public function store(StoreOutgoingLetterRequest $request): JsonResponse
    {
        $letter = OutgoingLetter::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Outgoing letter created successfully',
            'data' => new OutgoingLetterResource($letter),
        ], 201);
    }

    public function update(UpdateOutgoingLetterRequest $request, int $id): JsonResponse
    {
        $letter = OutgoingLetter::find($id);

        if (!$letter) {
            return response()->json([
                'success' => false,
                'message' => 'Outgoing letter not found',
                'data' => null,
            ], 404);
        }

        $letter->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Outgoing letter updated successfully',
            'data' => new OutgoingLetterResource($letter),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $letter = OutgoingLetter::find($id);

        if (!$letter) {
            return response()->json([
                'success' => false,
                'message' => 'Outgoing letter not found',
                'data' => null,
            ], 404);
        }

        $letter->delete();

        return response()->json([
            'success' => true,
            'message' => 'Outgoing letter deleted successfully',
            'data' => null,
        ]);
    }

    /**
     * Teacher portal: all outgoing letters (teachers see all outgoing).
     */
    public function myLetters(\Illuminate\Http\Request $request): JsonResponse
    {
        $query = OutgoingLetter::query();

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('letter_number', 'LIKE', "%{$q}%")
                    ->orWhere('recipient', 'LIKE', "%{$q}%")
                    ->orWhere('subject', 'LIKE', "%{$q}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $letters = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Outgoing letters retrieved successfully',
            'data' => OutgoingLetterResource::collection($letters),
            'meta' => [
                'current_page' => $letters->currentPage(),
                'per_page' => $letters->perPage(),
                'total' => $letters->total(),
                'last_page' => $letters->lastPage(),
            ],
        ]);
    }
}
