<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 2J — Grade finalization foundation.
 *
 * 1. grades.type ENUM('tugas','uts','uas') -> VARCHAR(20), value-preserving.
 *    Future assessment types must not require an ENUM migration; existing
 *    values (tugas/uts/uas) are untouched. No NEW types are introduced here.
 * 2. Additive finalization (lock) columns — mirrors the existing
 *    exam_results.is_final/finalized_at convention and is the authoritative
 *    academic-grade lock:
 *      is_final      boolean NOT NULL default false
 *      finalized_at  nullable datetime
 *      finalized_by  nullable user reference (INT UNSIGNED, soft-coupled like
 *                    owner_id/graded_by elsewhere in the codebase — users.id
 *                    differs between guarded (BIGINT) and migrated (INT)
 *                    databases, so no hard FK is added).
 *
 * Shape-aware; no data destroyed; existing indexes/uniques untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ENUM -> VARCHAR (value-preserving).
        if ($this->columnDataType('grades', 'type') === 'enum') {
            Schema::table('grades', function (Blueprint $t) {
                $t->string('type', 20)->change();
            });
        }

        Schema::table('grades', function (Blueprint $t) {
            if (! Schema::hasColumn('grades', 'is_final')) {
                $t->boolean('is_final')->default(false);
            }
            if (! Schema::hasColumn('grades', 'finalized_at')) {
                $t->dateTime('finalized_at')->nullable();
            }
            if (! Schema::hasColumn('grades', 'finalized_by')) {
                $t->unsignedInteger('finalized_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $t) {
            if (Schema::hasColumn('grades', 'finalized_by')) {
                $t->dropColumn('finalized_by');
            }
            if (Schema::hasColumn('grades', 'finalized_at')) {
                $t->dropColumn('finalized_at');
            }
            if (Schema::hasColumn('grades', 'is_final')) {
                $t->dropColumn('is_final');
            }
        });

        // Restore ENUM only when every value is still in the legacy set.
        if ($this->columnDataType('grades', 'type') === 'varchar') {
            $bad = DB::table('grades')->whereNotIn('type', ['tugas', 'uts', 'uas'])->count();
            if ($bad === 0) {
                Schema::table('grades', function (Blueprint $t) {
                    $t->enum('type', ['tugas', 'uts', 'uas'])->change();
                });
            }
        }
    }

    private function columnDataType(string $table, string $column): string
    {
        $row = DB::selectOne(
            'SELECT DATA_TYPE AS data_type FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1',
            [DB::connection()->getDatabaseName(), $table, $column]
        );

        return $row !== null ? strtolower((string) $row->data_type) : '';
    }
};