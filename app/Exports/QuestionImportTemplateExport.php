<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Downloadable XLSX template for the Question Bank importer.
 *
 * Contains the required header row plus one worked example for each supported
 * question type. No real school or student data — purely illustrative.
 */
class QuestionImportTemplateExport implements FromCollection, WithHeadings
{
    public function headings(): array
    {
        return [
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
    }

    public function collection(): Collection
    {
        return new Collection([
            [
                'Siapakah penemu teori relativitas?',
                'multiple_choice',
                'Isaac Newton',
                'Albert Einstein',
                'Michael Faraday',
                'Nikola Tesla',
                'B',
                10,
                'Teori relativitas dikembangkan oleh Albert Einstein.',
            ],
            [
                'Uraikan dampak positif dan negatif revolusi industri terhadap lingkungan.',
                'essay',
                '',
                '',
                '',
                '',
                '',
                20,
                null,
            ],
        ]);
    }
}