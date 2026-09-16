<?php

namespace App\Http\Controllers\Api\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Communication\StoreAnnouncementRequest;
use App\Http\Requests\Api\Communication\UpdateAnnouncementRequest;
use App\Http\Resources\Communication\AnnouncementResource;
use App\Models\Communication\Announcement;

class AnnouncementController extends Controller
{
    /**
     * Menampilkan semua pengumuman.
     */
    public function index()
    {
        $announcements = Announcement::latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Announcements retrieved successfully',
            'data' => AnnouncementResource::collection($announcements),
        ]);
    }

    /**
     * Menambahkan pengumuman.
     */
    public function store(StoreAnnouncementRequest $request)
    {
        $announcement = Announcement::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Announcement created successfully',
            'data' => new AnnouncementResource($announcement),
        ], 201);
    }

    /**
     * Menampilkan detail pengumuman.
     */
    public function show($id)
    {
        $announcement = Announcement::find($id);

        if (!$announcement) {
            return response()->json([
                'success' => false,
                'message' => 'Announcement not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Announcement retrieved successfully',
            'data' => new AnnouncementResource($announcement),
        ]);
    }

    /**
     * Mengubah data pengumuman.
     */
    public function update(UpdateAnnouncementRequest $request, $id)
    {
        $announcement = Announcement::find($id);

        if (!$announcement) {
            return response()->json([
                'success' => false,
                'message' => 'Announcement not found',
                'data' => null,
            ], 404);
        }

        $announcement->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Announcement updated successfully',
            'data' => new AnnouncementResource($announcement),
        ]);
    }

    /**
     * Menghapus pengumuman.
     */
    public function destroy($id)
    {
        $announcement = Announcement::find($id);

        if (!$announcement) {
            return response()->json([
                'success' => false,
                'message' => 'Announcement not found',
                'data' => null,
            ], 404);
        }

        $announcement->delete();

        return response()->json([
            'success' => true,
            'message' => 'Announcement deleted successfully',
            'data' => null,
        ]);
    }

    /**
     * Teacher portal: announcements for teachers (guru + umum).
     */
    public function myAnnouncements()
    {
        $announcements = Announcement::whereIn('category', ['guru', 'umum'])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Announcements retrieved successfully',
            'data' => AnnouncementResource::collection($announcements),
        ]);
    }
}