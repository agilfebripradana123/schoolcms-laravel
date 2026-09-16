<?php

namespace App\Http\Requests\Api\Academic;

use App\Http\Requests\Api\ApiRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGradeAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => [
                'required',
                'integer',
                'exists:students,id',
            ],
            'subject_id' => [
                'required',
                'integer',
                'exists:subjects,id',
            ],
            'class_id' => [
                'required',
                'integer',
                'exists:classes,id',
            ],
            'academic_year_id' => [
                'required',
                'integer',
                'exists:academic_years,id',
            ],
            'semester_id' => [
                'required',
                'integer',
                'exists:semesters,id',
            ],
            'assessment_category' => [
                'required',
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
                'required',
                'integer',
                'min:1',
            ],
            'score' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
            ],
            'max_score' => [
                'nullable',
                'numeric',
                'min:1',
            ],
            'weight' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'assessed_date' => [
                'nullable',
                'date',
            ],
            'notes' => [
                'nullable',
                'string',
            ],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validate student belongs to class
            $studentId = $this->input('student_id');
            $classId = $this->input('class_id');

            $student = \App\Models\Students\Student::where('id', $studentId)
                ->whereNull('deleted_at')
                ->first();

            if ($student && $student->class_id != $classId) {
                $validator->errors()->add('student_id', 'Siswa tidak terdaftar di kelas yang ditentukan.');
            }

            // Validate subject belongs to class
            $subjectId = $this->input('subject_id');
            $classSubject = \App\Models\Academic\ClassSubject::where('class_id', $classId)
                ->where('subject_id', $subjectId)
                ->exists();

            if (!$classSubject) {
                $validator->errors()->add('subject_id', 'Mata pelajaran tidak terdaftar pada kelas yang ditentukan.');
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