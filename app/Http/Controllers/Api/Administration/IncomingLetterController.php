<?php

namespace App\Http\Controllers\Api\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Administration\StoreIncomingLetterRequest;
use App\Http\Requests\Api\Administration\UpdateIncomingLetterRequest;
use App\Http\Resources\Administration\IncomingLetterResource;
use App\Models\Administration\IncomingLetter;
use Illuminate\Http\JsonResponse;

class IncomingLetterController extends Controller
{
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $query = IncomingLetter::query();

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('letter_number', 'LIKE', "%{$q}%")
                    ->orWhere('sender', 'LIKE', "%{$q}%")
                    ->orWhere('subject', 'LIKE', "%{$q}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('is_important')) {
            $query->where('is_important', $request->boolean('is_important') ? 1 : 0);
        }

        $letters = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Incoming letters retrieved successfully',
            'data' => IncomingLetterResource::collection($letters),
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
        $letter = IncomingLetter::with('dispositions')->find($id);

        if (!$letter) {
            return response()->json([
                'success' => false,
                'message' => 'Incoming letter not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Incoming letter retrieved successfully',
            'data' => new IncomingLetterResource($letter),
        ]);
    }

    public function store(StoreIncomingLetterRequest $request): JsonResponse
    {
        $letter = IncomingLetter::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Incoming letter created successfully',
            'data' => new IncomingLetterResource($letter),
        ], 201);
    }

    public function update(UpdateIncomingLetterRequest $request, int $id): JsonResponse
    {
        $letter = IncomingLetter::find($id);

        if (!$letter) {
            return response()->json([
                'success' => false,
                'message' => 'Incoming letter not found',
                'data' => null,
            ], 404);
        }

        $letter->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Incoming letter updated successfully',
            'data' => new IncomingLetterResource($letter),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $letter = IncomingLetter::find($id);

        if (!$letter) {
            return response()->json([
                'success' => false,
                'message' => 'Incoming letter not found',
                'data' => null,
            ], 404);
        }

        $letter->delete();

        return response()->json([
            'success' => true,
            'message' => 'Incoming letter deleted successfully',
            'data' => null,
        ]);
    }

    /**
     * Teacher portal: incoming letters disposed to logged-in teacher.
     */
    public function myLetters(\Illuminate\Http\Request $request): JsonResponse
    {
        $user = $request->user();
        $query = IncomingLetter::query()->whereHas('dispositions', function ($q) use ($user) {
            $q->where('assigned_to', $user->id);
        });

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('letter_number', 'LIKE', "%{$q}%")
                    ->orWhere('sender', 'LIKE', "%{$q}%")
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
            'message' => 'Incoming letters retrieved successfully',
            'data' => IncomingLetterResource::collection($letters),
            'meta' => [
                'current_page' => $letters->currentPage(),
                'per_page' => $letters->perPage(),
                'total' => $letters->total(),
                'last_page' => $letters->lastPage(),
            ],
        ]);
    }
}
