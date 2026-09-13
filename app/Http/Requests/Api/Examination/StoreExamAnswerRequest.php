<?php

namespace App\Http\Requests\Api\Examination;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreExamAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'participant_id' => [
                'required',
                'integer',
                Rule::exists('exam_participants', 'id'),
            ],
            'question_id' => [
                'required',
                'integer',
                Rule::exists('question_banks', 'id'),
            ],
            'selected_option_id' => [
                'nullable',
                'integer',
                Rule::exists('question_options', 'id'),
            ],
            'essay_answer' => [
                'nullable',
                'string',
            ],
            'answered_at' => [
                'required',
                'date',
            ],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
