<?php

use App\Models\Academic\Grade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrate existing Grade records to GradeAssessment.
 *
 * Map: type → assessment_category
 *      tugas → tugas
 *      uts   → uts
 *      uas   → uas
 *
 * assessment_sequence = 1 (first assessment of that category)
 *
 * Idempotent: skips if assessment already exists by (student, subject, class, category, sequence).
 * Does NOT delete, update, or change Grade rows or semantics.
 *
 * Current DB note: grades table has academic_year (string) and semester (string)
 * but not academic_year_id/semester_id integer FKs. Migrate with NULL FK values;
 * they can be resolved later when add_period_ids_to_grades_table runs.
 *
 * Expected: 84 grades → 84 grade_assessments.
 */
class MigrateGradesToAssessments extends Migration
{
    public function up(): void
    {
        $grades = Grade::all();

        foreach ($grades as $grade) {
            // Idempotency: check if assessment already exists
            // Use the columns that exist in grade_assessments
            $exists = DB::table('grade_assessments')
                ->where('student_id', $grade->student_id)
                ->where('subject_id', $grade->subject_id)
                ->where('class_id', $grade->class_id)
                ->where('assessment_category', $grade->type)
                ->where('assessment_sequence', 1)
                ->exists();

            if ($exists) {
                continue; // Skip already-migrated row
            }

            // Resolve academic_year_id from string
            $academicYearId = DB::table('academic_years')
                ->where('name', $grade->academic_year)
                ->value('id');

            // Resolve semester_id from string + academic_year_id
            $semesterId = DB::table('semesters')
                ->where('name', $grade->semester)
                ->where('academic_year_id', $academicYearId)
                ->value('id');

            // Skip if cannot resolve period IDs
            if (!$academicYearId || !$semesterId) {
                continue;
            }

            DB::table('grade_assessments')->insert([
                'student_id' => $grade->student_id,
                'subject_id' => $grade->subject_id,
                'class_id' => $grade->class_id,
                'academic_year_id' => $academicYearId,
                'semester_id' => $semesterId,
                'assessment_category' => $grade->type,
                'assessment_sequence' => 1,
                'score' => $grade->score,
                'max_score' => 100.00,
                'source_type' => $grade->source_type,
                'source_id' => $grade->source_id,
                'assessed_date' => null, // no legacy assessed_date available
                'notes' => 'Migrated from legacy Grade table',
                'created_at' => $grade->created_at,
                'updated_at' => $grade->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        // Do NOT delete assessment rows; Grade rows remain intact
        // This migration is additive only
    }
}