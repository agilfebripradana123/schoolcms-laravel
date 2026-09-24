<?php

namespace App\Http\Requests\Api\Students;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassStudent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Academic context is server-authoritative: when the client omits it, use
     * the active academic year (the codebase's established default resolution,
     * cf. TeacherGradeController). The persisted row always carries the year.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->filled('academic_year_id')) {
            $this->merge([
                'academic_year_id' => (int) AcademicYear::where('is_active', true)
                    ->orderBy('id')
                    ->value('id'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'student_id' => [
                'required',
                'integer',
                'exists:students,id',
                Rule::unique('attendances', 'student_id')->where(function ($query) {
                    $query->where('class_id', $this->input('class_id'))
                        ->where('date', $this->input('date'))
                        ->where('academic_year_id', $this->input('academic_year_id'));
                }),
            ],
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'academic_year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id'),
            ],
            'date' => ['required', 'date'],
            'status' => [
                'required',
                Rule::in(['hadir', 'sakit', 'izin', 'alpa']),
            ],
            'note' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $yearId = (int) $this->input('academic_year_id');

            if ($yearId && $this->input('student_id') && $this->input('class_id')) {
                $isMember = ClassStudent::where('student_id', $this->input('student_id'))
                    ->where('class_id', $this->input('class_id'))
                    ->where('academic_year_id', $yearId)
                    ->exists();

                if (!$isMember) {
                    $validator->errors()->add(
                        'student_id',
                        'Siswa bukan anggota kelas pada tahun ajaran tersebut.'
                    );
                }
            }
        });
    }
}