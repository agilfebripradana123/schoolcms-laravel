<?php

namespace App\Http\Requests\Api\Academic;

use App\Models\Academic\ClassSubject;
use App\Models\Academic\Semester;
use App\Models\Students\Student;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Explicit trigger for a single-identity grade aggregation (Phase 2L-3 3D).
 *
 * Accepts the exact canonical identity synchronizeScore() needs and nothing
 * else — no score, no category, no source fields, no finalization input. The
 * aggregation formula itself is never accepted from the client.
 */
class AggregateGradeScoreRequest extends FormRequest
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
                Rule::exists('students', 'id')->whereNull('deleted_at'),
            ],
            'subject_id' => [
                'required',
                'integer',
                Rule::exists('subjects', 'id')->whereNull('deleted_at'),
            ],
            'class_id' => [
                'required',
                'integer',
                Rule::exists('classes', 'id')->whereNull('deleted_at'),
            ],
            'academic_year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')->whereNull('deleted_at'),
            ],
            'semester_id' => [
                'required',
                'integer',
                Rule::exists('semesters', 'id'),
            ],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validate student belongs to class (same rule as the assessment service).
            $studentId = $this->input('student_id');
            $classId = $this->input('class_id');

            $student = Student::where('id', $studentId)
                ->whereNull('deleted_at')
                ->first();

            if ($student && $student->class_id != $classId) {
                $validator->errors()->add('student_id', 'Siswa tidak terdaftar di kelas yang ditentukan.');
            }

            // Validate subject belongs to class (same rule as the assessment service).
            $classSubject = ClassSubject::where('class_id', $classId)
                ->where('subject_id', $this->input('subject_id'))
                ->exists();

            if (! $classSubject) {
                $validator->errors()->add('subject_id', 'Mata pelajaran tidak terdaftar pada kelas yang ditentukan.');
            }

            // Validate academic year / semester pairing (same rule as the report card request).
            $semester = Semester::where('id', $this->input('semester_id'))->first();

            if ($semester && (int) $semester->academic_year_id !== (int) $this->input('academic_year_id')) {
                $validator->errors()->add('semester_id', 'The selected semester does not belong to the selected academic year.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validasi failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
