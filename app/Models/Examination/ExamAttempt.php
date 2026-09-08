<?php

namespace App\Models\Examination;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Per-attempt secure exam session (Phase 10).
 *
 * Distinct from the pre-existing time-slot `exam_sessions` entity. One record
 * represents ONE student attempt at ONE exam with server-authoritative
 * timing/expiry and a persistent question/option order.
 *
 * Status values: active, submitted, expired.
 */
class ExamAttempt extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_EXPIRED = 'expired';

    protected $table = 'exam_attempts';

    protected $fillable = [
        'exam_participant_id',
        'exam_id',
        'attempt_number',
        'status',
        'started_at',
        'expires_at',
        'submitted_at',
        'question_order',
        'option_order',
        'token',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'question_order' => 'array',
            'option_order' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ExamParticipant::class, 'exam_participant_id');
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class, 'exam_attempt_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ExamAttemptEvent::class, 'exam_attempt_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isExpiredAt(\DateTimeInterface $now): bool
    {
        return $this->expires_at !== null && $now >= $this->expires_at;
    }

    public function generateToken(): string
    {
        $this->token = Str::random(40);
        return $this->token;
    }
}
