<?php

namespace App\Http\Requests\Api\Students;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassStudent;
use App\Models\Students\Attendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Academic context is server-authoritative. When the client omits the year
     * (or edits a record whose context it does not restate), fall back to the
     * year persisted on the record being edited so an update never silently
     * re-binds an attendance row to another academic context.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->filled('academic_year_id')) {
            $yearId = Attendance::query()
                ->where('id', $this->route('attendance'))
                ->value('academic_year_id');

            if (!$yearId) {
                $yearId = AcademicYear::where('is_active', true)
                    ->orderBy('id')
                    ->value('id');
            }

            $this->merge(['academic_year_id' => (int) $yearId]);
        }
    }

    public function rules(): array
    {
        $attendanceId = (int) $this->route('attendance');

        return [
            'student_id' => [
                'required',
                'integer',
                'exists:students,id',
                Rule::unique('attendances', 'student_id')
                    ->where(function ($query) {
                        $query->where('class_id', $this->input('class_id'))
                            ->where('date', $this->input('date'))
                            ->where('academic_year_id', $this->input('academic_year_id'));
                    })
                    ->ignore($attendanceId, 'id'),
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