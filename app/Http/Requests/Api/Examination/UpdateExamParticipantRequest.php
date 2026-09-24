<?php

namespace App\Http\Requests\Api\Examination;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateExamParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('exams', 'id'),
            ],
            'student_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('students', 'id'),
            ],
            'exam_card_number' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                Rule::unique('exam_participants', 'exam_card_number')
                    ->ignore($this->route('exam_participant')),
            ],
            'schedule_id' => [
                'nullable',
                'integer',
                $this->input('exam_id')
                    ? Rule::exists('exam_schedules', 'id')->where(function ($query) {
                        $query->where('exam_id', $this->input('exam_id'));
                    })
                    : Rule::exists('exam_schedules', 'id'),
            ],
            'status' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['registered', 'started', 'completed', 'blocked']),
            ],
            'attendance' => [
                'nullable',
                'string',
                Rule::in(['pending', 'present', 'absent', 'excused']),
            ],
            'started_at' => [
                'nullable',
                'date',
            ],
            'completed_at' => [
                'nullable',
                'date',
            ],
            'is_blocked' => [
                'sometimes',
                'boolean',
            ],
            'blocked_reason' => [
                'nullable',
                'string',
            ],
            'login_allowed' => [
                'sometimes',
                'boolean',
            ],
            'current_session_id' => [
                'nullable',
                'integer',
            ],
            'last_activity_at' => [
                'nullable',
                'date',
            ],
            'ip_address' => [
                'nullable',
                'string',
                'max:45',
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
