<?php

namespace App\Http\Requests\Api\Examination;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

use App\Models\Examination\QuestionBank;

class UpdateQuestionRequest extends FormRequest
{
    use ValidatesQuestionOptions;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = $this->effectiveType();

        $rules = [
            'subject_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('subjects', 'id')->whereNull('deleted_at'),
            ],
            'instruction_id' => [
                'nullable',
                'integer',
                Rule::exists('exam_instructions', 'id'),
            ],
            'owner_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
            ],
            'question_text' => [
                'sometimes',
                'required',
                'string',
                'max:10000',
            ],
            'question_image' => [
                'nullable',
                'string',
                'max:255',
            ],
            'audio_url' => [
                'nullable',
                'string',
                'max:500',
            ],
            'video_url' => [
                'nullable',
                'string',
                'max:500',
            ],
            'type' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['multiple_choice', 'true_false', 'essay']),
            ],
            'difficulty' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['easy', 'medium', 'hard']),
            ],
            'cognitive_level' => [
                'nullable',
                'string',
                'max:20',
            ],
            'competency' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'indicator' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'explanation' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'points' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:1000',
            ],
            'status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['draft', 'approved', 'archived']),
            ],
            'options' => [
                'nullable',
                'array',
                'min:0',
            ],
            'options.*.option_text' => [
                'required_with:options',
                'string',
                'max:5000',
            ],
            'options.*.option_image' => [
                'nullable',
                'string',
                'max:255',
            ],
            'options.*.is_correct' => [
                'required_with:options',
                'boolean',
            ],
        ];

        if ($type === 'multiple_choice') {
            $rules['options'] = ['required', 'array', 'min:2'];
        } elseif ($type === 'true_false') {
            $rules['options'] = ['required', 'array', 'size:2'];
        } elseif ($type === 'essay') {
            $rules['options'] = ['nullable', 'array', 'size:0'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateQuestionOptionInvariants(
                $validator,
                $this->effectiveType(),
                (array) $this->input('options', [])
            );
        });
    }

    /**
     * Effective question type for invariant checks.
     *
     * An update payload may omit `type` (PATCH-style metadata edit). In that
     * case the option invariants must still be validated against the persisted
     * type — otherwise a client could bypass the exactly-one-correct / count
     * rules simply by not sending `type`.
     */
    private function effectiveType(): ?string
    {
        $type = $this->input('type');
        if ($type !== null) {
            return $type;
        }

        if (! $this->has('options')) {
            return null;
        }

        $id = $this->route('id');
        if ($id === null) {
            return null;
        }

        return QuestionBank::withTrashed()->find((int) $id)?->type;
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