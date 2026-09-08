<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — Secure Web Exam autosave foundation.
 *
 * Links exam answers to a specific attempt and guarantees idempotency:
 *   - one answer per (exam_attempt_id, question_id) -> idempotent autosave
 *   - one answer per (participant_id, question_id)  -> no duplicate answers
 *
 * NULL exam_attempt_id is permitted for legacy/admin-created answers; because
 * unique indexes allow multiple NULLs, historical rows remain untouched while
 * attempt-scoped answers are enforced unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_answers', function (Blueprint $table) {
            $table->unsignedBigInteger('exam_attempt_id')->nullable()->after('id');
            $table->index('exam_attempt_id', 'idx_exam_answers_attempt_id');
            $table->foreign('exam_attempt_id', 'fk_exam_ans_attempt')
                ->references('id')->on('exam_attempts')->onDelete('cascade');
            $table->unique(['exam_attempt_id', 'question_id'], 'uq_exam_answers_attempt_question');
            $table->unique(['participant_id', 'question_id'], 'uq_exam_answers_participant_question');
        });
    }

    public function down(): void
    {
        Schema::table('exam_answers', function (Blueprint $table) {
            $table->dropUnique('uq_exam_answers_participant_question');
            $table->dropUnique('uq_exam_answers_attempt_question');
            $table->dropForeign('fk_exam_ans_attempt');
            $table->dropIndex('idx_exam_answers_attempt_id');
            $table->dropColumn('exam_attempt_id');
        });
    }
};
