<?php

namespace App\Services\Students;

use InvalidArgumentException;

/**
 * Advisory academic outcome eligibility evaluator (Phase 2M-2H).
 *
 * Architecture (locked):
 *   Evidence -> Policy Provider -> Advisory Evaluator -> Human Decision -> Finalization
 *
 * This service is READ-ONLY and purely advisory. It consumes
 * AcademicOutcomeEvidenceService and AcademicOutcomePolicyProvider; it never
 * queries Grade/GradeAssessment/ReportCard directly, never mutates anything,
 * never calls finalization, and never invents policy values.
 *
 * Semantics locked:
 *   - absent/malformed policy  => rule disabled + machine-readable reason;
 *                                 never a permissive automatic pass
 *   - final_score null         => missing (not zero, not failure unless configured)
 *   - final_score 0            => valid zero (not missing)
 *   - expected subject w/o assessment => missing final
 *   - threshold applies only to actual final scores
 *   - required subjects counted regardless of wajib/pilihan inclusion
 *   - remedial presence is advisory-only (no silent pass/fail)
 *
 * outcome is always null in this version: the current policy settings cannot
 * safely express an advisory outcome, and the evaluator must not auto-decide
 * naik/tinggal/lulus.
 */
class AcademicOutcomeEligibilityService
{
    public function __construct(
        private AcademicOutcomeEvidenceService $evidence,
        private AcademicOutcomePolicyProvider $policy,
    ) {}

    public function evaluate(
        int $studentId,
        int $classId,
        int $academicYearId,
        int $semesterId,
        string $scope = 'promotion',
    ): array {
        if (! in_array($scope, ['promotion', 'graduation'], true)) {
            throw new InvalidArgumentException('scope must be promotion or graduation');
        }

        $policy = $this->policy->get($scope);
        $configErrors = $this->policy->configurationErrors();

        $evidence = $this->evidence->evidence($studentId, $classId, $academicYearId, $semesterId);

        $reasons = [];
        $failures = [];

        foreach ($configErrors as $key) {
            $this->reason($reasons, 'policy_invalid', null, $key);
            $this->failure($failures);
        }

        $enabledCriterion = false;

        if ($policy['report_card_required']) {
            $enabledCriterion = true;
            if (! $evidence['report_card']['published']) {
                $this->reason($reasons, 'report_card_not_published');
                $this->failure($failures);
            }
        }

        $scoreSubjects = $this->applicableSubjects($evidence['subjects'], (bool) $policy['pilihan_included'], $excludedPilihanCount);

        if (($policy['score_enabled']) && $policy['min_final_score'] !== null) {
            $enabledCriterion = true;

            foreach ($scoreSubjects as $subject) {
                if ($subject['has_final']) {
                    $value = (float) $subject['final_score'];

                    if ($value < $policy['min_final_score']) {
                        $this->reason($reasons, 'score_below_minimum', $subject['subject_id'], $value);
                        $this->failure($failures);
                    }

                    if ($value === 0.0 && ! $policy['zero_is_valid']) {
                        $this->reason($reasons, 'zero_not_valid', $subject['subject_id'], $value);
                        $this->failure($failures);
                    }
                } elseif ($policy['missing_is_failure']) {
                    $this->reason($reasons, 'missing_final', $subject['subject_id']);
                    $this->failure($failures);
                }
            }
        }

        if ($policy['min_completeness_pct'] !== null) {
            $enabledCriterion = true;

            $expected = count($scoreSubjects);
            $withFinal = count(array_filter($scoreSubjects, fn (array $s) => $s['has_final']));

            if ($expected === 0) {
                $this->reason($reasons, 'completeness_not_computable');
                $this->failure($failures);
            } else {
                $pct = round(($withFinal / $expected) * 100, 2);

                if ($pct < $policy['min_completeness_pct']) {
                    $this->reason($reasons, 'completeness_below_minimum', null, $pct);
                    $this->failure($failures);
                }
            }
        }

        if ($policy['required_subjects'] !== null) {
            $enabledCriterion = true;

            foreach ($policy['required_subjects'] as $subjectId) {
                $subject = collect($evidence['subjects'])->firstWhere('subject_id', $subjectId);

                if ($subject === null) {
                    $this->reason($reasons, 'required_subject_missing', $subjectId);
                    $this->failure($failures);

                    continue;
                }

                if (! $subject['has_final']) {
                    if ($policy['missing_is_failure']) {
                        $this->reason($reasons, 'missing_final', $subjectId);
                        $this->failure($failures);
                    }

                    continue;
                }

                $value = (float) $subject['final_score'];

                if ($policy['min_final_score'] !== null && $value < $policy['min_final_score']) {
                    $this->reason($reasons, 'required_subject_below_minimum', $subjectId, $value);
                    $this->failure($failures);
                }
            }
        }

        if ($excludedPilihanCount > 0) {
            $this->reason($reasons, 'pilihan_excluded', null, $excludedPilihanCount);
        }

        foreach ($evidence['subjects'] as $subject) {
            if (in_array('remedial', $subject['categories_present'], true) && ! $policy['remedial_allowed']) {
                $this->reason($reasons, 'remedial_not_allowed', $subject['subject_id']);
            }
        }

        if (! $enabledCriterion) {
            $this->reason($reasons, 'policy_not_configured');
            $this->failure($failures);
        }

        usort($reasons, fn (array $a, array $b) => [$a['code'], $a['subject_id'] ?? PHP_INT_MAX] <=> [$b['code'], $b['subject_id'] ?? PHP_INT_MAX]
        );

        return [
            'eligible' => $failures === [],
            'outcome' => null,
            'reasons' => $reasons,
            'evidence' => $evidence,
        ];
    }

    /**
     * Subjects applicable to score/completeness evaluation.
     *
     * @param  array<int, array<string, mixed>>  $subjects
     */
    private function applicableSubjects(array $subjects, bool $pilihanIncluded, ?int &$excludedPilihanCount): array
    {
        $excludedPilihanCount = 0;

        if ($pilihanIncluded) {
            return $subjects;
        }

        $kept = [];

        foreach ($subjects as $subject) {
            if ($subject['type'] === 'pilihan') {
                $excludedPilihanCount++;

                continue;
            }

            $kept[] = $subject;
        }

        return $kept;
    }

    private function reason(array &$reasons, string $code, ?int $subjectId = null, mixed $value = null): void
    {
        $reasons[] = [
            'code' => $code,
            'subject_id' => $subjectId,
            'value' => $value,
        ];
    }

    private function failure(array &$failures): void
    {
        $failures[] = true;
    }
}
