<?php

namespace App\Models\Examination;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit composition row: one reusable QuestionBank item placed inside one
 * Exam. This is the sole source of truth for a composed exam's question set,
 * order and per-question weight (Phase 2D).
 *
 * Schema (Phase 2A) deliberately uses `position` (ordering) and `points`
 * (per-question weight). `blueprint_item_id` is a nullable future hook for
 * kisi-kisi traceability.
 */
class ExamQuestion extends Model
{
    protected $table = 'exam_questions';

    protected $fillable = [
        'exam_id',
        'question_id',
        'blueprint_item_id',
        'position',
        'points',
    ];

    protected function casts(): array
    {
        return [
            'exam_id' => 'integer',
            'question_id' => 'integer',
            'blueprint_item_id' => 'integer',
            'position' => 'integer',
            'points' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'question_id');
    }

    public function blueprintItem(): BelongsTo
    {
        return $this->belongsTo(BlueprintItem::class, 'blueprint_item_id');
    }
}