<?php

namespace App\Http\Requests\Api\Academic;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreReportCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->any()) {
                return;
            }

            $academicYearId = $this->input('academic_year_id');
            $semesterId = $this->input('semester_id');

            $semester = \App\Models\Academic\Semester::find($semesterId);

            if ($semester && (int) $semester->academic_year_id !== (int) $academicYearId) {
                $validator->errors()->add('semester_id', 'The selected semester does not belong to the selected academic year.');
            }
        });
    }

    public function rules(): array
    {
        return [
            'student_id' => [
                'required',
                'integer',
                Rule::exists('students', 'id')->whereNull('deleted_at'),
                Rule::unique('report_cards')->where(fn ($q) => $q
                    ->where('student_id', $this->input('student_id'))
                    ->where('class_id', $this->input('class_id'))
                    ->where('academic_year_id', $this->input('academic_year_id'))
                    ->where('semester_id', $this->input('semester_id'))),
            ],
            'class_id' => [
                'required',
                'integer',
                Rule::exists('classes', 'id'),
            ],
            'academic_year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id'),
            ],
            'semester_id' => [
                'required',
                'integer',
                Rule::exists('semesters', 'id'),
            ],
            'teacher_notes' => [
                'nullable',
                'string',
            ],
            'status' => [
                'required',
                'string',
                Rule::in(['draft', 'published']),
            ],
            'published_at' => [
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
