<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Http\Resources\System\BackupLogResource;
use App\Models\System\BackupLog;
use Illuminate\Http\JsonResponse;

class BackupLogController extends Controller
{
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $query = BackupLog::query()->with('user');
        if ($request->filled('status')) { $query->where('status', $request->input('status')); }
        if ($request->filled('q')) { $query->where('description', 'LIKE', "%{$request->input('q')}%"); }
        $backupLogs = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 15));
        return response()->json([
            'success' => true, 'message' => 'Backup logs retrieved successfully',
            'data' => BackupLogResource::collection($backupLogs),
            'meta' => ['current_page' => $backupLogs->currentPage(), 'per_page' => $backupLogs->perPage(), 'total' => $backupLogs->total(), 'last_page' => $backupLogs->lastPage()],
        ]);
    }
    public function show(int $id): JsonResponse
    {
        $backupLog = BackupLog::with('user')->find($id);
        if (!$backupLog) return response()->json(['success' => false, 'message' => 'Backup log not found', 'data' => null], 404);
        return response()->json(['success' => true, 'message' => 'Backup log retrieved successfully', 'data' => new BackupLogResource($backupLog)]);
    }
}