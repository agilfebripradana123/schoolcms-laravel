<?php

namespace App\Services\Academic;

use App\Models\Academic\Grade;
use App\Models\Academic\GradeAssessment;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Grade aggregation engine (Phase 2L-3, Stages B + C).
 *
 * `aggregate()` is COMPUTE ONLY — it reads grade_assessments and returns a
 * normalized, bucketed arithmetic mean for a single academic identity:
 *
 *   (student_id, subject_id, class_id, academic_year_id, semester_id)
 *
 * `synchronizeScore()` additionally writes the derived buckets back into the
 * matching Grade rows in exactly one atomic transaction.
 *
 * Aggregation contract:
 *   - normalized_score = (score / max_score) * 100, clamped to 0..100
 *   - multiple assessments within a bucket = arithmetic mean over
 *     assessment_sequence 1..n (no latest/highest behavior)
 *   - weight is ignored
 *   - missing categories are omitted (never zero-padded)
 *   - remedial is independent assessment evidence inside the tugas bucket
 *   - deterministic: re-running over the same assessment set yields the same
 *     derived buckets (pure recompute, no accumulation)
 */
class GradeAggregationService
{
    /**
     * Category -> bucket mapping. Every supported category folds into one of
     * the three legacy grade types; no new grade types are introduced.
     */
    private const BUCKETS = [
        'tugas' => ['tugas', 'formatif', 'PH', 'PTS', 'PAS', 'sumatif', 'ujian_sekolah', 'remedial', 'other'],
        'uts' => ['uts'],
        'uas' => ['uas'],
    ];

    /**
     * Derive the bucket scores for one identity.
     *
     * Only present buckets are returned; a missing category yields no key.
     *
     * @return array<string, array{score: float, assessment_count: int}> keyed by bucket (tugas/uts/uas)
     */
    public function aggregate(
        int $studentId,
        int $subjectId,
        int $classId,
        int $academicYearId,
        int $semesterId,
    ): array {
        $assessments = GradeAssessment::query()
            ->where('student_id', $studentId)
            ->where('subject_id', $subjectId)
            ->where('class_id', $classId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->get();

        $result = [];

        foreach (self::BUCKETS as $bucket => $categories) {
            $bucketAssessments = $assessments->filter(
                fn (GradeAssessment $assessment) => in_array($assessment->assessment_category, $categories, true)
            );

            if ($bucketAssessments->isEmpty()) {
                continue;
            }

            $normalized = $bucketAssessments->map(
                fn (GradeAssessment $assessment) => $this->normalize((float) $assessment->score, (float) $assessment->max_score)
            );

            $result[$bucket] = [
                'score' => round($normalized->avg(), 2),
                'assessment_count' => $normalized->count(),
            ];
        }

        return $result;
    }

    /**
     * Write the derived bucket scores back into the matching Grade rows.
     *
     * Write-back rules (Stage 3C):
     *   - derives buckets exactly like aggregate() (full recompute, idempotent)
     *   - re-queries each present bucket's Grade row INSIDE the transaction
     *     (`lockForUpdate` where the driver supports row locks)
     *   - verifies every located row passes GradeMutationGuard::assertMutable()
     *     BEFORE any write — a single finalized or published-report-card
     *     locked bucket rejects the ENTIRE operation (atomic, no partial write)
     *   - updates only `score` on existing rows: never creates rows, never
     *     touches buckets without assessments, never rewrites identity or
     *     finalization fields
     *   - missing Grade rows are skipped silently
     *
     * @return array<string, array{score: float, assessment_count: int}> the derived buckets
     *
     * @throws HttpResponseException when any present bucket is finalized or
     *                               locked by a published report card
     */
    public function synchronizeScore(
        int $studentId,
        int $subjectId,
        int $classId,
        int $academicYearId,
        int $semesterId,
    ): array {
        $derived = $this->aggregate($studentId, $subjectId, $classId, $academicYearId, $semesterId);

        if ($derived === []) {
            return $derived;
        }

        DB::transaction(function () use ($derived, $studentId, $subjectId, $classId, $academicYearId, $semesterId) {
            $targets = [];

            foreach (array_keys($derived) as $bucket) {
                $grade = Grade::where('student_id', $studentId)
                    ->where('subject_id', $subjectId)
                    ->where('class_id', $classId)
                    ->where('academic_year_id', $academicYearId)
                    ->where('semester_id', $semesterId)
                    ->where('type', $bucket)
                    ->lockForUpdate()
                    ->first();

                if ($grade !== null) {
                    $targets[$bucket] = $grade;
                }
            }

            foreach ($targets as $grade) {
                app(GradeMutationGuard::class)->assertMutable($grade);
            }

            foreach ($derived as $bucket => $value) {
                if (! isset($targets[$bucket])) {
                    continue;
                }

                $targets[$bucket]->update(['score' => $value['score']]);
            }
        });

        return $derived;
    }

    /**
     * Derive the category-level weighted final score for one identity
     * (Phase 2L-4).
     *
     * COMPUTE ONLY. Reuses aggregate() for the bucket scores (no formula
     * duplication) and applies category-level relative weights lifted from
     * grade_assessments.weight:
     *   - weight is category-level; NULL behaves as equal share (effective 1.0)
     *   - uniform effective weights keep that value; otherwise the category
     *     falls back to equal share (1.0) — no per-assessment weighting
     *   - effective weight 0 excludes the category from numerator/denominator
     *     (the bucket remains reported)
     *   - negative weights are invalid and raise GradeAggregationException
     *   - relative scale: values > 100 are legal (normalized by their sum)
     *   - weighted_final = sum(score * weight) / sum(weight), rounded to 2
     *     decimals; null when no present category has a positive weight
     *
     * @return array{
     *     weighted_final_score: float|null,
     *     categories: array<string, array{score: float, assessment_count: int, weight: float}>,
     * }
     *
     * @throws GradeAggregationException when a persisted weight is negative
     */
    public function weightedFinalScore(
        int $studentId,
        int $subjectId,
        int $classId,
        int $academicYearId,
        int $semesterId,
    ): array {
        $derived = $this->aggregate($studentId, $subjectId, $classId, $academicYearId, $semesterId);

        $assessmentRows = GradeAssessment::query()
            ->where('student_id', $studentId)
            ->where('subject_id', $subjectId)
            ->where('class_id', $classId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->get(['assessment_category', 'weight']);

        $categories = [];
        $weightedSum = 0.0;
        $weightSum = 0.0;

        foreach ($derived as $bucket => $result) {
            $weight = $this->resolveCategoryWeight($assessmentRows, $bucket);

            $categories[$bucket] = [
                'score' => $result['score'],
                'assessment_count' => $result['assessment_count'],
                'weight' => $weight,
            ];

            if ($weight > 0) {
                $weightedSum += $result['score'] * $weight;
                $weightSum += $weight;
            }
        }

        return [
            'weighted_final_score' => $weightSum > 0 ? round($weightedSum / $weightSum, 2) : null,
            'categories' => $categories,
        ];
    }

    /**
     * Resolve the effective category weight for a bucket.
     *
     * All assessment rows participating in the bucket contribute one weight
     * value each; NULL behaves as equal share (1.0). Deterministic rules:
     * uniform effective weights keep that value, otherwise the category falls
     * back to equal share (1.0). Negative values are always invalid.
     *
     * @param  Collection<int, GradeAssessment>  $rows  identity-scoped assessment rows
     */
    private function resolveCategoryWeight(Collection $rows, string $bucket): float
    {
        $weights = $rows
            ->filter(
                fn (GradeAssessment $assessment) => in_array($assessment->assessment_category, self::BUCKETS[$bucket], true)
            )
            ->map(
                fn (GradeAssessment $assessment) => $assessment->weight === null ? 1.0 : (float) $assessment->weight
            )
            ->values();

        if ($weights->isEmpty()) {
            return 1.0;
        }

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new GradeAggregationException(sprintf(
                    'Cannot resolve category weight: weight must be non-negative (got %s).',
                    $weight
                ));
            }
        }

        $first = $weights->first();

        return $weights->every(fn (float $weight) => $weight === $first) ? $first : 1.0;
    }

    /**
     * @throws GradeAggregationException when max_score <= 0
     */
    private function normalize(float $score, float $maxScore): float
    {
        if ($maxScore <= 0) {
            throw new GradeAggregationException(sprintf(
                'Cannot normalize assessment score: max_score must be greater than 0 (got %s).',
                $maxScore
            ));
        }

        $normalized = ($score / $maxScore) * 100.0;

        return min(100.0, max(0.0, $normalized));
    }
}
