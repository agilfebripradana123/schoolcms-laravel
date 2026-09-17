<?php

namespace App\Models\Students;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\SchoolClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentHistory extends Model
{
    protected $table = 'student_histories';

    protected $fillable = [
        'student_id',
        'class_id',
        'academic_year_id',
        'status',
        'notes',
        'is_final',
        'finalized_at',
        'finalized_by',
    ];

    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'class_id' => 'integer',
            'academic_year_id' => 'integer',
            'is_final' => 'boolean',
            'finalized_at' => 'datetime',
            'finalized_by' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }
}
