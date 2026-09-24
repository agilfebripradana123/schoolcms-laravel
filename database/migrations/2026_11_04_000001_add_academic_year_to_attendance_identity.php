<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-A05 (DB-2): bind student attendance to its academic context.
 *
 * The `attendances` table previously had no academic-year dimension and no
 * uniqueness, so:
 *   - the same (student, class, date) could be recorded repeatedly,
 *   - a student history / report aggregated every year together, and
 *   - teacher `updateOrCreate(student, class, date)` could silently overwrite
 *     a record belonging to another academic year.
 *
 * Business key (one daily record per student/class/date/year):
 *
 *   UNIQUE (student_id, class_id, date, academic_year_id)
 *   name:   uniq_attendance_slot
 *
 * `semester_id` is intentionally NOT added: attendance is a per-day record
 * (one row per student/class/date), and no existing attendance dimension is
 * semester-scoped — `class_students`, `teacher_assignments` and the daily
 * roster are all year-scoped only. Date already resolves the semester; adding
 * semester_id would create identity ambiguity without changing behavior.
 *
 * `academic_year_id` stays nullable: a small number of legacy rows may have no
 * resolvable `class_students` membership (confirmed: 1 orphan row in the dev
 * DB). The backfill below resolves every row it can from the authoritative
 * membership record; unresolved rows are preserved with NULL rather than
 * deleted. New writes are enforced non-null by the application layer.
 *
 * FK uses ON DELETE RESTRICT (matching `grades`/`report_cards`): deleting an
 * academic year that still has attendance records must fail loudly rather than
 * silently remove attendance history (class/student rows are CASCADE by the
 * original table — the year is the only non-recoverable context).
 *
 * Existing-data safety: only 1 attendance row exists in the dev DB and it is
 * unresolvable, so the new unique index cannot collide with pre-existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedInteger('academic_year_id')->after('class_id')->nullable();
        });

        DB::statement(
            'UPDATE `attendances` a '
            . 'LEFT JOIN `class_students` cs '
            . '  ON cs.student_id = a.student_id '
            . ' AND cs.class_id = a.class_id '
            . ' AND cs.status = \'active\' '
            . 'SET a.academic_year_id = cs.academic_year_id '
            . 'WHERE a.academic_year_id IS NULL'
        );

        Schema::table('attendances', function (Blueprint $table) {
            $table->index('academic_year_id', 'idx_att_ay');
            $table->foreign('academic_year_id', 'fk_att_ay')
                ->references('id')
                ->on('academic_years')
                ->onDelete('restrict');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->unique(
                ['student_id', 'class_id', 'date', 'academic_year_id'],
                'uniq_attendance_slot'
            );
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('uniq_attendance_slot');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign('fk_att_ay');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('academic_year_id');
        });
    }
};