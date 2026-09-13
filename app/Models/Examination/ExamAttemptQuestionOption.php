<?php

namespace App\Models\Examination;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of one option exactly as an attempt used it (Phase 2G).
 *
 * `is_correct` is grading truth copied at snapshot time and must NEVER be
 * serialized into student-facing payloads (sanitized resources only).
 * `source_option_id` is a plain reference that survives option replacement:
 * a QuestionBank option may be deleted/re-created without invalidating this
 * historical row or the answers pointing at it.
 */
class ExamAttemptQuestionOption extends Model
{
    protected $table = 'exam_attempt_question_options';

    protected $fillable = [
        'attempt_question_id',
        'source_option_id',
        'option_text',
        'option_image',
        'position',
        'is_correct',
    ];

    protected $hidden = [
        'is_correct',
    ];

    protected function casts(): array
    {
        return [
            'attempt_question_id' => 'integer',
            'source_option_id' => 'integer',
            'position' => 'integer',
            'is_correct' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function attemptQuestion(): BelongsTo
    {
        return $this->belongsTo(ExamAttemptQuestion::class, 'attempt_question_id');
    }
}