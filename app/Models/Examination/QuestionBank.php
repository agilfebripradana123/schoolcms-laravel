<?php

namespace App\Models\Examination;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Academic\Subject;
use App\Models\System\User;
class QuestionBank extends Model
{
    use SoftDeletes;

    protected $table = 'question_banks';

    /**
     * `code` is deliberately NOT fillable: the question code is generated
     * server-side from the auto-increment id (Q-000001, ...) at creation and
     * must never be mass-assigned or silently changed afterwards.
     */
    protected $fillable = [
        'subject_id',
        'instruction_id',
        'owner_id',
        'question_text',
        'question_image',
        'audio_url',
        'video_url',
        'type',
        'difficulty',
        'cognitive_level',
        'competency',
        'indicator',
        'explanation',
        'points',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function instruction(): BelongsTo
    {
        return $this->belongsTo(ExamInstruction::class, 'instruction_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class, 'question_id');
    }

    public function examCompositions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class, 'question_id');
    }
}