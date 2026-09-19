<?php

namespace App\Models\Examination;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamResult extends Model
{
    protected $table = 'exam_results';

    protected $fillable = [
        'participant_id',
        'exam_attempt_id',
        'total_score',
        'correct_count',
        'wrong_count',
        'unanswered_count',
        'percentage',
        'grade',
        'status',
        'graded_at',
        'is_final',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'exam_attempt_id' => 'integer',
            'total_score' => 'decimal:2',
            'correct_count' => 'integer',
            'wrong_count' => 'integer',
            'unanswered_count' => 'integer',
            'percentage' => 'decimal:2',
            'graded_at' => 'datetime',
            'is_final' => 'boolean',
            'finalized_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ExamParticipant::class, 'participant_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }
}
