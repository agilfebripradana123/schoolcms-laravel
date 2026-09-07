<?php

namespace App\Http\Requests\Api\Academic;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('academic_years', 'name')
                    ->ignore($this->route('academic_year')),
            ],
            'start_date' => [
                'sometimes',
                'nullable',
                'date',
                'before_or_equal:end_date',
            ],
            'end_date' => [
                'sometimes',
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
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
