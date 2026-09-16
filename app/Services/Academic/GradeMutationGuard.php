<?php

namespace App\Services\Academic;

use App\Models\Academic\Grade;
use App\Models\Academic\ReportCard;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Authoritative guard for academic Grade mutation (Phase 2J).
 *
 * A Grade is immutable when:
 *   1. it is finalized (is_final = true), OR
 *   2. a PUBLISHED ReportCard exists for the same (student, class,
 *      academic_year, semester).
 *
 * `Grade.is_final` is the authoritative lock. ReportCard publication is an
 * ADDITIONAL guard and never auto-sets is_final (two explicit mechanisms).
 *
 * The guard rejects with the repository's established 422 envelope
 * ({success:false, message, data:null}) via HttpResponseException, so call
 * sites simply invoke assertMutable() without extra plumbing.
 */
class GradeMutationGuard
{
    public function isLocked(Grade $grade): bool
    {
        if ($grade->is_final) {
            return true;
        }

        return ReportCard::where('student_id', $grade->student_id)
            ->where('class_id', $grade->class_id)
            ->where('academic_year_id', $grade->academic_year_id)
            ->where('semester_id', $grade->semester_id)
            ->where('status', 'published')
            ->exists();
    }

    /**
     * @throws HttpResponseException when the grade is locked
     */
    public function assertMutable(Grade $grade): void
    {
        if (! $grade->is_final) {
            $publishedCard = ReportCard::where('student_id', $grade->student_id)
                ->where('class_id', $grade->class_id)
                ->where('academic_year_id', $grade->academic_year_id)
                ->where('semester_id', $grade->semester_id)
                ->where('status', 'published')
                ->exists();

            if (! $publishedCard) {
                return;
            }

            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Grade cannot be modified: a published report card exists for this academic period.',
                'errors' => ['grade' => ['A published report card locks this grade.']],
                'data' => null,
            ], 422));
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Grade is finalized and cannot be modified.',
            'errors' => ['grade' => ['Unfinalize the grade before modifying it.']],
            'data' => null,
        ], 422));
    }
}