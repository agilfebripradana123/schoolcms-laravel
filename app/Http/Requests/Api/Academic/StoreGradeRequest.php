<?php

namespace App\Http\Requests\Api\Academic;

use App\Models\Academic\ClassSubject;
use App\Models\Academic\Grade;
use App\Models\Students\Student;
use App\Services\Academic\GradePeriodResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreGradeRequest extends FormRequest
{
    /** @var array{academic_year_id:int, semester_id:int, academic_year:string, semester:string}|null */
    private ?array $period = null;

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
            'type' => [
                'required',
                'string',
                Rule::in(['tugas', 'uts', 'uas']),
            ],
            'score' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
            ],
            'semester_id' => [
                'nullable',
                'integer',
                Rule::exists('semesters', 'id'),
            ],
            'academic_year_id' => [
                'nullable',
                'integer',
                Rule::exists('academic_years', 'id')->whereNull('deleted_at'),
            ],
            'semester' => [
                'nullable',
                'string',
                Rule::in(['1', '2']),
            ],
            'academic_year' => [
                'nullable',
                'string',
                'regex:/^\d{4}\/\d{4}$/',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->any()) {
                return;
            }

            $this->resolvePeriod($validator);

            if ($this->period === null) {
                return;
            }

            $studentId = $this->input('student_id');
            $subjectId = $this->input('subject_id');
            $classId = $this->input('class_id');

            $student = Student::where('id', $studentId)->whereNull('deleted_at')->first();

            if (!$student) {
                $validator->errors()->add('student_id', 'The selected student is invalid.');
                return;
            }

            if (is_null($student->class_id)) {
                $validator->errors()->add('student_id', 'The selected student has not been assigned to a class.');
                return;
            }

            if ($student->class_id != $classId) {
                $validator->errors()->add('student_id', 'The selected student does not belong to the specified class.');
                return;
            }

            $classSubjectExists = ClassSubject::where('class_id', $classId)
                ->where('subject_id', $subjectId)
                ->exists();

            if (!$classSubjectExists) {
                $validator->errors()->add('subject_id', 'The selected subject is not assigned to the specified class.');
                return;
            }

            $exists = Grade::where('student_id', $studentId)
                ->where('subject_id', $subjectId)
                ->where('class_id', $classId)
                ->where('type', $this->input('type'))
                ->where('semester_id', $this->period['semester_id'])
                ->where('academic_year_id', $this->period['academic_year_id'])
                ->exists();

            if ($exists) {
                $validator->errors()->add('student_id', 'A grade for this student, subject, class, type, semester, and academic year already exists.');
            }
        });
    }

    /**
     * The canonical period resolved from the validated payload.
     *
     * @return array{academic_year_id:int, semester_id:int, academic_year:string, semester:string}
     */
    public function period(): array
    {
        return $this->period ?? [
            'academic_year_id' => 0,
            'semester_id' => 0,
            'academic_year' => '',
            'semester' => '',
        ];
    }

    private function resolvePeriod($validator): void
    {
        $resolver = GradePeriodResolver::from($this->input());
        $period = $resolver->resolve();
        $errors = $resolver->errors();

        if (!empty($errors)) {
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }

            return;
        }

        $this->period = $period;
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