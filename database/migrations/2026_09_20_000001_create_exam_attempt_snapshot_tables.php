<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 2G — Attempt snapshot (historical integrity).
 *
 * Captures the exact question/option/points/ordering an attempt used at the
 * moment it started, so later QuestionBank edits, option replacement, point
 * changes, archiving or soft deletion never mutate a running/finished attempt.
 *
 * Relational snapshot (deliberately separate from live master data):
 *   exam_attempts
 *     └── exam_attempt_questions          (snapshot of one question used)
 *           └── exam_attempt_question_options  (snapshot of one option used)
 *
 * Design rules:
 *   - `source_question_id` / `source_option_id` are plain nullable reference
 *     columns WITHOUT foreign keys: snapshot rows survive hard deletion of the
 *     source QuestionBank/QuestionOption. Soft-delete/archive keep the row and
 *     the reference intact.
 *   - `exam_attempt_question_options.is_correct` is grading truth, copied at
 *     snapshot time. It is NEVER delivered to students (sanitized resources).
 *   - `exam_attempt_questions.points` / `.position` freeze the weight and the
 *     actual per-attempt order (shuffle already applied).
 *   - `exam_answers` gains `attempt_question_id` (snapshot question identity)
 *     and `selected_attempt_option_id` (snapshot option identity). Legacy
 *     columns `question_id` / `selected_option_id` stay for historical/legacy
 *     rows and are nullable on new rows.
 *
 * Shape-aware: guarded by column/index/FK existence checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exam_attempt_questions')) {
            Schema::create('exam_attempt_questions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('exam_attempt_id');
                $table->unsignedInteger('source_question_id')->nullable();
                $table->string('question_code', 50)->nullable();
                $table->text('question_text');
                $table->string('question_type', 50);
                $table->unsignedInteger('points')->default(1);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->index('exam_attempt_id', 'idx_att_q_attempt');
                $table->unique(['exam_attempt_id', 'position'], 'uq_att_q_attempt_position');
                $table->index('source_question_id', 'idx_att_q_source');
                $table->foreign('exam_attempt_id', 'fk_att_q_attempt')
                    ->references('id')->on('exam_attempts')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('exam_attempt_question_options')) {
            Schema::create('exam_attempt_question_options', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('attempt_question_id');
                $table->unsignedInteger('source_option_id')->nullable();
                $table->text('option_text');
                $table->string('option_image', 500)->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->boolean('is_correct')->default(false);
                $table->timestamps();

                $table->index('attempt_question_id', 'idx_att_qo_attempt_question');
                $table->unique(['attempt_question_id', 'position'], 'uq_att_qo_attempt_question_position');
                $table->index('source_option_id', 'idx_att_qo_source');
                $table->foreign('attempt_question_id', 'fk_att_qo_attempt_question')
                    ->references('id')->on('exam_attempt_questions')->onDelete('cascade');
            });
        }

        // Attempt answers now point at snapshot identities (additive, legacy-safe).
        Schema::table('exam_answers', function (Blueprint $table) {
            if (! Schema::hasColumn('exam_answers', 'attempt_question_id')) {
                $table->unsignedBigInteger('attempt_question_id')->nullable()->after('exam_attempt_id');
            }
            if (! Schema::hasColumn('exam_answers', 'selected_attempt_option_id')) {
                $table->unsignedBigInteger('selected_attempt_option_id')->nullable()->after('selected_option_id');
            }
        });

        if (! $this->indexCoversColumns('exam_answers', ['attempt_question_id'], false)) {
            Schema::table('exam_answers', function (Blueprint $table) {
                $table->index('attempt_question_id', 'idx_exam_answers_att_q');
            });
        }
        if (! $this->indexCoversColumns('exam_answers', ['selected_attempt_option_id'], false)) {
            Schema::table('exam_answers', function (Blueprint $table) {
                $table->index('selected_attempt_option_id', 'idx_exam_answers_att_opt');
            });
        }
        if (! $this->indexCoversColumns('exam_answers', ['exam_attempt_id', 'attempt_question_id'], true)) {
            Schema::table('exam_answers', function (Blueprint $table) {
                $table->unique(['exam_attempt_id', 'attempt_question_id'], 'uq_exam_answers_att_q_snapshot');
            });
        }
        if (! $this->foreignKeyExists('exam_answers', 'fk_ans_att_question') && Schema::hasColumn('exam_answers', 'attempt_question_id')) {
            Schema::table('exam_answers', function (Blueprint $table) {
                $table->foreign('attempt_question_id', 'fk_ans_att_question')
                    ->references('id')->on('exam_attempt_questions')->onDelete('cascade');
            });
        }
        if (! $this->foreignKeyExists('exam_answers', 'fk_ans_att_option') && Schema::hasColumn('exam_answers', 'selected_attempt_option_id')) {
            Schema::table('exam_answers', function (Blueprint $table) {
                $table->foreign('selected_attempt_option_id', 'fk_ans_att_option')
                    ->references('id')->on('exam_attempt_question_options')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::table('exam_answers', function (Blueprint $table) {
            if (Schema::hasColumn('exam_answers', 'selected_attempt_option_id')) {
                $table->dropForeign('fk_ans_att_option');
                $table->dropIndex('idx_exam_answers_att_opt');
                $table->dropColumn('selected_attempt_option_id');
            }
            if (Schema::hasColumn('exam_answers', 'attempt_question_id')) {
                $table->dropForeign('fk_ans_att_question');
                $table->dropIndex('idx_exam_answers_att_q');
                $table->dropUnique('uq_exam_answers_att_q_snapshot');
                $table->dropColumn('attempt_question_id');
            }
        });

        Schema::dropIfExists('exam_attempt_question_options');
        Schema::dropIfExists('exam_attempt_questions');
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