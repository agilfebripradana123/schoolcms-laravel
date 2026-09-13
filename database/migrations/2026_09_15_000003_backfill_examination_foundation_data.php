<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 2A — Deterministic, non-destructive backfill for the new foundation
 * columns. Every statement targets only rows where the column is still NULL
 * (or otherwise unchanged), so re-runs and --pretend are safe.
 *
 *  - question_banks.code        : 'Q-' + zero-padded id (matches the rooms
 *                                 `RM-` convention; reproducible, no randomness)
 *  - question_banks.status      : legacy rows -> 'approved'  (safest default;
 *                                 approval workflow deferred to a later phase)
 *  - exams.exam_type            : legacy rows -> 'other'  (never inferred from title)
 *  - exams.config_snapshot      : rebuilt deterministically from current columns
 *  - exam_participants.attendance : legacy rows -> 'pending'
 *  - exam_results.is_final      : true only when the participant has exactly one
 *                                 result (deterministic; ambiguous cases untouched)
 *  - exam_results.exam_attempt_id : mapped to the participant's latest attempt
 *                                 (highest attempt_number, then submitted_at,
 *                                 then started_at); never fabricated. No-op when
 *                                 no attempt exists.
 *
 * Not backfilled (deliberately left NULL for reconciliation):
 *  - exam_answers.grade_status / score / feedback / graded_by / graded_at
 *  - exam_results.exam_attempt_id when no deterministic attempt match
 *  - blueprints / blueprint_items / exam_questions (new, empty by design)
 */
return new class extends Migration
{
    public function up(): void
    {
        echo "[phase2a] backfill: question_banks.code\n";
        $rows = DB::table('question_banks')->whereNull('code')->get(['id']);
        foreach ($rows as $row) {
            DB::table('question_banks')->where('id', $row->id)->update([
                'code' => 'Q-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT),
            ]);
        }

        echo "[phase2a] backfill: question_banks.status\n";
        DB::table('question_banks')->whereNull('status')->update(['status' => 'approved']);

        echo "[phase2a] backfill: exams.exam_type\n";
        DB::table('exams')->whereNull('exam_type')->update(['exam_type' => 'other']);

        echo "[phase2a] backfill: exams.config_snapshot\n";
        $exams = DB::table('exams')->whereNull('config_snapshot')->get([
            'id', 'duration_minutes', 'passing_score', 'max_attempts',
            'shuffle_questions', 'shuffle_options', 'show_result', 'exam_type',
        ]);
        foreach ($exams as $exam) {
            DB::table('exams')->where('id', $exam->id)->update([
                'config_snapshot' => json_encode([
                    'duration_minutes' => (int) $exam->duration_minutes,
                    'passing_score' => (int) $exam->passing_score,
                    'max_attempts' => (int) $exam->max_attempts,
                    'shuffle_questions' => (bool) $exam->shuffle_questions,
                    'shuffle_options' => (bool) $exam->shuffle_options,
                    'show_result' => (bool) $exam->show_result,
                    'exam_type' => $exam->exam_type ?? 'other',
                ]),
            ]);
        }

        echo "[phase2a] backfill: exam_participants.attendance\n";
        DB::table('exam_participants')->whereNull('attendance')->update(['attendance' => 'pending']);

        echo "[phase2a] backfill: exam_results.is_final (single-result participants)\n";
        $singleResultParticipants = DB::table('exam_results')
            ->select('participant_id')
            ->groupBy('participant_id')
            ->havingRaw('COUNT(*) = 1')
            ->pluck('participant_id');
        DB::table('exam_results')
            ->where('is_final', 0)
            ->whereIn('participant_id', $singleResultParticipants)
            ->update(['is_final' => 1]);

        echo "[phase2a] backfill: exam_results.exam_attempt_id (latest attempt per participant)\n";
        $results = DB::table('exam_results')->whereNull('exam_attempt_id')->get(['id', 'participant_id']);
        $mapped = 0;
        foreach ($results as $result) {
            $attempt = DB::table('exam_attempts')
                ->where('exam_participant_id', $result->participant_id)
                ->orderByDesc('attempt_number')
                ->orderByDesc('submitted_at')
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->first();
            if ($attempt) {
                DB::table('exam_results')->where('id', $result->id)->update(['exam_attempt_id' => $attempt->id]);
                $mapped++;
            }
        }
        echo "[phase2a] backfill: exam_results.exam_attempt_id mapped={$mapped} unresolved=".($results->count() - $mapped)."\n";
    }

    public function down(): void
    {
        // Backfill is intentionally NOT reversed in down(): reverting computed
        // values would destroy audit continuity. The additive columns migration
        // is the rollback boundary for the schema; data backfill is left as-is.
    }
};