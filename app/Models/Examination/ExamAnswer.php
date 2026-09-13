<?php

namespace App\Models\Examination;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAnswer extends Model
{
    protected $table = 'exam_answers';

    protected $fillable = [
        'participant_id',
        'question_id',
        'selected_option_id',
        'essay_answer',
        'is_correct',
        'answered_at',
        'exam_attempt_id',
        'attempt_question_id',
        'selected_attempt_option_id',
        'score',
        'feedback',
        'grade_status',
        'graded_by',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'answered_at' => 'datetime',
            'score' => 'decimal:2',
            'graded_by' => 'integer',
            'graded_at' => 'datetime',
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

    public function attemptQuestion(): BelongsTo
    {
        return $this->belongsTo(ExamAttemptQuestion::class, 'attempt_question_id');
    }

    public function attemptedOption(): BelongsTo
    {
        return $this->belongsTo(ExamAttemptQuestionOption::class, 'selected_attempt_option_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'question_id');
    }

    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'selected_option_id');
    }
}
