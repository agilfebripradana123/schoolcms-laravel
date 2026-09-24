<?php

namespace App\Http\Requests\Api\Examination;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_id' => [
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
                'required',
                'string',
                'max:200',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'duration_minutes' => [
                'required',
                'integer',
                'min:1',
            ],
            'total_questions' => [
                'integer',
                'min:0',
            ],
            'passing_score' => [
                'integer',
                'min:0',
                'max:100',
            ],
            'max_attempts' => [
                'integer',
                'min:1',
            ],
            'shuffle_questions' => [
                'boolean',
            ],
            'shuffle_options' => [
                'boolean',
            ],
            'show_result' => [
                'boolean',
            ],
            'status' => [
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
