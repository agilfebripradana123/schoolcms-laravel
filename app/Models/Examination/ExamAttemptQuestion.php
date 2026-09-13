<?php

namespace App\Models\Examination;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Snapshot of one question exactly as an attempt used it (Phase 2G).
 *
 * Historical copy: content/points/position freeze the state at attempt start.
 * `source_question_id` is a plain reference for traceability and survives
 * QuestionBank soft-delete/archive (no FK, so hard deletion cannot destroy the
 * snapshot either).
 */
class ExamAttemptQuestion extends Model
{
    protected $table = 'exam_attempt_questions';

    protected $fillable = [
        'exam_attempt_id',
        'source_question_id',
        'question_code',
        'question_text',
        'question_type',
        'points',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'exam_attempt_id' => 'integer',
            'source_question_id' => 'integer',
            'points' => 'integer',
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ExamAttemptQuestionOption::class, 'attempt_question_id');
    }
}