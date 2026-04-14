<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportContactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required_without:manual_input', 'file', 'max:10240', 'mimes:csv,txt,xlsx,xls'],
            'manual_input' => ['required_without:file', 'nullable', 'string'],
            'group_id' => ['required', 'exists:contact_groups,id'],
            'column_map' => ['nullable', 'array'],
        ];
    }
}
