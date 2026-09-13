<?php

namespace App\Http\Requests\Api\Examination;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreExamParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_id' => [
                'required',
                'integer',
                Rule::exists('exams', 'id'),
            ],
            'student_id' => [
                'required',
                'integer',
                Rule::exists('students', 'id'),
            ],
            'exam_card_number' => [
                'required',
                'string',
                'max:30',
                Rule::unique('exam_participants', 'exam_card_number'),
            ],
            'schedule_id' => [
                'nullable',
                'integer',
                Rule::exists('exam_schedules', 'id'),
            ],
            'status' => [
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
                'boolean',
            ],
            'blocked_reason' => [
                'nullable',
                'string',
            ],
            'login_allowed' => [
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
