<?php

namespace App\Models\System;

use App\Models\Students\Student;
use App\Models\Staff\Teacher;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes, HasFactory;

    /**
     * Default read capabilities granted to every user with the `Guru` role.
     * These are the core Portal Guru modules (Kelas & Siswa); additional
     * optional modules (e.g. Sarpras) are still granted per-user via
     * permission_user.
     */
    public const GURU_DEFAULT_PERMISSIONS = [
        'view-classes',
        'view-students',
        'view-schedules',
        'view-attendance',
        'manage-attendance',
        'view-grades',
        'manage-grades',
        'view-assignments',
        'manage-assignments',
        'view-exams',
        'view-exam-schedules',
        'view-exam-results',
        'view-exam-monitoring',
        'view-achievements',
        'view-violations',
        'view-extracurricular',
        'view-counselings',
    ];

    protected $table = 'users';

    protected $fillable = [
        'role_id',
        'username',
        'name',
        'email',
        'password',
        'photo',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory()
    {
        return UserFactory::new();
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user', 'user_id', 'permission_id');
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(Student::class, 'user_id');
    }

    /**
     * PHASE 2D restore — external merge `bc3caa5` (origin/main) dropped these
     * three members from the User model. PermissionMiddleware still calls
     * `effectivePermissions()` and the teacher portal relies on
     * `teacherProfile()`, so without them every permission-gated route returns
     * 500. Restoring the exact Phase 2B definitions to keep the Phase 2B/2C
     * security guarantees intact (see Phase 2D report, git section).
     */

    public function teacherProfile(): HasOne
    {
        return $this->hasOne(Teacher::class, 'user_id');
    }

    /**
     * Effective permission names = role permissions UNION user additional
     * permissions (deduplicated). Requires `role.permissions` and `permissions`
     * relations to be loaded, otherwise they are fetched. Users with the `Guru`
     * role also receive the default Guru read capabilities.
     */
    public function effectivePermissions(): array
    {
        $this->loadMissing(['role.permissions', 'permissions']);

        $rolePermissions = $this->role?->permissions?->pluck('name')->all() ?? [];
        $userPermissions = $this->permissions?->pluck('name')->all() ?? [];

        $effective = array_merge($rolePermissions, $userPermissions);

        if (strtolower((string) $this->role?->name) === 'guru') {
            $effective = array_merge($effective, self::GURU_DEFAULT_PERMISSIONS);
        }

        return array_values(array_unique($effective));
    }

    /**
     * Check whether the user holds the given permission (role or additional).
     */
    public function hasPermission(?string $permission): bool
    {
        if ($permission === null || $permission === '') {
            return false;
        }

        return in_array($permission, $this->effectivePermissions(), true);
    }
}
