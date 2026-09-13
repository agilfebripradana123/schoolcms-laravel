<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — Secure Web Exam foundation.
 *
 * Adds a per-attempt session entity (`exam_attempts`) alongside the existing
 * time-slot `exam_sessions` entity, plus an audit/event table
 * (`exam_attempt_events`).
 *
 * Rationale (audit-driven): the pre-existing `exam_sessions` table represents
 * a schedule TIME-SLOT (name/start_time/end_time) referenced by
 * `exam_schedules.session_id` and must NOT be repurposed. A secure exam
 * attempt (student + attempt_number + server timer + persistent random order)
 * therefore needs its own table.
 *
 * PHASE 2A (shape-aware): the shared dev database already holds an
 * `exam_attempts` table that was created outside the migration system while
 * this migration remained pending. To keep `php artisan migrate` idempotent on
 * such divergent databases, up() now creates the tables when missing and, when
 * they already exist, only ensures the missing columns/indexes/foreign keys.
 * Fresh installs are unaffected (tables missing -> full create).
 *
 * PHASE 2A FK TYPE FIX: `exam_attempts.exam_participant_id` and
 * `exam_attempts.exam_id` are declared INT UNSIGNED to match
 * `exam_participants.id` / `exams.id` (INT UNSIGNED). The original BIGINT
 * declaration was incompatible with the parent key types (MySQL error 3780),
 * which is why the live table could never carry its FKs (and why the guarded
 * migration would also have failed on a fresh MySQL install). Existing
 * columns of the wrong type are safely altered (data-preserving) before the
 * FKs are applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exam_attempts')) {
            Schema::create('exam_attempts', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('exam_participant_id');
                $table->unsignedInteger('exam_id');
                $table->unsignedInteger('attempt_number');
                $table->string('status')->default('active');
                $table->dateTime('started_at')->nullable();
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('submitted_at')->nullable();
                $table->json('question_order')->nullable();
                $table->json('option_order')->nullable();
                $table->string('token', 64)->nullable()->unique();
                $table->timestamps();

                $table->unique(['exam_participant_id', 'attempt_number'], 'uq_exam_attempts_participant_attempt');
                $table->index('exam_id', 'idx_exam_attempts_exam_id');
                $table->index('status', 'idx_exam_attempts_status');
                $table->foreign('exam_participant_id', 'fk_att_participant')
                    ->references('id')->on('exam_participants')->onDelete('cascade');
                $table->foreign('exam_id', 'fk_att_exam')
                    ->references('id')->on('exams')->onDelete('cascade');
            });
        } else {
            $this->ensureAttemptSchema();
        }

        if (! Schema::hasTable('exam_attempt_events')) {
            Schema::create('exam_attempt_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('exam_attempt_id');
                $table->string('event_type');
                $table->json('metadata')->nullable();
                $table->dateTime('occurred_at');
                $table->timestamps();

                $table->index('event_type', 'idx_att_events_event_type');
                $table->index('occurred_at', 'idx_att_events_occurred_at');
                $table->index('exam_attempt_id', 'idx_att_events_attempt_id');
                $table->foreign('exam_attempt_id', 'fk_att_events_attempt')
                    ->references('id')->on('exam_attempts')->onDelete('cascade');
            });
        } else {
            $this->ensureEventSchema();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempt_events');
        Schema::dropIfExists('exam_attempts');
    }

    /**
     * Reconcile a pre-existing `exam_attempts` table (Phase 2A).
     */
    private function ensureAttemptSchema(): void
    {
        // Align FK-anchor columns with their INT UNSIGNED parent keys (Phase 2A
        // FK type fix). Data-preserving ALTER; safe because the values fit and
        // the table is empty (or small) at this stage of the module.
        if ($this->columnDataType('exam_attempts', 'exam_participant_id') === 'bigint') {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->unsignedInteger('exam_participant_id')->change();
            });
        }
        if ($this->columnDataType('exam_attempts', 'exam_id') === 'bigint') {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->unsignedInteger('exam_id')->change();
            });
        }

        if (! Schema::hasColumn('exam_attempts', 'token')) {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->string('token', 64)->nullable();
            });
        }
        if (! Schema::hasIndex('exam_attempts', 'exam_attempts_token_unique')
            && ! $this->indexExists('exam_attempts', ['token'], true)) {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->unique('token', 'exam_attempts_token_unique');
            });
        }
        if (! Schema::hasIndex('exam_attempts', 'uq_exam_attempts_participant_attempt')) {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->unique(['exam_participant_id', 'attempt_number'], 'uq_exam_attempts_participant_attempt');
            });
        }
        if (! Schema::hasIndex('exam_attempts', 'idx_exam_attempts_exam_id')) {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->index('exam_id', 'idx_exam_attempts_exam_id');
            });
        }
        if (! Schema::hasIndex('exam_attempts', 'idx_exam_attempts_status')) {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->index('status', 'idx_exam_attempts_status');
            });
        }
        if (! $this->foreignKeyExists('exam_attempts', 'fk_att_participant')) {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->foreign('exam_participant_id', 'fk_att_participant')
                    ->references('id')->on('exam_participants')->onDelete('cascade');
            });
        }
        if (! $this->foreignKeyExists('exam_attempts', 'fk_att_exam')) {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->foreign('exam_id', 'fk_att_exam')
                    ->references('id')->on('exams')->onDelete('cascade');
            });
        }
    }

    /**
     * Reconcile a pre-existing `exam_attempt_events` table (Phase 2A).
     */
    private function ensureEventSchema(): void
    {
        if (! Schema::hasIndex('exam_attempt_events', 'idx_att_events_event_type')) {
            Schema::table('exam_attempt_events', function (Blueprint $table) {
                $table->index('event_type', 'idx_att_events_event_type');
            });
        }
        if (! Schema::hasIndex('exam_attempt_events', 'idx_att_events_occurred_at')) {
            Schema::table('exam_attempt_events', function (Blueprint $table) {
                $table->index('occurred_at', 'idx_att_events_occurred_at');
            });
        }
        if (! Schema::hasIndex('exam_attempt_events', 'idx_att_events_attempt_id')) {
            Schema::table('exam_attempt_events', function (Blueprint $table) {
                $table->index('exam_attempt_id', 'idx_att_events_attempt_id');
            });
        }
        if (! $this->foreignKeyExists('exam_attempt_events', 'fk_att_events_attempt')) {
            Schema::table('exam_attempt_events', function (Blueprint $table) {
                $table->foreign('exam_attempt_id', 'fk_att_events_attempt')
                    ->references('id')->on('exam_attempts')->onDelete('cascade');
            });
        }
    }

    private function indexExists(string $table, array $columns, bool $unique): bool
    {
        $row = DB::table('information_schema.statistics')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', $table)
            ->whereIn('column_name', $columns)
            ->where('non_unique', $unique ? 0 : 1)
            ->selectRaw('index_name, COUNT(DISTINCT column_name) AS n, COUNT(*) AS cols')
            ->groupBy('index_name')
            ->havingRaw('COUNT(DISTINCT column_name) = ?', [count($columns)])
            ->first();

        return $row !== null;
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