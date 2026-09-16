<?php

namespace App\Services\Academic;

use App\Models\Academic\Grade;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;

/**
 * Grade finalization service (Phase 2J).
 *
 * The academic-grade lock. Finalization is transactional and
 * server-authoritative: the actor is always taken from the authenticated
 * server context and student/subject/class/period/score are NEVER client
 * supplied here.
 *
 * Semantics:
 *   - finalize(...)     : locks the grade, stamps finalized_at/finalized_by.
 *   - unfinalize(...)   : explicitly reverses the lock and clears both stamps.
 *   - finalizing an already-finalized grade is a safe no-op (idempotent —
 *     the original finalized_at/finalized_by are preserved).
 */
class GradeFinalizationService
{
    public function __construct(private GradeMutationGuard $guard)
    {
    }

    public function finalize(Grade $grade, User $actor): Grade
    {
        DB::transaction(function () use ($grade, $actor) {
            if (! $grade->is_final) {
                $grade->is_final = true;
                $grade->finalized_at = now();
                $grade->finalized_by = (int) $actor->id;
                $grade->save();
            }
        });

        return $grade->fresh();
    }

    public function unfinalize(Grade $grade, User $actor): Grade
    {
        DB::transaction(function () use ($grade) {
            if ($grade->is_final) {
                $grade->is_final = false;
                $grade->finalized_at = null;
                $grade->finalized_by = null;
                $grade->save();
            }
        });

        return $grade->fresh();
    }
}