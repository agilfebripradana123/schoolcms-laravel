<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('grade_assessments')) {
            return;
        }

        if (!Schema::hasTable('grades')) {
            return;
        }

        $conn = DB::connection();

        $unresolved = (int) $conn->table('grade_assessments')
            ->where(function ($q) {
                $q->whereNull('academic_year_id')->orWhereNull('semester_id');
            })
            ->count();

        if ($unresolved === 0) {
            return;
        }

        // Process in batches to avoid locking the table.
        $conn->table('grade_assessments')
            ->where(function ($q) {
                $q->whereNull('academic_year_id')->orWhereNull('semester_id');
            })
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($conn) {
                foreach ($rows as $row) {
                    $grade = $conn->table('grades')
                        ->where('student_id', $row->student_id)
                        ->where('subject_id', $row->subject_id)
                        ->where('class_id', $row->class_id)
                        ->where('type', $row->assessment_category)
                        ->first();

                    if (! $grade) {
                        throw new RuntimeException(
                            "Period reconciliation failed: assessment id {$row->id} has no "
                            . "matching Grade row."
                        );
                    }

                    $conn->table('grade_assessments')
                        ->where('id', $row->id)
                        ->where(function ($q) {
                            $q->whereNull('academic_year_id')->orWhereNull('semester_id');
                        })
                        ->update([
                            'academic_year_id' => $grade->academic_year_id,
                            'semester_id' => $grade->semester_id,
                        ]);
                }
            });

        // Verify completeness.
        $remaining = (int) $conn->table('grade_assessments')
            ->where(function ($q) {
                $q->whereNull('academic_year_id')->orWhereNull('semester_id');
            })
            ->count();

        if ($remaining > 0) {
            throw new RuntimeException(
                "Period reconciliation incomplete: {$remaining} assessment(s) could not be "
                . "matched to a Grade row."
            );
        }
    }

    public function down(): void
    {
        // Reconciliation is additive and irreversible: the original NULL values
        // cannot be distinguished from other NULLs, so we do NOT reset them.
        // No-op down follows repository convention for additive-only data fixes.
    }
};
