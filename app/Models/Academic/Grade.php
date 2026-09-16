<?php

namespace App\Models\Academic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

use App\Models\Students\Student;
use App\Models\System\User;
class Grade extends Model
{
    protected $table = 'grades';

    protected $fillable = [
        'student_id',
        'subject_id',
        'class_id',
        'type',
        'score',
        'semester',
        'academic_year',
        'semester_id',
        'academic_year_id',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
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

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /**
     * Assessments belonging to THIS Grade row.
     *
     * A Grade is identified by the composite key:
     *   (student_id, subject_id, class_id, academic_year_id, semester_id)
     *
     * A legacy grade row (utm/uts/uas) is the parent of every assessment that
     * shares those five identity predicates. Eloquent's hasMany supports only a
     * single foreign key, so this uses a scoped query builder instead — never
     * returning assessments that belong to another student, class, academic
     * year, semester, or subject.
     */
    public function assessmentsQuery(): QueryBuilder
    {
        return \Illuminate\Support\Facades\DB::table('grade_assessments')
            ->where('student_id', $this->student_id)
            ->where('subject_id', $this->subject_id)
            ->where('class_id', $this->class_id)
            ->where('academic_year_id', $this->academic_year_id)
            ->where('semester_id', $this->semester_id);
    }
}
