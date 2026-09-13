<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — Secure Web Exam autosave foundation.
 *
 * Links exam answers to a specific attempt and guarantees idempotency:
 *   - one answer per (exam_attempt_id, question_id) -> idempotent autosave
 *
 * PHASE 2A CHANGES:
 *  1. Shape-aware: the shared dev database was created outside the migration
 *     system, so column/index/FK additions are guarded and only applied when
 *     missing. Fresh installs are unaffected (missing -> full apply).
 *  2. The legacy UNIQUE(participant_id, question_id) is intentionally NO LONGER
 *     created. Phase 2 audit flagged it as INCORRECT DESIGN: it blocks a
 *     participant from answering the same question on a second attempt
 *     (multi-attempt exams), which violates the target UNIQUE(exam_attempt_id,
 *     question_id). Any pre-existing copy of that constraint is removed by the
 *     2026_09_15_000004_correct_examination_constraints migration.
 *
 * NULL exam_attempt_id is permitted for legacy/admin-created answers; because
 * unique indexes allow multiple NULLs, historical rows remain untouched while
 * attempt-scoped answers are enforced unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('exam_answers', 'exam_attempt_id')) {
            Schema::table('exam_answers', function (Blueprint $table) {
                $table->unsignedBigInteger('exam_attempt_id')->nullable()->after('id');
                $table->index('exam_attempt_id', 'idx_exam_answers_attempt_id');
                $table->foreign('exam_attempt_id', 'fk_exam_ans_attempt')
                    ->references('id')->on('exam_attempts')->onDelete('cascade');
                $table->unique(['exam_attempt_id', 'question_id'], 'uq_exam_answers_attempt_question');
            });
        } else {
            $this->ensureAnswerSchema();
        }
    }

    public function down(): void
    {
        Schema::table('exam_answers', function (Blueprint $table) {
            $table->dropUnique('uq_exam_answers_attempt_question');
            $table->dropUnique('uq_exam_answers_participant_question');
            $table->dropForeign('fk_exam_ans_attempt');
            $table->dropIndex('idx_exam_answers_attempt_id');
            $table->dropColumn('exam_attempt_id');
        });
    }

    /**
     * Reconcile a pre-existing `exam_answers` table (Phase 2A).
     */
    private function ensureAnswerSchema(): void
    {
        if (! Schema::hasIndex('exam_answers', 'idx_exam_answers_attempt_id')) {
            Schema::table('exam_answers', function (Blueprint $table) {
                $table->index('exam_attempt_id', 'idx_exam_answers_attempt_id');
            });
        }
        if (! $this->foreignKeyExists('exam_answers', 'fk_exam_ans_attempt')) {
            Schema::table('exam_answers', function (Blueprint $table) {
                $table->foreign('exam_attempt_id', 'fk_exam_ans_attempt')
                    ->references('id')->on('exam_attempts')->onDelete('cascade');
            });
        }
        if (! Schema::hasIndex('exam_answers', 'uq_exam_answers_attempt_question')) {
            $this->addAttemptQuestionUniqueIfSafe();
        }
    }

    private function addAttemptQuestionUniqueIfSafe(): void
    {
        $duplicates = DB::table('exam_answers')
            ->whereNotNull('exam_attempt_id')
            ->selectRaw('exam_attempt_id, question_id')
            ->groupBy('exam_attempt_id', 'question_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicates === 0) {
            Schema::table('exam_answers', function (Blueprint $table) {
                $table->unique(['exam_attempt_id', 'question_id'], 'uq_exam_answers_attempt_question');
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