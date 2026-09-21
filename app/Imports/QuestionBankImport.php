<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * XLSX reader for the Question Bank importer.
 *
 * Columns are mapped strictly by heading (WithHeadingRow), never by position.
 * Headers must match the template exactly; unknown headers reject the whole
 * file. Row validation is shared by the preview and the actual import so a
 * preview that says "valid" can never diverge from the import result.
 *
 * The reader never touches the database: it only parses + validates and
 * exposes the normalized rows for a later (rejected-or-atomic) write phase.
 */
class QuestionBankImport implements ToCollection, WithHeadingRow
{
    private const TEMPLATE_HEADERS = [
        'question_text',
        'question_type',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'correct_answer',
        'points',
        'explanation',
    ];

    private const SUPPORTED_TYPES = ['multiple_choice', 'essay'];

    private const CORRECT_ANSWERS = ['A', 'B', 'C', 'D'];

    private const MAX_ROW_COUNT = 1000;

    private const MAX_TEXT_LENGTH = 10000;
    private const MAX_OPTION_TEXT_LENGTH = 5000;
    private const MIN_POINTS = 1;
    private const MAX_POINTS = 1000;

    private bool $headerValid = false;
    private array $errors = [];
    private array $validData = [];
    private int $totalRows = 0;

    public function collection(Collection $rows): void
    {
        $rows = $rows->filter(fn ($row) => ! $this->isEmptyRow($row))->values();

        if ($rows->isEmpty()) {
            $this->totalRows = 0;

            return;
        }

        if (! $this->headersAreValid($rows->first())) {
            $this->errors[] = [
                'row' => 1,
                'field' => 'file',
                'message' => 'Invalid Excel header. Header must contain exactly: '.implode(', ', self::TEMPLATE_HEADERS).'.',
            ];

            return;
        }

        $this->headerValid = true;
        $this->totalRows = $rows->count();

        if ($this->totalRows > self::MAX_ROW_COUNT) {
            $this->errors[] = [
                'row' => 1,
                'field' => 'file',
                'message' => 'File exceeds the maximum of '.self::MAX_ROW_COUNT.' data rows.',
            ];

            return;
        }

        foreach ($rows as $index => $row) {
            $excelRow = $index + 2;
            $data = $this->normalizeRow($row);
            $rowErrors = $this->validateRow($data);

            if (! empty($rowErrors)) {
                foreach ($rowErrors as $error) {
                    $this->errors[] = [
                        'row' => $excelRow,
                        'field' => $error['field'],
                        'message' => $error['message'],
                    ];
                }

                continue;
            }

            $data['excel_row'] = $excelRow;
            $this->validData[] = $data;
        }
    }

    public function isHeaderValid(): bool
    {
        return $this->headerValid;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getValidData(): array
    {
        return $this->validData;
    }

    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function getFailedRowCount(): int
    {
        return $this->totalRows - count($this->validData);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function cell($row, string $key): mixed
    {
        return $row[$key] ?? null;
    }

    private function isEmptyRow($row): bool
    {
        foreach (self::TEMPLATE_HEADERS as $header) {
            $value = $this->cell($row, $header);

            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function headersAreValid($row): bool
    {
        $keys = collect($row->keys())
            ->filter(fn ($key) => is_string($key) && trim($key) !== '')
            ->map(fn ($key) => trim((string) $key))
            ->values();

        return $keys->diff(self::TEMPLATE_HEADERS)->isEmpty()
            && collect(self::TEMPLATE_HEADERS)->diff($keys)->isEmpty();
    }

    private function normalizeRow($row): array
    {
        $optionValue = fn (string $letter) => trim((string) $this->cell($row, 'option_'.strtolower($letter)));
        $explanation = trim((string) $this->cell($row, 'explanation'));

        return [
            'question_text' => trim((string) $this->cell($row, 'question_text')),
            'question_type' => strtolower(trim((string) $this->cell($row, 'question_type'))),
            'option_a' => $optionValue('A'),
            'option_b' => $optionValue('B'),
            'option_c' => $optionValue('C'),
            'option_d' => $optionValue('D'),
            'correct_answer' => strtoupper(trim((string) $this->cell($row, 'correct_answer'))),
            'points' => $this->cell($row, 'points'),
            'explanation' => $explanation === '' ? null : $explanation,
        ];
    }

    private function validateRow(array &$data): array
    {
        $errors = [];
        $type = $data['question_type'];

        if ($data['question_text'] === '') {
            $errors[] = ['field' => 'question_text', 'message' => 'question_text is required.'];
        } elseif (mb_strlen($data['question_text']) > self::MAX_TEXT_LENGTH) {
            $errors[] = ['field' => 'question_text', 'message' => 'question_text must not exceed '.self::MAX_TEXT_LENGTH.' characters.'];
        }

        if ($type === '') {
            $errors[] = ['field' => 'question_type', 'message' => 'question_type is required.'];
        } elseif (! in_array($type, self::SUPPORTED_TYPES, true)) {
            $errors[] = ['field' => 'question_type', 'message' => 'Unsupported question_type "'.$type.'". Supported types: '.implode(', ', self::SUPPORTED_TYPES).'.'];
        }

        if ($data['explanation'] !== null && mb_strlen($data['explanation']) > self::MAX_TEXT_LENGTH) {
            $errors[] = ['field' => 'explanation', 'message' => 'explanation must not exceed '.self::MAX_TEXT_LENGTH.' characters.'];
        }

        $points = $data['points'];
        if ($points === null || trim((string) $points) === '') {
            $errors[] = ['field' => 'points', 'message' => 'points is required.'];
        } else {
            if (! is_numeric($points)) {
                $errors[] = ['field' => 'points', 'message' => 'points must be a number.'];
            } else {
                $numeric = (float) $points;

                if (floor($numeric) !== $numeric) {
                    $errors[] = ['field' => 'points', 'message' => 'points must be an integer.'];
                } elseif ($numeric < self::MIN_POINTS || $numeric > self::MAX_POINTS) {
                    $errors[] = ['field' => 'points', 'message' => 'points must be between '.self::MIN_POINTS.' and '.self::MAX_POINTS.'.'];
                } else {
                    $data['points'] = (int) $numeric;
                }
            }
        }

        if ($type === 'multiple_choice') {
            $data['options'] = $this->validateMultipleChoice($data, $errors);
        } elseif ($type === 'essay') {
            $this->validateEssay($data, $errors);
            $data['options'] = [];
        }

        return $errors;
    }

    private function validateMultipleChoice(array $data, array &$errors): array
    {
        $provided = [];

        foreach (self::CORRECT_ANSWERS as $letter) {
            $text = $data['option_'.strtolower($letter)];

            if ($text === '') {
                continue;
            }

            if (mb_strlen($text) > self::MAX_OPTION_TEXT_LENGTH) {
                $errors[] = ['field' => 'option_'.strtolower($letter), 'message' => 'option_'.strtolower($letter).' must not exceed '.self::MAX_OPTION_TEXT_LENGTH.' characters.'];
            }

            $provided[$letter] = $text;
        }

        if (count($provided) < 2) {
            $errors[] = ['field' => 'options', 'message' => 'Multiple choice questions require at least 2 non-empty options (option_a..option_d).'];
        }

        $seen = [];
        foreach ($provided as $letter => $text) {
            $normalized = mb_strtolower($text);

            if (in_array($normalized, $seen, true)) {
                $errors[] = ['field' => 'option_'.strtolower($letter), 'message' => 'Duplicate option text is not allowed within a question.'];
            }

            $seen[] = $normalized;
        }

        $answer = $data['correct_answer'];
        if ($answer === '') {
            $errors[] = ['field' => 'correct_answer', 'message' => 'correct_answer is required for multiple choice questions.'];
        } elseif (! in_array($answer, self::CORRECT_ANSWERS, true)) {
            $errors[] = ['field' => 'correct_answer', 'message' => 'correct_answer must be one of '.implode(', ', self::CORRECT_ANSWERS).'.'];
        } elseif (! isset($provided[$answer])) {
            $errors[] = ['field' => 'correct_answer', 'message' => 'correct_answer "'.$answer.'" must reference a provided option.'];
        }

        return array_map(
            fn (string $letter, string $text) => [
                'letter' => $letter,
                'option_text' => $text,
                'is_correct' => $letter === $data['correct_answer'] && isset($provided[$letter]),
            ],
            array_keys($provided),
            array_values($provided)
        );
    }

    private function validateEssay(array $data, array &$errors): void
    {
        foreach (self::CORRECT_ANSWERS as $letter) {
            if ($data['option_'.strtolower($letter)] !== '') {
                $errors[] = ['field' => 'option_'.strtolower($letter), 'message' => 'Essay questions must not include options (option_a..option_d).'];
            }
        }

        if ($data['correct_answer'] !== '') {
            $errors[] = ['field' => 'correct_answer', 'message' => 'correct_answer must be empty for essay questions.'];
        }
    }
}