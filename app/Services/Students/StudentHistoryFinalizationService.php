<?php

namespace App\Services\Students;

use App\Models\Academic\ReportCard;
use App\Models\Academic\Semester;
use App\Models\Students\StudentHistory;
use App\Models\System\AuditLog;
use App\Models\System\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Outcome finalization service (Phase 2M-2D).
 *
 * Makes a StudentHistory row authoritative and irreversible.
 *
 * Prerequisites before locking:
 *   - the history must not already be finalized
 *   - exactly one Semester 2 must exist for the history's academic year
 *   - a published ReportCard must exist for the exact outcome identity
 *     (student + class + academic year + resolved Semester 2)
 *
 * Finalization is transactional, re-locks the row to prevent concurrent
 * double-finalization, and stamps is_final/finalized_at/finalized_by. No other
 * domain state (grades, assessments, report cards, class membership, alumni,
 * transfers, student lifecycle) is touched.
 */
class StudentHistoryFinalizationService
{
    public function finalize(
        StudentHistory $history,
        User $actor,
        ?string $decisionReason = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): StudentHistory {
        return DB::transaction(function () use ($history, $actor, $decisionReason, $ipAddress, $userAgent) {
            $locked = StudentHistory::query()
                ->whereKey($history->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw $this->reject('Student history not found.');
            }

            if ($locked->is_final) {
                throw $this->reject('Student history is already finalized.');
            }

            $semester2 = Semester::query()
                ->where('academic_year_id', $locked->academic_year_id)
                ->where('name', '2')
                ->get();

            if ($semester2->isEmpty()) {
                throw $this->reject('Terminal semester (Semester 2) is missing for this academic year.');
            }

            if ($semester2->count() > 1) {
                throw $this->reject('Multiple Semester 2 records exist for this academic year; academic configuration error.');
            }

            $terminalSemesterId = (int) $semester2->first()->id;

            $published = ReportCard::query()
                ->where('student_id', $locked->student_id)
                ->where('class_id', $locked->class_id)
                ->where('academic_year_id', $locked->academic_year_id)
                ->where('semester_id', $terminalSemesterId)
                ->where('status', 'published')
                ->exists();

            if (! $published) {
                throw $this->reject('A published terminal-semester (Semester 2) report card is required before outcome finalization.');
            }

            $locked->is_final = true;
            $locked->finalized_at = now();
            $locked->finalized_by = (int) $actor->id;

            if ($decisionReason !== null) {
                $locked->notes = $decisionReason;
            }

            $locked->save();

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'student_history_finalized',
                'model' => 'StudentHistory',
                'model_id' => $locked->id,
                'description' => json_encode(array_filter([
                    'status' => $locked->status,
                    'decision_reason' => $decisionReason,
                ])),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            return $locked->fresh();
        });
    }

    private function reject(string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'success' => false,
            'message' => $message,
            'errors' => ['student_history' => [$message]],
            'data' => null,
        ], 422));
    }
}
