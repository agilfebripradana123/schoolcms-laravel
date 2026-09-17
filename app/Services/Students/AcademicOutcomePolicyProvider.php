<?php

namespace App\Services\Students;

use App\Models\System\Setting;

/**
 * Academic outcome policy provider (Phase 2M-2H).
 *
 * Reads the `academic_outcome.*` settings group and coerces the raw TEXT values
 * into the PHP types the advisory evaluator needs. It owns all type coercion
 * and JSON parsing.
 *
 * Semantics:
 *   - absent key            => rule disabled (never automatic pass)
 *   - malformed value       => rule disabled, recorded as a machine-readable
 *                              `policy_invalid` error for the evaluator
 *   - missing/null integer  => threshold disabled
 *
 * The provider never queries Grade/GradeAssessment/ReportCard/Attendance and
 * performs no policy decision. `scope` is accepted for forward-compatibility
 * (promotion/graduation rule-sets share the same keys today).
 */
class AcademicOutcomePolicyProvider
{
    public const GROUP = 'academic_outcome';

    private array $settings;

    private array $errors = [];

    /**
     * @return array{
     *     score_enabled: bool,
     *     min_final_score: int|null,
     *     min_completeness_pct: int|null,
     *     required_subjects: int[]|null,
     *     pilihan_included: bool,
     *     remedial_allowed: bool,
     *     zero_is_valid: bool,
     *     missing_is_failure: bool,
     *     report_card_required: bool,
     * }
     */
    public function get(string $scope = 'promotion'): array
    {
        $this->settings = Setting::query()
            ->where('group', self::GROUP)
            ->pluck('value', 'key')
            ->all();

        $this->errors = [];

        return [
            'score_enabled' => $this->boolean('score_enabled', false),
            'min_final_score' => $this->nullableInteger('min_final_score'),
            'min_completeness_pct' => $this->nullableInteger('min_completeness_pct'),
            'required_subjects' => $this->idList('required_subjects'),
            'pilihan_included' => $this->boolean('pilihan_included', false),
            'remedial_allowed' => $this->boolean('remedial_allowed', false),
            'zero_is_valid' => $this->boolean('zero_is_valid', false),
            'missing_is_failure' => $this->boolean('missing_is_failure', false),
            'report_card_required' => $this->boolean('report_card_required', false),
        ];
    }

    /**
     * Machine-readable configuration errors discovered during the last get().
     *
     * @return array<int, string> setting keys that failed to parse
     */
    public function configurationErrors(): array
    {
        return array_values(array_unique($this->errors));
    }

    private function boolean(string $key, bool $default): bool
    {
        $value = $this->settings[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null) {
            return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        $this->errors[] = $key;

        return $default;
    }

    private function nullableInteger(string $key): ?int
    {
        $value = $this->settings[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            $this->errors[] = $key;

            return null;
        }

        return (int) $value;
    }

    /**
     * @return int[]|null
     */
    private function idList(string $key): ?array
    {
        $value = $this->settings[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            $this->errors[] = $key;

            return null;
        }

        $ids = array_values(array_filter(array_map('intval', $decoded), fn (int $v) => $v > 0));

        if ($ids === [] || count($ids) !== count($decoded)) {
            $this->errors[] = $key;

            return null;
        }

        return $ids;
    }
}
