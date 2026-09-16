<?php

namespace App\Models\Academic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeAssessment extends Model
{
    protected $table = 'grade_assessments';

    protected $fillable = [
        'student_id',
        'subject_id',
        'class_id',
        'academic_year_id',
        'semester_id',
        'assessment_category',
        'assessment_sequence',
        'assessment_name',
        'score',
        'max_score',
        'weight',
        'source_type',
        'source_id',
        'assessed_date',
        'notes',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'weight' => 'decimal:2',
        'assessed_date' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Students\Student::class, 'student_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Academic\SchoolClass::class, 'class_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Academic\Semester::class, 'semester_id');
    }
}