<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Models\System\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Role-aware login.
     * Frontend sends expected_role based on login page:
     * - siswa: only NIS (users.username) + role Siswa
     * - guru : only email + role Guru
     * - admin: username OR email + role Admin/Administrator
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'expected_role' => ['nullable', 'string', 'in:siswa,guru,admin,administrator,Siswa,Guru,Admin,Administrator'],
        ]);

        $expected = strtolower($validated['expected_role'] ?? '');
        $login = $validated['login'];

        $query = User::with(['role.permissions', 'permissions']);

        if ($expected === 'siswa') {
            // Siswa: only NIS = username
            $query->where('username', $login);
        } elseif ($expected === 'guru') {
            // Guru: only email
            $query->where('email', $login);
        } elseif (in_array($expected, ['admin', 'administrator'], true)) {
            // Admin/Administrator: username OR email
            // Actual role is checked below.
            $query->where(function ($q) use ($login) {
                $q->where('email', $login)
                    ->orWhere('username', $login);
            });
        } else {
            // Fallback without expected_role:
            // preserve previous username/email login behavior.
            $query->where(function ($q) use ($login) {
                $q->where('email', $login)
                    ->orWhere('username', $login);
            });
        }

        $user = $query->first();

        if (!$user) {
            return response()->json([
                'message' => 'Username atau Email tidak ditemukan.'
            ], 401);
        }

        if (!Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Password salah.'
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Akun Anda tidak aktif.'
            ], 403);
        }

        // Role gate: expected_role must match user's actual role
        if ($expected !== '') {
            $actual = strtolower($user->role?->name ?? '');
            $isAdminExpected = in_array($expected, ['admin', 'administrator'], true);
            $isAdminActual = in_array($actual, ['admin', 'administrator'], true);
            $matches = ($actual === $expected) || ($isAdminExpected && $isAdminActual);
            if (!$matches) {
                return response()->json([
                    'message' => 'Akun tidak memiliki akses untuk halaman login ini.'
                ], 403);
            }
        }

        $token = $user->createToken('schoolcms')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil.',
            'token' => $token,
            'user' => $this->userResponse($user),
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['role.permissions', 'permissions']);

        return response()->json([
            'user' => $this->userResponse($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil.'
        ]);
    }

    /**
     * Inline user payload consistent across login/me. `permissions` is the
     * list of EFFECTIVE permission names:
     *
     *   role permissions (permission_role) UNION user additional permissions (permission_user)
     *
     * Matches how ProfileController::userResponse() builds the same payload so
     * the frontend always receives the full effective permission set.
     */
    private function userResponse(User $user): array
    {
        $rolePermissions = $user->role?->permissions?->pluck('name')->all() ?? [];
        $userPermissions = $user->permissions?->pluck('name')->all() ?? [];
        $effective = array_values(array_unique(array_merge($rolePermissions, $userPermissions)));

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'photo' => $user->photo ? \Illuminate\Support\Facades\Storage::disk('public')->url($user->photo) : null,
            'is_active' => $user->is_active,
            'role' => $user->role?->name,
            'permissions' => $effective,
        ];
    }
}
