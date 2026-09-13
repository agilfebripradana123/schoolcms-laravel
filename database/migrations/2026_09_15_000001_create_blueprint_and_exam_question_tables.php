<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 2A — Additive foundation tables (blueprints + exam composition).
 *
 * Introduces the three tables that convert an Exam from "subject filter over
 * the question bank" into an explicitly composed paper:
 *
 *   blueprints (kisi-kisi)      -> exam_type / subject / class / period target
 *   blueprint_items             -> per-line competency/material/indicator + type/difficulty/count/points
 *   exam_questions              -> explicit curated composition (exam M:N question_banks)
 *
 * Constraints rationale:
 *   - exam_questions: UNIQUE(exam_id, question_id) prevents a duplicated
 *     question in the same paper; INDEX(exam_id, position) serves the ordered
 *     paper render + composition API.
 *   - exam_questions.question_id -> RESTRICT: a QuestionBank row referenced by
 *     a composition must not be hard-deleted (historical composition safety).
 *     Soft-delete (deleted_at) is unaffected by RESTRICT.
 *   - exams / question_banks are soft-deletable, so exam_id FK uses CASCADE
 *     (consistent with module convention) while the composition rows are only
 *     removed on force-delete.
 *
 * All tables are shape-aware (created only when missing) and additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blueprints')) {
            Schema::create('blueprints', function (Blueprint $table) {
                $table->id();
                $table->string('name', 200);
                $table->unsignedInteger('subject_id');
                $table->unsignedInteger('class_id')->nullable();
                $table->unsignedInteger('academic_year_id')->nullable();
                $table->unsignedInteger('semester_id')->nullable();
                $table->string('exam_type', 50)->default('other');
                $table->unsignedInteger('total_questions')->default(0);
                $table->unsignedInteger('duration_minutes')->nullable();
                $table->unsignedInteger('passing_score')->nullable();
                $table->text('description')->nullable();
                $table->string('status', 20)->default('draft');
                $table->timestamps();

                $table->index('subject_id', 'idx_blueprints_subject_id');
                $table->index('status', 'idx_blueprints_status');
                $table->foreign('subject_id', 'fk_blp_subject')
                    ->references('id')->on('subjects')->onDelete('cascade');
                $table->foreign('class_id', 'fk_blp_class')
                    ->references('id')->on('classes')->onDelete('set null');
                $table->foreign('academic_year_id', 'fk_blp_academic_year')
                    ->references('id')->on('academic_years')->onDelete('set null');
                $table->foreign('semester_id', 'fk_blp_semester')
                    ->references('id')->on('semesters')->onDelete('set null');
            });
        }

        if (! Schema::hasTable('blueprint_items')) {
            Schema::create('blueprint_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('blueprint_id');
                $table->text('competency')->nullable();
                $table->text('material')->nullable();
                $table->text('indicator')->nullable();
                $table->string('question_type', 50);
                $table->string('difficulty', 20)->default('medium');
                $table->unsignedInteger('count')->default(1);
                $table->unsignedInteger('points')->default(1);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->index('blueprint_id', 'idx_blueprint_items_blueprint_id');
                $table->index(['blueprint_id', 'position'], 'idx_blueprint_items_blueprint_position');
                $table->foreign('blueprint_id', 'fk_blp_item_blueprint')
                    ->references('id')->on('blueprints')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('exam_questions')) {
            Schema::create('exam_questions', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('exam_id');
                $table->unsignedInteger('question_id');
                $table->unsignedBigInteger('blueprint_item_id')->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->unsignedInteger('points')->default(1);
                $table->timestamps();

                $table->unique(['exam_id', 'question_id'], 'uq_exam_questions_exam_question');
                $table->index(['exam_id', 'position'], 'idx_exam_questions_exam_position');
                $table->index('question_id', 'idx_exam_questions_question_id');
                $table->foreign('exam_id', 'fk_exq_exam')
                    ->references('id')->on('exams')->onDelete('cascade');
                $table->foreign('question_id', 'fk_exq_question')
                    ->references('id')->on('question_banks')->onDelete('restrict');
                $table->foreign('blueprint_item_id', 'fk_exq_blueprint_item')
                    ->references('id')->on('blueprint_items')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_questions');
        Schema::dropIfExists('blueprint_items');
        Schema::dropIfExists('blueprints');
    }
};