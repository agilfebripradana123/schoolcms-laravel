<?php

namespace App\Models\Examination;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Models\Academic\Subject;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\AcademicYear;
use App\Models\Academic\Semester;

/**
 * Kisi-kisi (blueprint) header (Phase 2A schema). Model-only foundation;
 * blueprint management/generation belongs to a later phase.
 */
class Blueprint extends Model
{
    protected $table = 'blueprints';

    protected $fillable = [
        'name',
        'subject_id',
        'class_id',
        'academic_year_id',
        'semester_id',
        'exam_type',
        'total_questions',
        'duration_minutes',
        'passing_score',
        'description',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'class_id' => 'integer',
            'academic_year_id' => 'integer',
            'semester_id' => 'integer',
            'total_questions' => 'integer',
            'duration_minutes' => 'integer',
            'passing_score' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BlueprintItem::class, 'blueprint_id');
    }
}