<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 2A — Additive foundation columns (no data touched yet).
 *
 * Adds nullable/foundation-aware columns to existing Examination tables plus
 * `grades` source tracking. Every addition is guarded by column/FK existence so
 * the migration is safe on both fresh installs and the divergent dev database.
 *
 * Column typing follows the repository convention:
 *   - references to `users`/`exam_attempts` use BIGINT (those tables use
 *     bigint auto-increment ids);
 *   - references to examination/academic tables use INT UNSIGNED.
 *
 * FKs on new columns use ON DELETE SET NULL (nullable ownership/schedule refs)
 * or CASCADE (result -> attempt, because an attempt row owns its result).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addQuestionBankColumns();
        $this->addExamColumns();
        $this->addExamScheduleColumns();
        $this->addExamParticipantColumns();
        $this->addExamAttemptColumns();
        $this->addExamAnswerColumns();
        $this->addExamResultColumns();
        $this->addGradeColumns();
    }

    public function down(): void
    {
        // Best-effort symmetric removal (all guarded; safe when columns missing).
        $this->dropColumns('grades', ['source_type', 'source_id']);

        $this->dropColumns('exam_results', [
            'exam_attempt_id', 'raw_score', 'weighted_score', 'percentage',
            'passed', 'duration_seconds', 'is_final', 'finalized_at',
        ]);
        $this->dropColumns('exam_answers', ['score', 'feedback', 'grade_status', 'graded_by', 'graded_at']);
        Schema::table('exam_attempts', function (Blueprint $t) {
            if (Schema::hasColumn('exam_attempts', 'snapshot')) {
                $t->dropColumn('snapshot');
            }
        });
        $this->dropColumns('exam_participants', ['schedule_id', 'attendance']);
        $this->dropColumns('exam_schedules', ['supervisor_id', 'start_datetime', 'end_datetime']);
        $this->dropColumns('exams', [
            'class_id', 'academic_year_id', 'semester_id', 'teacher_id',
            'exam_type', 'instructions', 'config_snapshot',
        ]);
        $this->dropColumns('question_banks', [
            'code', 'owner_id', 'status', 'cognitive_level',
            'competency', 'indicator', 'audio_url', 'video_url',
        ]);
    }

    // -----------------------------------------------------------------
    // question_banks
    // -----------------------------------------------------------------

    private function addQuestionBankColumns(): void
    {
        $add = fn (callable $callback) => Schema::table('question_banks', $callback);

        if (! Schema::hasColumn('question_banks', 'code')) {
            $add(function (Blueprint $t) {
                $t->string('code', 50)->nullable()->after('id');
            });
        }
        foreach (['status', 'cognitive_level'] as $col) {
            if (! Schema::hasColumn('question_banks', $col)) {
                $add(function (Blueprint $t) use ($col) {
                    $t->string($col, 20)->nullable();
                });
            }
        }
        foreach (['competency', 'indicator'] as $col) {
            if (! Schema::hasColumn('question_banks', $col)) {
                $add(function (Blueprint $t) use ($col) {
                    $t->text($col)->nullable();
                });
            }
        }
        foreach (['audio_url', 'video_url'] as $col) {
            if (! Schema::hasColumn('question_banks', $col)) {
                $add(function (Blueprint $t) use ($col) {
                    $t->string($col, 500)->nullable();
                });
            }
        }
        if (! Schema::hasColumn('question_banks', 'owner_id')) {
            $add(function (Blueprint $t) {
                $t->unsignedInteger('owner_id')->nullable();
            });
        }
        // NOTE: no FK on owner_id. users.id is INT UNSIGNED on the live/datamigrated
        // DB but BIGINT on a guarded fresh install, so a hard FK would be
        // environment-dependent. The rest of the project likewise references
        // users via soft-coupled INT UNSIGNED columns (students.user_id).
    }

    // -----------------------------------------------------------------
    // exams
    // -----------------------------------------------------------------

    private function addExamColumns(): void
    {
        $add = fn (callable $callback) => Schema::table('exams', $callback);

        foreach (['class_id', 'academic_year_id', 'semester_id', 'teacher_id'] as $col) {
            if (! Schema::hasColumn('exams', $col)) {
                $add(function (Blueprint $t) use ($col) {
                    $t->unsignedInteger($col)->nullable();
                });
            }
        }
        if (! Schema::hasColumn('exams', 'exam_type')) {
            $add(function (Blueprint $t) {
                $t->string('exam_type', 50)->nullable();
            });
        }
        if (! Schema::hasColumn('exams', 'instructions')) {
            $add(function (Blueprint $t) {
                $t->text('instructions')->nullable();
            });
        }
        if (! Schema::hasColumn('exams', 'config_snapshot')) {
            $add(function (Blueprint $t) {
                $t->json('config_snapshot')->nullable();
            });
        }

        if (! $this->foreignKeyExists('exams', 'fk_ex_exam_class') && Schema::hasColumn('exams', 'class_id')) {
            $add(fn (Blueprint $t) => $t->foreign('class_id', 'fk_ex_exam_class')->references('id')->on('classes')->onDelete('set null'));
        }
        if (! $this->foreignKeyExists('exams', 'fk_ex_exam_academic_year') && Schema::hasColumn('exams', 'academic_year_id')) {
            $add(fn (Blueprint $t) => $t->foreign('academic_year_id', 'fk_ex_exam_academic_year')->references('id')->on('academic_years')->onDelete('set null'));
        }
        if (! $this->foreignKeyExists('exams', 'fk_ex_exam_semester') && Schema::hasColumn('exams', 'semester_id')) {
            $add(fn (Blueprint $t) => $t->foreign('semester_id', 'fk_ex_exam_semester')->references('id')->on('semesters')->onDelete('set null'));
        }
        if (! $this->foreignKeyExists('exams', 'fk_ex_exam_teacher') && Schema::hasColumn('exams', 'teacher_id')) {
            $add(fn (Blueprint $t) => $t->foreign('teacher_id', 'fk_ex_exam_teacher')->references('id')->on('teachers')->onDelete('set null'));
        }
    }

    // -----------------------------------------------------------------
    // exam_schedules
    // -----------------------------------------------------------------

    private function addExamScheduleColumns(): void
    {
        if (! Schema::hasColumn('exam_schedules', 'supervisor_id')) {
            Schema::table('exam_schedules', function (Blueprint $t) {
                $t->unsignedInteger('supervisor_id')->nullable();
            });
        }
        foreach (['start_datetime', 'end_datetime'] as $col) {
            if (! Schema::hasColumn('exam_schedules', $col)) {
                Schema::table('exam_schedules', function (Blueprint $t) use ($col) {
                    $t->dateTime($col)->nullable();
                });
            }
        }
        if (! $this->foreignKeyExists('exam_schedules', 'fk_ex_sch_supervisor') && Schema::hasColumn('exam_schedules', 'supervisor_id')) {
            Schema::table('exam_schedules', function (Blueprint $t) {
                $t->foreign('supervisor_id', 'fk_ex_sch_supervisor')->references('id')->on('teachers')->onDelete('set null');
            });
        }
    }

    // -----------------------------------------------------------------
    // exam_participants
    // -----------------------------------------------------------------

    private function addExamParticipantColumns(): void
    {
        if (! Schema::hasColumn('exam_participants', 'schedule_id')) {
            Schema::table('exam_participants', function (Blueprint $t) {
                $t->unsignedInteger('schedule_id')->nullable();
            });
        }
        if (! Schema::hasColumn('exam_participants', 'attendance')) {
            Schema::table('exam_participants', function (Blueprint $t) {
                $t->string('attendance', 20)->nullable();
            });
        }
        if (! $this->foreignKeyExists('exam_participants', 'fk_ex_pa_schedule') && Schema::hasColumn('exam_participants', 'schedule_id')) {
            Schema::table('exam_participants', function (Blueprint $t) {
                $t->foreign('schedule_id', 'fk_ex_pa_schedule')->references('id')->on('exam_schedules')->onDelete('set null');
            });
        }
    }

    // -----------------------------------------------------------------
    // exam_attempts
    // -----------------------------------------------------------------

    private function addExamAttemptColumns(): void
    {
        Schema::table('exam_attempts', function (Blueprint $t) {
            if (! Schema::hasColumn('exam_attempts', 'snapshot')) {
                $t->json('snapshot')->nullable();
            }
        });
    }

    // -----------------------------------------------------------------
    // exam_answers
    // -----------------------------------------------------------------

    private function addExamAnswerColumns(): void
    {
        if (! Schema::hasColumn('exam_answers', 'score')) {
            Schema::table('exam_answers', function (Blueprint $t) {
                $t->decimal('score', 10, 2)->nullable();
            });
        }
        foreach (['feedback'] as $col) {
            if (! Schema::hasColumn('exam_answers', $col)) {
                Schema::table('exam_answers', function (Blueprint $t) use ($col) {
                    $t->text($col)->nullable();
                });
            }
        }
        foreach (['grade_status', 'graded_by', 'graded_at'] as $col) {
            if (! Schema::hasColumn('exam_answers', $col)) {
                Schema::table('exam_answers', function (Blueprint $t) use ($col) {
                    if ($col === 'grade_status') {
                        $t->string($col, 20)->nullable();
                    } elseif ($col === 'graded_by') {
                        $t->unsignedInteger($col)->nullable();
                    } else {
                        $t->dateTime($col)->nullable();
                    }
                });
            }
        }
        // NOTE: no FK on graded_by — same users.id environment divergence as
        // question_banks.owner_id; application-layer integrity instead.
    }

    // -----------------------------------------------------------------
    // exam_results
    // -----------------------------------------------------------------

    private function addExamResultColumns(): void
    {
        if (! Schema::hasColumn('exam_results', 'exam_attempt_id')) {
            Schema::table('exam_results', function (Blueprint $t) {
                $t->unsignedBigInteger('exam_attempt_id')->nullable();
            });
        }
        foreach (['raw_score', 'weighted_score'] as $col) {
            if (! Schema::hasColumn('exam_results', $col)) {
                Schema::table('exam_results', function (Blueprint $t) use ($col) {
                    $t->decimal($col, 10, 2)->nullable();
                });
            }
        }
        if (! Schema::hasColumn('exam_results', 'percentage')) {
            Schema::table('exam_results', function (Blueprint $t) {
                $t->decimal('percentage', 5, 2)->nullable();
            });
        }
        if (! Schema::hasColumn('exam_results', 'passed')) {
            Schema::table('exam_results', function (Blueprint $t) {
                $t->boolean('passed')->nullable();
            });
        }
        if (! Schema::hasColumn('exam_results', 'duration_seconds')) {
            Schema::table('exam_results', function (Blueprint $t) {
                $t->unsignedInteger('duration_seconds')->nullable();
            });
        }
        if (! Schema::hasColumn('exam_results', 'is_final')) {
            Schema::table('exam_results', function (Blueprint $t) {
                $t->boolean('is_final')->default(false);
            });
        }
        if (! Schema::hasColumn('exam_results', 'finalized_at')) {
            Schema::table('exam_results', function (Blueprint $t) {
                $t->dateTime('finalized_at')->nullable();
            });
        }

        if (! Schema::hasIndex('exam_results', 'idx_exam_results_attempt_id')) {
            Schema::table('exam_results', function (Blueprint $t) {
                $t->index('exam_attempt_id', 'idx_exam_results_attempt_id');
            });
        }
        if (! $this->foreignKeyExists('exam_results', 'fk_res_attempt') && Schema::hasColumn('exam_results', 'exam_attempt_id')) {
            Schema::table('exam_results', function (Blueprint $t) {
                $t->foreign('exam_attempt_id', 'fk_res_attempt')->references('id')->on('exam_attempts')->onDelete('cascade');
            });
        }
    }

    // -----------------------------------------------------------------
    // grades
    // -----------------------------------------------------------------

    private function addGradeColumns(): void
    {
        if (! Schema::hasColumn('grades', 'source_type')) {
            Schema::table('grades', function (Blueprint $t) {
                $t->string('source_type', 50)->nullable();
            });
        }
        if (! Schema::hasColumn('grades', 'source_id')) {
            Schema::table('grades', function (Blueprint $t) {
                $t->unsignedBigInteger('source_id')->nullable();
            });
        }
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function columnExists(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
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

    private function dropColumns(string $table, array $columns): void
    {
        Schema::table($table, function (Blueprint $t) use ($table, $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};