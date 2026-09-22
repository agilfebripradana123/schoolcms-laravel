<?php

namespace App\Services\Students;

use App\Models\Students\Student;
use App\Models\System\AuditLog;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Student login account lifecycle.
 *
 * Owns provisioning (create), enable, disable and NISN↔username sync for the
 * Admin → Students → Data Siswa → Edit Siswa account toggle. The login
 * username always equals `students.nisn`; the initial password is
 * admin-supplied (never derived from the NISN) and hashed through the User
 * model's `hashed` cast.
 *
 * - provision  : no linked User + is_active=true  → creates User (role Siswa)
 * - enable     : linked User + is_active=true     → flips is_active (password
 *                and email untouched)
 * - disable    : linked User + is_active=false    → flips is_active AND revokes
 *                every Sanctum personal access token of that User
 * - sync       : on `students.nisn` change, keeps `users.username` in sync
 *
 * Every mutation is transactional. Operations are idempotent when the target
 * state already holds. Conflicts (username/email taken, inconsistent username)
 * surface as application-level 422 ValidationException — never raw SQLSTATE
 * errors. Audit writes never contain password/password hash/access tokens.
 */
class StudentAccountService
{
    /**
     * Apply the requested account status for a student.
     */
    public function applyStatus(
        Student $student,
        bool $isActive,
        ?string $email = null,
        ?string $password = null,
        ?User $actor = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Student {
        return DB::transaction(function () use ($student, $isActive, $email, $password, $actor, $ipAddress, $userAgent) {
            // B22: re-read inside the transaction so the latest row is used.
            $student = Student::query()->with('user')->findOrFail($student->id);
            $user = $student->user;

            if ($isActive) {
                if ($user === null) {
                    $user = $this->provision($student, $email, $password, $actor, $ipAddress, $userAgent);
                } else {
                    $this->enable($student, $user, $actor, $ipAddress, $userAgent);
                }
            } elseif ($user !== null) {
                $this->disable($student, $user, $actor, $ipAddress, $userAgent);
            }

            return $student->fresh(['user']);
        });
    }

    /**
     * Keep `users.username` synchronized when `students.nisn` changes.
     *
     * Only applies when the Student already has a linked account. Guarded so
     * that a username owned by another account is never overwritten (422) and
     * an already-inconsistent username is never blindly replaced (422).
     */
    public function syncNisnUsername(Student $student, string $oldNisn, string $newNisn): void
    {
        $user = $student->fresh()->user;

        if ($user === null) {
            return;
        }

        if ($user->username === $newNisn) {
            return;
        }

        if (self::usernameTakenByOther($newNisn, $user->id)) {
            throw ValidationException::withMessages([
                'nisn' => ['NISN tersebut sudah digunakan oleh akun lain.'],
            ]);
        }

        if ($user->username !== $oldNisn) {
            throw ValidationException::withMessages([
                'nisn' => ['Akun login siswa tidak sinkron dengan NISN lama. Periksa data akun siswa.'],
            ]);
        }

        $user->update(['username' => $newNisn]);
    }

    /**
     * Create a User (role Siswa) for a Student without a linked account.
     * Requires an admin-supplied email and initial password (min 6 chars).
     */
    private function provision(
        Student $student,
        ?string $email,
        ?string $password,
        ?User $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): User {
        $nisn = trim((string) $student->nisn);

        if ($nisn === '') {
            throw ValidationException::withMessages([
                'nisn' => ['Siswa belum memiliki NISN.'],
            ]);
        }

        if ($email === null || trim($email) === '') {
            throw ValidationException::withMessages([
                'email' => ['Email wajib diisi untuk membuat akun siswa.'],
            ]);
        }

        if ($password === null || $password === '') {
            throw ValidationException::withMessages([
                'password' => ['Password awal wajib diisi untuk membuat akun siswa.'],
            ]);
        }

        if (self::usernameTaken($nisn)) {
            throw ValidationException::withMessages([
                'nisn' => ['NISN tersebut sudah digunakan oleh akun lain.'],
            ]);
        }

        if (self::emailTaken($email)) {
            throw ValidationException::withMessages([
                'email' => ['Email tersebut sudah digunakan oleh akun lain.'],
            ]);
        }

        $siswaRoleId = Role::query()->where('name', 'Siswa')->value('id');

        if (! $siswaRoleId) {
            throw ValidationException::withMessages([
                'role' => ['Role "Siswa" tidak ditemukan.'],
            ]);
        }

        $user = User::create([
            'role_id' => $siswaRoleId,
            'username' => $nisn,
            'name' => $student->name,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);

        $student->user_id = $user->id;
        $student->save();

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'student_account_created',
            'model' => 'Student',
            'model_id' => $student->id,
            'description' => json_encode([
                'is_active' => true,
                'email' => $email,
            ]),
            'ip_address' => $ipAddress ?? request()->ip(),
            'user_agent' => $userAgent ?? request()->userAgent(),
        ]);

        return $user;
    }

    /**
     * Activate an existing account without touching its password or email.
     */
    private function enable(
        Student $student,
        User $user,
        ?User $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        if ($user->is_active) {
            return;
        }

        $user->update(['is_active' => true]);

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'student_account_enabled',
            'model' => 'Student',
            'model_id' => $student->id,
            'description' => json_encode([
                'old_is_active' => false,
                'new_is_active' => true,
            ]),
            'ip_address' => $ipAddress ?? request()->ip(),
            'user_agent' => $userAgent ?? request()->userAgent(),
        ]);
    }

    /**
     * Deactivate an account and revoke every Sanctum token of that User so an
     * existing session is terminated immediately.
     */
    private function disable(
        Student $student,
        User $user,
        ?User $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        if (! $user->is_active) {
            return;
        }

        $user->update(['is_active' => false]);
        $user->tokens()->delete();

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'student_account_disabled',
            'model' => 'Student',
            'model_id' => $student->id,
            'description' => json_encode([
                'old_is_active' => true,
                'new_is_active' => false,
            ]),
            'ip_address' => $ipAddress ?? request()->ip(),
            'user_agent' => $userAgent ?? request()->userAgent(),
        ]);
    }

    private static function usernameTaken(string $username): bool
    {
        return User::query()
            ->where('username', $username)
            ->whereNull('deleted_at')
            ->exists();
    }

    private static function usernameTakenByOther(string $username, int $exceptUserId): bool
    {
        return User::query()
            ->where('username', $username)
            ->where('id', '!=', $exceptUserId)
            ->whereNull('deleted_at')
            ->exists();
    }

    private static function emailTaken(string $email): bool
    {
        return User::query()
            ->where('email', $email)
            ->whereNull('deleted_at')
            ->exists();
    }
}