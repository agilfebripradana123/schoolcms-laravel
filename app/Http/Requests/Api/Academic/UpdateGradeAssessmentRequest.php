<?php

namespace App\Http\Requests\Api\Academic;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGradeAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => [
                'sometimes',
                'integer',
                'exists:students,id',
            ],
            'subject_id' => [
                'sometimes',
                'integer',
                'exists:subjects,id',
            ],
            'class_id' => [
                'sometimes',
                'integer',
                'exists:classes,id',
            ],
            'academic_year_id' => [
                'sometimes',
                'integer',
                'exists:academic_years,id',
            ],
            'semester_id' => [
                'sometimes',
                'integer',
                'exists:semesters,id',
            ],
            'assessment_category' => [
                'sometimes',
                'string',
                Rule::in([
                    'tugas',
                    'formatif',
                    'PH',
                    'PTS',
                    'PAS',
                    'sumatif',
                    'uts',
                    'uas',
                    'ujian_sekolah',
                    'remedial',
                    'other',
                ]),
            ],
            'assessment_sequence' => [
                'sometimes',
                'integer',
                'min:1',
            ],
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

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // If student or class changed, validate consistency
            $studentId = $this->student_id ?? null;
            $classId = $this->class_id ?? null;

            if ($studentId && $classId) {
                $student = \App\Models\Students\Student::where('id', $studentId)
                    ->whereNull('deleted_at')
                    ->first();

                if ($student && $student->class_id != $classId) {
                    $validator->errors()->add('student_id', 'Siswa tidak terdaftar di kelas yang ditentukan.');
                }
            }

            // If subject changed, validate it belongs to class
            if ($this->filled('subject_id') && $this->filled('class_id')) {
                $classSubject = \App\Models\Academic\ClassSubject::where('class_id', $this->class_id)
                    ->where('subject_id', $this->subject_id)
                    ->exists();

                if (!$classSubject) {
                    $validator->errors()->add('subject_id', 'Mata pelajaran tidak terdaftar pada kelas yang ditentukan.');
                }
            }
        });
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