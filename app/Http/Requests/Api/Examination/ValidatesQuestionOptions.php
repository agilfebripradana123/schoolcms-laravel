<?php

namespace App\Http\Requests\Api\Examination;

use Illuminate\Contracts\Validation\Validator;

/**
 * Question option invariants shared by Store/Update question requests.
 *
 * Type-specific rules (counts) are enforced separately in the FormRequest
 * rules; this trait enforces the cross-cutting invariants that array rules
 * cannot express:
 *   - option text must not be empty/whitespace
 *   - option text must not repeat within the same question
 *   - multiple choice & true/false must declare exactly one correct option
 *
 * Correctness (`is_correct`) is question-bank internal data: it is only
 * accepted through these authorized management requests, never through any
 * student-facing endpoint.
 */
trait ValidatesQuestionOptions
{
    protected function validateQuestionOptionInvariants(Validator $validator, ?string $type, ?array $options): void
    {
        if ($type === null || $options === null) {
            return;
        }

        if (! in_array($type, ['multiple_choice', 'true_false'], true)) {
            return; // essay: option list must be empty (rules enforce size:0)
        }

        $texts = [];
        $correctCount = 0;

        foreach ($options as $index => $option) {
            $raw = is_array($option) ? ($option['option_text'] ?? null) : null;
            $text = is_string($raw) ? trim($raw) : '';
            $normalized = mb_strtolower($text);

            if ($text === '') {
                $validator->errors()->add("options.{$index}.option_text", 'Option text must not be empty.');
                continue;
            }

            if (in_array($normalized, $texts, true)) {
                $validator->errors()->add(
                    "options.{$index}.option_text",
                    'Duplicate option text is not allowed within a question.'
                );
            } else {
                $texts[] = $normalized;
            }

            if (filter_var($option['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $correctCount++;
            }
        }

        if ($type === 'multiple_choice' && count($options) >= 2 && $correctCount !== 1) {
            $validator->errors()->add('options', 'Multiple choice questions require exactly one correct option.');
        }

        if ($type === 'true_false' && count($options) === 2 && $correctCount !== 1) {
            $validator->errors()->add('options', 'True/false questions require exactly one correct option.');
        }
    }
}