<?php

namespace App\Models\System;

use App\Models\Students\Student;
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
}
