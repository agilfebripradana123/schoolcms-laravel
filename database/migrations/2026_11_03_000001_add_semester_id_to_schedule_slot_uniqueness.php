<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B-A03 (DB-1): scope schedule slot uniqueness per semester.
 *
 * Old: UNIQUE (class_id, day, period_id, academic_year_id)
 * New: UNIQUE (class_id, day, period_id, academic_year_id, semester_id)
 *
 * The same class/day/period slot may now exist once per semester of an
 * academic year (Ganjil / Genap), while true duplicates inside one semester
 * remain rejected.
 *
 * The swap is a single atomic ALTER (DROP INDEX + ADD UNIQUE in one
 * statement): `uniq_schedules_slot` currently backs the `fk_sc_class` foreign
 * key because `class_id` is its leftmost column, and MySQL refuses to drop an
 * index a FK depends on (error 1553) unless the resulting statement still
 * exposes a matching leftmost-prefix index. The new key keeps `class_id` as
 * its first column, so the FK requirement is satisfied by the post-statement
 * state and the swap is legal in one step.
 *
 * NULL policy: `semester_id` stays nullable and no schema workaround is
 * introduced. As with any MySQL UNIQUE index, rows where `semester_id` is
 * NULL are considered distinct, so multiple semester-less schedules may
 * legally occupy the same slot. This preserves the nullable application
 * contract (such rows were previously deduplicated by the old key; they are
 * now explicitly allowed and no new business rule is invented here). Guarded
 * by the FormRequest unique rule being skipped whenever `semester_id` is NULL.
 *
 * Existing-data safety: the old key is a strict prefix of the new key, so any
 * rows that satisfied the old constraint cannot collide on the new index. No
 * data migration or preflight cleanup is required.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE `schedules` DROP INDEX `uniq_schedules_slot`, '
            . 'ADD UNIQUE KEY `uniq_schedules_slot` '
            . '(`class_id`,`day`,`period_id`,`academic_year_id`,`semester_id`)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE `schedules` DROP INDEX `uniq_schedules_slot`, '
            . 'ADD UNIQUE KEY `uniq_schedules_slot` '
            . '(`class_id`,`day`,`period_id`,`academic_year_id`)'
        );
    }
};