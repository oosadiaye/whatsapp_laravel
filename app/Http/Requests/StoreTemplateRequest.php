<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Template name must be lowercase letters, numbers, and underscores only (Meta requirement).',
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/'],
            'content' => ['required', 'string'],
            'category' => ['required', 'in:promotional,transactional,reminder'],
            'language' => ['nullable', 'string', 'max:16'],
            'media' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,gif,pdf,mp3,ogg'],
        ];
    }
}
