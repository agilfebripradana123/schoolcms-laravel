<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\System\UpdatePasswordRequest;
use App\Http\Requests\Api\System\UpdateProfileRequest;
use App\Models\System\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function show(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user()->load(['role.permissions', 'permissions']);

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil dimuat.',
            'data' => $this->userResponse($user),
        ]);
    }

    /**
     * Update the authenticated user's own profile.
     * The target is always the authenticated user — never a client-supplied id.
     * Role / status / permissions are never mutable through this endpoint.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $user->update($validated);
        $user->load(['role.permissions', 'permissions']);

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diperbarui.',
            'user' => $this->userResponse($user),
        ]);
    }

    /**
     * Change the authenticated user's own password.
     * Never returns a password and never logs it.
     */
    public function updatePhoto(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate(['photo' => 'required|image|max:2048|mimes:jpg,jpeg,png,webp']);
        $user = $request->user();
        if ($user->photo) \Illuminate\Support\Facades\Storage::disk('public')->delete($user->photo);
        $path = $request->file('photo')->store('users/photos', 'public');
        $user->update(['photo' => $path]);
        $user->load(['role.permissions', 'permissions']);

        return response()->json([
            'success' => true,
            'message' => 'Foto profil berhasil diperbarui.',
            'data' => $this->userResponse($user),
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password saat ini salah.',
                'errors' => [
                    'current_password' => ['Password saat ini salah.'],
                ],
            ], 422);
        }

        $user->password = Hash::make($request->input('password'));
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password berhasil diubah.',
        ]);
    }

    /**
     * Inline user payload kept consistent with AuthController::me / login so the
     * frontend can consume a uniform `User` shape (role exposed as its name).
     */
    private function userResponse(User $user): array
    {
        $rolePermissions = $user->role?->permissions?->pluck('name')->all() ?? [];
        $directPermissions = $user->permissions?->pluck('name')->all() ?? [];
        $effective = array_values(array_unique(array_merge($rolePermissions, $directPermissions)));

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'photo' => $user->photo ? \Illuminate\Support\Facades\Storage::disk('public')->url($user->photo) : null,
            'photo_path' => $user->photo,
            'is_active' => $user->is_active,
            'role' => $user->role?->name,
            'permissions' => $effective,
        ];
    }
}
