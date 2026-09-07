<?php

namespace App\Http\Requests\Api\Academic;

use App\Models\Academic\Schedule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'class_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('classes', 'id'),
            ],
            'subject_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('subjects', 'id'),
            ],
            'teacher_id' => [
                'nullable',
                'integer',
                Rule::exists('teachers', 'id'),
            ],
            'day' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu']),
            ],
            'period_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('periods', 'id'),
            ],
            'academic_year_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('academic_years', 'id'),
            ],
            'semester_id' => [
                'nullable',
                'integer',
                Rule::exists('semesters', 'id')->where(function ($query) {
                    $academicYearId = $this->input('academic_year_id');

                    if (empty($academicYearId)) {
                        $academicYearId = Schedule::find((int) $this->route('schedule'))?->academic_year_id;
                    }

                    $query->where('academic_year_id', $academicYearId);
                }),
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
