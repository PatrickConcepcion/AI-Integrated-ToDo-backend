<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiChatRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is handled by auth:api middleware,
     * so we just return true here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => [
                'required',
                'string',
                'min:1',
                'max:1000',
            ],
            'context' => [
                'nullable',
                'array',
            ],
            'context.creator_info' => [
                'nullable',
                'array',
            ],
            'context.creator_info.name' => [
                'nullable',
                'string',
            ],
            'context.creator_info.linkedin' => [
                'nullable',
                'string',
                'url',
            ],
            'context.creator_info.note' => [
                'nullable',
                'string',
            ],
        ];
    }

    /**
     * Get custom error messages for validation failures.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Please enter a message.',
            'message.string' => 'Message must be text.',
            'message.min' => 'Message cannot be empty.',
            'message.max' => 'Message cannot exceed 1000 characters.',
        ];
    }
}
