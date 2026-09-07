<?php

namespace App\Services\Academic;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\Semester;

/**
 * Resolves the canonical grade period from either the canonical IDs
 * (`academic_year_id` + `semester_id`) or the legacy string aliases
 * (`academic_year` + `semester`), enforcing:
 *
 *   - a semester must belong to its academic year (pair consistency),
 *   - when both forms are supplied they must agree (no silent arbitration),
 *   - never guessing: any failure is recorded as a field error.
 *
 * Used by the grade write paths (Store/UpdateGradeRequest and the teacher
 * bulk write) so the server stays authoritative for the alias columns while
 * canonical IDs are stored.
 */
class GradePeriodResolver
{
    /** @var array<string, mixed> */
    private array $input;

    /** @var array<string, list<string>> */
    private array $errors = [];

    private function __construct(array $input)
    {
        $this->input = $input;
    }

    public static function from(array $input): self
    {
        return new self($input);
    }

    /**
     * @return array{academic_year_id: int, semester_id: int, academic_year: string, semester: string}|null
     */
    public function resolve(): ?array
    {
        $year = $this->resolveYear();
        $semester = $this->resolveSemester($year);

        if ($year === null || $semester === null) {
            return null;
        }

        return [
            'academic_year_id' => (int) $year->id,
            'semester_id' => (int) $semester->id,
            'academic_year' => $year->name,
            'semester' => $semester->name,
        ];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    private function resolveYear(): ?AcademicYear
    {
        if ($this->present('academic_year_id')) {
            $year = AcademicYear::where('id', $this->input['academic_year_id'])
                ->whereNull('deleted_at')
                ->first();

            if (!$year) {
                $this->addError('academic_year_id', 'The selected academic year is invalid.');

                return null;
            }

            if ($this->present('academic_year') && $year->name !== (string) $this->input['academic_year']) {
                $this->addError('academic_year', 'The academic_year value conflicts with academic_year_id.');
            }

            return $year;
        }

        if ($this->present('academic_year')) {
            $year = AcademicYear::where('name', $this->input['academic_year'])
                ->whereNull('deleted_at')
                ->first();

            if (!$year) {
                $this->addError('academic_year', 'The selected academic year is invalid.');

                return null;
            }

            return $year;
        }

        $this->addError('academic_year', 'The academic_year field is required.');

        return null;
    }

    private function resolveSemester(?AcademicYear $year): ?Semester
    {
        if ($this->present('semester_id')) {
            $semester = Semester::where('id', $this->input['semester_id'])->first();

            if (!$semester) {
                $this->addError('semester_id', 'The selected semester is invalid.');

                return null;
            }

            if ($year !== null && $semester->academic_year_id !== $year->id) {
                $this->addError('semester_id', 'The selected semester does not belong to the selected academic year.');

                return null;
            }

            if ($this->present('semester') && $semester->name !== (string) $this->input['semester']) {
                $this->addError('semester', 'The semester value conflicts with semester_id.');
            }

            return $semester;
        }

        if ($this->present('semester')) {
            if ($year === null) {
                return null; // academic year error already recorded above
            }

            $semester = Semester::where('academic_year_id', $year->id)
                ->where('name', $this->input['semester'])
                ->first();

            if (!$semester) {
                $this->addError('semester', 'The selected semester does not exist for the selected academic year.');

                return null;
            }

            return $semester;
        }

        $this->addError('semester', 'The semester field is required.');

        return null;
    }

    private function present(string $key): bool
    {
        $value = $this->input[$key] ?? null;

        return $value !== null && $value !== '';
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}