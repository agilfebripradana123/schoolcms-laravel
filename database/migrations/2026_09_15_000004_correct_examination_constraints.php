<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 2A — Constraint correction & enum safety (runs after backfill).
 *
 * 1. question_banks.type       : ENUM -> VARCHAR(50) (future question types
 *                                without a new enum migration; values preserved)
 * 2. question_banks.difficulty : ENUM -> VARCHAR(20) (values preserved)
 * 3. question_banks.code       : UNIQUE when data is clean
 * 4. exam_results.status       : ENUM -> VARCHAR(20) (values preserved;
 *                                'finalized' becomes possible later)
 * 5. exam_results              : UNIQUE(participant_id) -> plain index (Phase 2
 *                                audit: one result per attempt); new
 *                                UNIQUE(exam_attempt_id) when safe (NULLs are
 *                                allowed by MySQL unique indexes)
 * 6. exam_participants         : last line of defense UNIQUE(exam_id, student_id)
 *                                (already present on the live dev DB; ensured)
 * 7. exam_answers              : removes legacy UNIQUE(participant_id,
 *                                question_id) under either constraint name —
 *                                the multi-attempt blocker — and ensures
 *                                UNIQUE(exam_attempt_id, question_id)
 * 8. exam_attempts.token       : UNIQUE when column/index missing and data clean
 * 9. grades                    : UNIQUE(source_type, source_id) — deterministic
 *                                result -> grade source trace
 *
 * All mutations are guarded by existence + data-cleanliness checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->enumToVarchar('question_banks', 'type', 'varchar(50)', 'multiple_choice');
        $this->enumToVarchar('question_banks', 'difficulty', 'varchar(20)', 'medium');
        $this->enumToVarchar('exam_results', 'status', 'varchar(20)', 'pending');

        $this->addCodeUniqueIfSafe();
        $this->fixExamResultsUnique();
        $this->ensureParticipantUnique();
        $this->fixExamAnswersUnique();
        $this->ensureAttemptTokenUnique();
        $this->ensureGradeSourceUnique();
    }

    public function down(): void
    {
        // Best-effort reverse. The enum->varchar conversions are intentionally
        // NOT reversed (restoring enum would re-introduce the extensibility
        // trap). Constraint rollback is guarded and idempotent.
        $this->dropIndexIfExists('grades', 'uniq_grades_source');
        $this->dropIndexIfExists('exam_attempts', 'exam_attempts_token_unique');
        $this->dropIndexIfExists('exam_answers', 'uq_exam_answers_attempt_question');
        $this->dropIndexIfExists('exam_participants', 'uq_exam_participants_exam_student');
        $this->dropIndexIfExists('exam_results', 'uniq_exam_results_attempt');
    }

    // -----------------------------------------------------------------
    // enum -> varchar (value-preserving)
    // -----------------------------------------------------------------

    private function enumToVarchar(string $table, string $column, string $type, string $default): void
    {
        if ($this->columnDataType($table, $column) !== 'enum') {
            return;
        }

        [$base, $length] = array_pad(explode('(', $type, 2), 2, null);
        $length = $length !== null ? (int) rtrim($length, ')') : null;

        Schema::table($table, function (Blueprint $t) use ($table, $column, $base, $length, $default) {
            if (Schema::hasColumn($table, $column)) {
                if ($length !== null) {
                    $t->string($column, $length)->default($default)->change();
                } else {
                    $t->string($column)->default($default)->change();
                }
            }
        });
    }

    // -----------------------------------------------------------------
    // question_banks.code unique
    // -----------------------------------------------------------------

    private function addCodeUniqueIfSafe(): void
    {
        if (! Schema::hasColumn('question_banks', 'code')) {
            return;
        }
        if ($this->indexCoversColumns('question_banks', ['code'], true)) {
            return;
        }
        $dup = DB::table('question_banks')->whereNotNull('code')
            ->selectRaw('code')->groupBy('code')->havingRaw('COUNT(*) > 1')->count();
        if ($dup > 0) {
            echo "[phase2a] SKIP question_banks.code unique: {$dup} duplicate code(s) found\n";
            return;
        }
        Schema::table('question_banks', function (Blueprint $t) {
            $t->unique('code', 'question_banks_code_unique');
        });
    }

    // -----------------------------------------------------------------
    // exam_results: drop participant unique, ensure attempt unique
    // -----------------------------------------------------------------

    private function fixExamResultsUnique(): void
    {
        // The UNIQUE(participant_id) doubles as the FK index for
        // `exam_results_participant_id_foreign`; MySQL 1553 prevents dropping
        // the unique while the FK references it. Order: drop FK -> drop unique
        // -> add plain index -> re-add FK (preserving original cascade).
        $hasFk = $this->foreignKeyExists('exam_results', 'exam_results_participant_id_foreign');
        if ($hasFk) {
            Schema::table('exam_results', function (Blueprint $t) {
                $t->dropForeign('exam_results_participant_id_foreign');
            });
        }

        foreach (['uniq_exam_results_participant', 'participant_id'] as $name) {
            if (Schema::hasIndex('exam_results', $name)) {
                Schema::table('exam_results', function (Blueprint $t) use ($name) {
                    $t->dropUnique($name);
                });
            }
        }

        if (! $this->indexCoversColumns('exam_results', ['participant_id'], false)
            && ! $this->indexCoversColumns('exam_results', ['participant_id'], true)) {
            Schema::table('exam_results', function (Blueprint $t) {
                $t->index('participant_id', 'exam_results_participant_id_index');
            });
        }

        if ($hasFk) {
            Schema::table('exam_results', function (Blueprint $t) {
                $t->foreign('participant_id', 'exam_results_participant_id_foreign')
                    ->references('id')->on('exam_participants')->onDelete('cascade');
            });
        }

        if (! Schema::hasColumn('exam_results', 'exam_attempt_id')) {
            return;
        }
        if (! $this->indexCoversColumns('exam_results', ['exam_attempt_id'], true)) {
            $dup = DB::table('exam_results')->whereNotNull('exam_attempt_id')
                ->selectRaw('exam_attempt_id')->groupBy('exam_attempt_id')->havingRaw('COUNT(*) > 1')->count();
            if ($dup > 0) {
                echo "[phase2a] SKIP exam_results UNIQUE(exam_attempt_id): {$dup} clash(es)\n";
            } else {
                Schema::table('exam_results', function (Blueprint $t) {
                    $t->unique('exam_attempt_id', 'uniq_exam_results_attempt');
                });
            }
        }
    }

    // -----------------------------------------------------------------
    // exam_participants UNIQUE(exam_id, student_id)
    // -----------------------------------------------------------------

    private function ensureParticipantUnique(): void
    {
        if ($this->indexCoversColumns('exam_participants', ['exam_id', 'student_id'], true)) {
            return;
        }
        $dup = DB::table('exam_participants')
            ->selectRaw('exam_id, student_id')->groupBy('exam_id', 'student_id')->havingRaw('COUNT(*) > 1')->count();
        if ($dup > 0) {
            echo "[phase2a] SKIP exam_participants UNIQUE(exam_id, student_id): {$dup} duplicate(s)\n";
            return;
        }
        Schema::table('exam_participants', function (Blueprint $t) {
            $t->unique(['exam_id', 'student_id'], 'uq_exam_participants_exam_student');
        });
    }

    // -----------------------------------------------------------------
    // exam_answers: drop legacy participant-question unique, ensure attempt-question unique
    // -----------------------------------------------------------------

    private function fixExamAnswersUnique(): void
    {
        foreach (['uq_exam_answers_participant_question', 'participant_id_question_id'] as $name) {
            if (Schema::hasIndex('exam_answers', $name)) {
                Schema::table('exam_answers', function (Blueprint $t) use ($name) {
                    $t->dropUnique($name);
                });
            }
        }

        if (Schema::hasColumn('exam_answers', 'exam_attempt_id')
            && ! $this->indexCoversColumns('exam_answers', ['exam_attempt_id', 'question_id'], true)) {
            $dup = DB::table('exam_answers')->whereNotNull('exam_attempt_id')
                ->selectRaw('exam_attempt_id, question_id')->groupBy('exam_attempt_id', 'question_id')->havingRaw('COUNT(*) > 1')->count();
            if ($dup > 0) {
                echo "[phase2a] SKIP exam_answers UNIQUE(exam_attempt_id, question_id): {$dup} clash(es)\n";
            } else {
                Schema::table('exam_answers', function (Blueprint $t) {
                    $t->unique(['exam_attempt_id', 'question_id'], 'uq_exam_answers_attempt_question');
                });
            }
        }
    }

    // -----------------------------------------------------------------
    // exam_attempts.token unique
    // -----------------------------------------------------------------

    private function ensureAttemptTokenUnique(): void
    {
        if (! Schema::hasColumn('exam_attempts', 'token')) {
            return;
        }
        if ($this->indexCoversColumns('exam_attempts', ['token'], true)) {
            return;
        }
        $dup = DB::table('exam_attempts')->whereNotNull('token')
            ->selectRaw('token')->groupBy('token')->havingRaw('COUNT(*) > 1')->count();
        if ($dup > 0) {
            echo "[phase2a] SKIP exam_attempts.token unique: {$dup} duplicate(s)\n";
            return;
        }
        Schema::table('exam_attempts', function (Blueprint $t) {
            $t->unique('token', 'exam_attempts_token_unique');
        });
    }

    // -----------------------------------------------------------------
    // grades UNIQUE(source_type, source_id)
    // -----------------------------------------------------------------

    private function ensureGradeSourceUnique(): void
    {
        $hasBoth = Schema::hasColumn('grades', 'source_type') && Schema::hasColumn('grades', 'source_id');
        if (! $hasBoth) {
            return;
        }
        if ($this->indexCoversColumns('grades', ['source_type', 'source_id'], true)) {
            return;
        }
        $dup = DB::table('grades')->whereNotNull('source_type')->whereNotNull('source_id')
            ->selectRaw('source_type, source_id')->groupBy('source_type', 'source_id')->havingRaw('COUNT(*) > 1')->count();
        if ($dup > 0) {
            echo "[phase2a] SKIP grades UNIQUE(source_type, source_id): {$dup} duplicate(s)\n";
            return;
        }
        Schema::table('grades', function (Blueprint $t) {
            $t->unique(['source_type', 'source_id'], 'uniq_grades_source');
        });
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function columnDataType(string $table, string $column): string
    {
        $row = DB::selectOne(
            'SELECT DATA_TYPE AS data_type FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1',
            [DB::connection()->getDatabaseName(), $table, $column]
        );

        return $row !== null ? strtolower((string) $row->data_type) : '';
    }

    private function indexCoversColumns(string $table, array $columns, bool $unique): bool
    {
        $row = DB::table('information_schema.statistics')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', $table)
            ->whereIn('column_name', $columns)
            ->where('non_unique', $unique ? 0 : 1)
            ->selectRaw('index_name')
            ->groupBy('index_name')
            ->havingRaw('COUNT(DISTINCT column_name) >= ?', [count($columns)])
            ->first();

        return $row !== null;
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (Schema::hasIndex($table, $index)) {
            Schema::table($table, function (Blueprint $t) use ($index) {
                $t->dropIndex($index);
            });
        }
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::connection()->getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};