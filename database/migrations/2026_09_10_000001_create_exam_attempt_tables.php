<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exam_participant_id');
            $table->unsignedBigInteger('exam_id');
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
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempt_events');
        Schema::dropIfExists('exam_attempts');
    }
};
