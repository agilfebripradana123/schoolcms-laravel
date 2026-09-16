<?php

namespace App\Http\Requests\Api\Academic;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGradeAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Identity is immutable on update.
            'student_id' => ['sometimes', 'prohibited'],
            'subject_id' => ['sometimes', 'prohibited'],
            'class_id' => ['sometimes', 'prohibited'],
            'academic_year_id' => ['sometimes', 'prohibited'],
            'semester_id' => ['sometimes', 'prohibited'],
            'assessment_category' => ['sometimes', 'prohibited'],
            'assessment_sequence' => ['sometimes', 'prohibited'],
            'score' => [
                'sometimes',
                'numeric',
                'min:0',
                'max:100',
            ],
            'max_score' => [
                'sometimes',
                'numeric',
                'min:1',
            ],
            'weight' => [
                'sometimes',
                'numeric',
                'min:0',
            ],
            'assessed_date' => [
                'sometimes',
                'date',
            ],
            'notes' => [
                'sometimes',
                'string',
            ],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validasi failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}