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
     * Resolve the academic grade bucket (tugas/uts/uas) for one assessment
     * category. Uses BUCKETS as the single source of mapping truth.
     *
     * @return string|null the bucket owning the category, or null when unknown
     */
    public function bucketForCategory(string $category): ?string
    {
        foreach (self::BUCKETS as $bucket => $categories) {
            if (in_array($category, $categories, true)) {
                return $bucket;
            }
        }

        return null;
    }

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
        return $this->aggregateFromAssessments(
            $this->assessmentsForIdentity($studentId, $subjectId, $classId, $academicYearId, $semesterId)
        );
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
        $assessments = $this->assessmentsForIdentity($studentId, $subjectId, $classId, $academicYearId, $semesterId);

        return $this->weightedFromBuckets(
            $this->aggregateFromAssessments($assessments),
            $assessments,
        );
    }

    /**
     * Derive the canonical weighted finals for every academic identity of one
     * student in a single assessment query (Phase 2L-7B).
     *
     * The student/period scope is loaded once; identities are grouped in memory
     * by (subject_id, class_id, academic_year_id, semester_id) and each group is
     * evaluated with the exact weightedFinalScore() semantics. No per-identity
     * database queries are issued. NULL indicates no eligible weighted final
     * (no assessments, only zero weights, or no positive-weight category).
     *
     * @return array<string, float|null> keyed by subject_id|class_id|academic_year_id|semester_id
     */
    public function weightedFinalScoresForStudent(
        int $studentId,
        ?int $academicYearId = null,
        ?int $semesterId = null,
    ): array {
        $query = GradeAssessment::query()->where('student_id', $studentId);

        if ($academicYearId !== null) {
            $query->where('academic_year_id', $academicYearId);
        }

        if ($semesterId !== null) {
            $query->where('semester_id', $semesterId);
        }

        $assessments = $query->get();

        $results = [];

        foreach ($assessments->groupBy(
            fn (GradeAssessment $assessment) => $this->identityKey($assessment)
        ) as $key => $group) {
            $results[$key] = $this->weightedFromBuckets(
                $this->aggregateFromAssessments($group),
                $group,
            )['weighted_final_score'];
        }

        return $results;
    }

    private function assessmentsForIdentity(
        int $studentId,
        int $subjectId,
        int $classId,
        int $academicYearId,
        int $semesterId,
    ): Collection {
        return GradeAssessment::query()
            ->where('student_id', $studentId)
            ->where('subject_id', $subjectId)
            ->where('class_id', $classId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->get();
    }

    private function aggregateFromAssessments(Collection $assessments): array
    {
        $result = [];

        foreach (array_keys(self::BUCKETS) as $bucket) {
            $derived = $this->deriveBucket($assessments, $bucket);

            if ($derived !== null) {
                $result[$bucket] = $derived;
            }
        }

        return $result;
    }

    private function deriveBucket(Collection $assessments, string $bucket): ?array
    {
        $bucketAssessments = $assessments->filter(
            fn (GradeAssessment $assessment) => in_array($assessment->assessment_category, self::BUCKETS[$bucket], true)
        );

        if ($bucketAssessments->isEmpty()) {
            return null;
        }

        $normalized = $bucketAssessments->map(
            fn (GradeAssessment $assessment) => $this->normalize((float) $assessment->score, (float) $assessment->max_score)
        );

        return [
            'score' => round($normalized->avg(), 2),
            'assessment_count' => $normalized->count(),
        ];
    }

    private function weightedFromBuckets(array $derived, Collection $assessmentRows): array
    {
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

    private function identityKey(GradeAssessment $assessment): string
    {
        return implode('|', [
            $assessment->subject_id,
            $assessment->class_id,
            $assessment->academic_year_id,
            $assessment->semester_id,
        ]);
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
