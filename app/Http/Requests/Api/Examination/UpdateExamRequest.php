<?php

namespace App\Http\Requests\Api\Examination;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('subjects', 'id'),
            ],
            'class_id' => [
                'nullable',
                'integer',
                Rule::exists('classes', 'id')->whereNull('deleted_at'),
            ],
            'academic_year_id' => [
                'nullable',
                'integer',
                Rule::exists('academic_years', 'id')->whereNull('deleted_at'),
            ],
            'semester_id' => [
                'nullable',
                'integer',
                $this->input('academic_year_id')
                    ? Rule::exists('semesters', 'id')->where(function ($query) {
                        $query->where('academic_year_id', $this->input('academic_year_id'));
                    })
                    : Rule::exists('semesters', 'id'),
            ],
            'exam_type' => [
                'nullable',
                'string',
                Rule::in(['formatif', 'sumatif', 'uts', 'uas', 'ujian_sekolah', 'remedial', 'other']),
            ],
            'title' => [
                'sometimes',
                'required',
                'string',
                'max:200',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'duration_minutes' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
            ],
            'total_questions' => [
                'sometimes',
                'integer',
                'min:0',
            ],
            'passing_score' => [
                'sometimes',
                'integer',
                'min:0',
                'max:100',
            ],
            'max_attempts' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'shuffle_questions' => [
                'sometimes',
                'boolean',
            ],
            'shuffle_options' => [
                'sometimes',
                'boolean',
            ],
            'show_result' => [
                'sometimes',
                'boolean',
            ],
            'status' => [
                'sometimes',
                'string',
                Rule::in(['draft', 'published', 'ongoing', 'completed', 'archived']),
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
