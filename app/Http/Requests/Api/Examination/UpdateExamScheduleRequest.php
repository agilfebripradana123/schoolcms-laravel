<?php

namespace App\Http\Requests\Api\Examination;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateExamScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('exams', 'id'),
            ],
            'room_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('rooms', 'id'),
            ],
            'session_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('exam_sessions', 'id'),
            ],
            'exam_date' => [
                'sometimes',
                'required',
                'date',
            ],
            'start_datetime' => [
                'nullable',
                'date',
            ],
            'end_datetime' => [
                'nullable',
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
