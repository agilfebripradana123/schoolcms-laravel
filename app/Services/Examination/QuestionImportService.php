<?php

namespace App\Services\Examination;

use App\Imports\QuestionBankImport;
use App\Models\Examination\QuestionBank;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Question Bank XLSX import orchestration (v1).
 *
 * The same read+validate path feeds both the preview and the actual import so
 * a preview that reports "valid" can never diverge from the import result.
 *
 *   - preview: parse + validate only; never writes.
 *   - import: parse + validate; any invalid row rejects the WHOLE file
 *     (all-or-nothing), then all rows persist inside one DB transaction.
 *
 * Imported questions always start as `draft` and are owned by the current
 * user — the approval workflow is never bypassed.
 */
class QuestionImportService
{
    /**
     * Parse + validate an uploaded XLSX without writing anything.
     *
     * @return array{valid: bool, message: string, total_rows: int, valid_rows: int, invalid_rows: int, errors: array, validated_data: array}
     */
    public function analyze(UploadedFile $file): array
    {
        $reader = new QuestionBankImport();

        try {
            Excel::import($reader, $file);
        } catch (\Throwable $e) {
            return $this->result(false, 'Failed to read the Excel file. Ensure the file is a valid .xlsx.', 0, [], true);
        }

        $message = null;
        if (! $reader->isHeaderValid()) {
            $message = 'Invalid Excel header.';
        } elseif ($reader->hasErrors()) {
            $message = 'Question import rejected: '.$reader->getFailedRowCount().' invalid row(s).';
        }

        return $this->result(
            $message === null,
            $message ?? 'Question import preview generated successfully.',
            $reader->getTotalRows(),
            $reader->getValidData(),
            false,
            $reader->getErrors()
        );
    }

    /**
     * Preview result: includes per-row preview rows when the file is fully valid.
     */
    public function preview(UploadedFile $file): array
    {
        $result = $this->analyze($file);

        $result['preview'] = array_map(
            fn (array $data) => [
                'excel_row' => $data['excel_row'],
                'question_text' => $data['question_text'],
                'question_type' => $data['question_type'],
                'points' => $data['points'],
                'option_count' => count($data['options']),
            ],
            $result['validated_data']
        );

        return $result;
    }

    /**
     * Execute the import. Rejects the entire file when any row is invalid;
     * otherwise persists all rows atomically (status draft).
     */
    public function import(UploadedFile $file, int $subjectId, ?int $ownerId): array
    {
        $result = $this->analyze($file);

        if (! $result['valid']) {
            return $result;
        }

        $questionIds = [];

        DB::transaction(function () use ($result, $subjectId, $ownerId, &$questionIds) {
            foreach ($result['validated_data'] as $data) {
                $question = QuestionBank::create([
                    'subject_id' => $subjectId,
                    'owner_id' => $ownerId,
                    'question_text' => $data['question_text'],
                    'type' => $data['question_type'],
                    'difficulty' => 'medium',
                    'explanation' => $data['explanation'],
                    'points' => $data['points'],
                    'status' => 'draft',
                ]);

                $question->code = 'Q-'.str_pad((string) $question->id, 6, '0', STR_PAD_LEFT);
                $question->save();

                foreach ($data['options'] as $option) {
                    $question->options()->create([
                        'option_text' => $option['option_text'],
                        'option_image' => null,
                        'is_correct' => $option['is_correct'],
                    ]);
                }

                $questionIds[] = $question->id;
            }
        });

        $result['imported_count'] = count($questionIds);
        $result['question_ids'] = $questionIds;
        $result['message'] = count($questionIds).' question(s) imported successfully.';

        return $result;
    }

    private function result(
        bool $valid,
        string $message,
        int $totalRows,
        array $validatedData,
        bool $parseFailure,
        array $errors = []
    ): array {
        $invalidRows = $parseFailure ? $totalRows : $totalRows - count($validatedData);

        return [
            'valid' => $valid,
            'message' => $message,
            'total_rows' => $totalRows,
            'valid_rows' => count($validatedData),
            'invalid_rows' => $invalidRows,
            'errors' => $errors,
            'validated_data' => $validatedData,
        ];
    }
}