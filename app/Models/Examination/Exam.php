<?php

namespace App\Models\Examination;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
class Exam extends Model
{
    use SoftDeletes;

    protected $table = 'exams';

    protected $fillable = [
        'subject_id',
        'class_id',
        'academic_year_id',
        'semester_id',
        'teacher_id',
        'exam_type',
        'title',
        'description',
        'instructions',
        'duration_minutes',
        'total_questions',
        'passing_score',
        'max_attempts',
        'shuffle_questions',
        'shuffle_options',
        'show_result',
        'config_snapshot',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'class_id' => 'integer',
            'academic_year_id' => 'integer',
            'semester_id' => 'integer',
            'teacher_id' => 'integer',
            'duration_minutes' => 'integer',
            'total_questions' => 'integer',
            'passing_score' => 'integer',
            'max_attempts' => 'integer',
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'show_result' => 'boolean',
            'config_snapshot' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
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

    public function schedules(): HasMany
    {
        return $this->hasMany(ExamSchedule::class, 'exam_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ExamParticipant::class, 'exam_id');
    }

    public function examQuestions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class, 'exam_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class, 'exam_id');
    }

    /**
     * State-aware teacher authorization gate (three-state model).
     *
     * - Classless exam (class_id IS NULL): subject-only scope retained — any exam
     *   in a subject the teacher is assigned to, regardless of class/year.
     * - Class-scoped exam (class_id NOT NULL): the teacher must hold a matching
     *   TeacherAssignment(teacher_id, class_id, subject_id, academic_year_id).
     *   Because TeacherAssignment.academic_year_id is NOT NULL, a class-scoped
     *   exam without an academic year can never match an assignment row, so a
     *   teacher is denied access to such exams (no fallback is invented).
     *
     * Used by all teacher examination surfaces (list and individual-exam access
     * alike) so the scope lives in exactly one place.
     */
    public function scopeTeacherAccessible(Builder $query, int $teacherId): Builder
    {
        return $query->where(function (Builder $q) use ($teacherId) {
            $q->whereNull('class_id')
                ->whereIn('subject_id', function ($sub) use ($teacherId) {
                    $sub->select('subject_id')
                        ->from('teacher_assignments')
                        ->where('teacher_id', $teacherId);
                });

            $q->orWhere(function (Builder $scoped) use ($teacherId) {
                $scoped->whereNotNull('class_id')
                    ->whereExists(function ($exists) use ($teacherId) {
                        $exists->select('id')
                            ->from('teacher_assignments')
                            ->where('teacher_assignments.teacher_id', $teacherId)
                            ->whereColumn('teacher_assignments.class_id', 'exams.class_id')
                            ->whereColumn('teacher_assignments.subject_id', 'exams.subject_id')
                            ->whereColumn('teacher_assignments.academic_year_id', 'exams.academic_year_id');
                    });
            });
        });
    }

    /**
     * Instance-level variant for already-loaded exams (single-exam gates).
     */
    public function accessibleByTeacher(int $teacherId): bool
    {
        return static::query()
            ->teacherAccessible($teacherId)
            ->whereKey($this->getKey())
            ->exists();
    }
}
