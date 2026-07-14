<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmailCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'body_html' => ['required', 'string'],
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['exists:contact_groups,id'],
            'rate_per_minute' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'recurrence' => ['nullable', 'in:none,daily,weekly,monthly'],
            'recurrence_until' => ['nullable', 'date', 'after:scheduled_at'],
            // Which button the user pressed: save draft / schedule / send now.
            'action' => ['nullable', 'in:draft,schedule,send'],
        ];
    }
}
