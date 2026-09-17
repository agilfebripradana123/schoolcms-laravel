<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 2M-2D — StudentHistory outcome finalization foundation.
 *
 * Additive lock columns mirroring the existing grades.is_final/
 * finalized_at/finalized_by convention:
 *   is_final      boolean NOT NULL default false
 *   finalized_at  nullable datetime
 *   finalized_by  nullable user reference (INT UNSIGNED, soft-coupled like
 *                 grades.finalized_by — no hard FK to match repo convention)
 *
 * The existing annual unique constraint (student_id, academic_year_id) is
 * preserved untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_histories', function (Blueprint $t) {
            if (! Schema::hasColumn('student_histories', 'is_final')) {
                $t->boolean('is_final')->default(false);
            }
            if (! Schema::hasColumn('student_histories', 'finalized_at')) {
                $t->dateTime('finalized_at')->nullable();
            }
            if (! Schema::hasColumn('student_histories', 'finalized_by')) {
                $t->unsignedInteger('finalized_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_histories', function (Blueprint $t) {
            if (Schema::hasColumn('student_histories', 'finalized_by')) {
                $t->dropColumn('finalized_by');
            }
            if (Schema::hasColumn('student_histories', 'finalized_at')) {
                $t->dropColumn('finalized_at');
            }
            if (Schema::hasColumn('student_histories', 'is_final')) {
                $t->dropColumn('is_final');
            }
        });
    }
};
