<?php

namespace App\Models\Examination;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit/security event recorded against a secure exam attempt (Phase 10).
 *
 * Event logging is an audit trail only — it is NOT a security boundary.
 * The server still authorizes every request and decides validity.
 */
class ExamAttemptEvent extends Model
{
    public const TYPE_VISIBILITY_CHANGE = 'visibility_change';
    public const TYPE_TAB_SWITCH = 'tab_switch';
    public const TYPE_FULLSCREEN_EXIT = 'fullscreen_exit';
    public const TYPE_RECONNECT = 'reconnect';
    public const TYPE_LATE_REQUEST = 'late_request';
    public const TYPE_MULTIPLE_SESSION_ATTEMPT = 'multiple_session_attempt';

    protected $table = 'exam_attempt_events';

    protected $fillable = [
        'exam_attempt_id',
        'event_type',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }
}
