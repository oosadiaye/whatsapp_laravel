<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCampaignRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'instance_id' => ['nullable', 'exists:whatsapp_instances,id'],
            'message_template_id' => ['nullable', 'exists:message_templates,id'],
            'template_language' => ['nullable', 'string', 'max:16'],
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['exists:contact_groups,id'],
            'media' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,gif,pdf,mp3,ogg'],
            'rate_per_minute' => ['integer', 'min:1', 'max:60'],
            'delay_min' => ['integer', 'min:1', 'max:30'],
            'delay_max' => ['integer', 'min:1', 'max:60'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'status' => ['nullable', 'in:DRAFT,QUEUED'],
        ];
    }
}
