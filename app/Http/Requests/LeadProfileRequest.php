<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EmailAccount;
use App\Services\Leads\LeadSourceRegistry;
use App\Services\Leads\Outreach\AutonomyPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware (permission:leads.manage) is the gate.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'brief' => ['required', 'string', 'min:20', 'max:2000'],
            'sources' => ['required', 'array', 'min:1'],
            'sources.*' => ['string', Rule::in(app(LeadSourceRegistry::class)->keys())],
            'daily_pull_cap' => ['required', 'integer', 'min:1', 'max:500'],
            'hot_threshold' => [
                'nullable', 'integer', 'min:1', 'max:100',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    // Compare with the warm value sent in this request, else with the stored one.
                    $warm = $this->input('warm_threshold');
                    if ($warm === null || $warm === '') {
                        $warm = $this->route('leadProfile')?->warm_threshold;
                    }
                    if ($warm !== null && (int) $value < (int) $warm) {
                        $fail('The hot threshold cannot be lower than the warm threshold.');
                    }
                },
            ],
            'warm_threshold' => [
                'nullable', 'integer', 'min:0', 'max:100',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    // Compare with the hot value sent in this request, else with the stored one.
                    $hot = $this->input('hot_threshold');
                    if ($hot === null || $hot === '') {
                        $hot = $this->route('leadProfile')?->hot_threshold;
                    }
                    if ($hot !== null && (int) $value > (int) $hot) {
                        $fail('The warm threshold cannot be higher than the hot threshold.');
                    }
                },
            ],
            'daily_qualify_cap' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'monthly_reveal_budget' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'autonomy' => ['nullable', 'string', Rule::in(AutonomyPolicy::LEVELS)],
            'sender_email_account_id' => ['nullable', 'integer', $this->mailboxIsAllowed(...)],
            'playbook' => ['nullable', 'string', 'max:3000'],
            'followup_count' => ['nullable', 'integer', 'min:0', 'max:5'],
            'followup_delay_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'daily_send_cap' => ['nullable', 'integer', 'min:1', 'max:500'],
            'hf_countries' => ['nullable', 'string', 'max:500'],
            'hf_titles_include' => ['nullable', 'string', 'max:500'],
            'hf_titles_exclude' => ['nullable', 'string', 'max:500'],
            'hf_employee_ranges' => ['nullable', 'string', 'max:200', 'regex:/^\s*\d{1,6}\s*,\s*\d{1,6}\s*(;\s*\d{1,6}\s*,\s*\d{1,6}\s*)*$/', $this->rangesAreOrdered(...)],
        ];
    }

    /**
     * The hard filters as the stored array shape (see LeadQuery::withHardFilters).
     *
     * @return array{countries: list<string>, titles_include: list<string>, titles_exclude: list<string>, employee_ranges: list<string>, require_verified_email: bool}
     */
    public function hardFilters(): array
    {
        return [
            'countries' => $this->list('hf_countries'),
            'titles_include' => $this->list('hf_titles_include'),
            'titles_exclude' => $this->list('hf_titles_exclude'),
            'employee_ranges' => array_values(array_filter(array_map(
                fn (string $range): string => (string) preg_replace('/\s+/', '', $range),
                explode(';', (string) $this->input('hf_employee_ranges')),
            ), fn (string $range): bool => $range !== '')),
            'require_verified_email' => $this->boolean('hf_require_verified_email'),
        ];
    }

    /**
     * Only the qualification fields the form actually sent, as integers. Omitted
     * fields are left out so create uses the column defaults and update keeps values.
     *
     * @return array<string, int>
     */
    public function qualification(): array
    {
        $out = [];
        foreach (['hot_threshold', 'warm_threshold', 'daily_qualify_cap', 'monthly_reveal_budget'] as $field) {
            if ($this->filled($field) || $this->input($field) === '0' || $this->input($field) === 0) {
                $out[$field] = (int) $this->input($field);
            }
        }

        return $out;
    }

    /**
     * Outreach settings the form sent. An empty autonomy means "use the global default"; an empty mailbox
     * clears it. Omitted numeric fields are left out so create uses the column defaults.
     *
     * @return array<string, mixed>
     */
    public function outreach(): array
    {
        $out = [];

        if ($this->has('autonomy')) {
            $out['autonomy'] = $this->filled('autonomy') ? (string) $this->input('autonomy') : null;
        }
        if ($this->has('sender_email_account_id')) {
            $out['sender_email_account_id'] = $this->filled('sender_email_account_id') ? (int) $this->input('sender_email_account_id') : null;
        }
        if ($this->has('playbook')) {
            $out['playbook'] = trim((string) $this->input('playbook')) ?: null;
        }
        foreach (['followup_count', 'followup_delay_days', 'daily_send_cap'] as $field) {
            if ($this->filled($field) || $this->input($field) === '0') {
                $out[$field] = (int) $this->input($field);
            }
        }

        return $out;
    }

    /**
     * A profile may send only from an active mailbox of the person saving it (or the one it already uses),
     * so nobody can send as a colleague by editing a form field.
     */
    private function mailboxIsAllowed(string $attribute, mixed $value, \Closure $fail): void
    {
        $account = EmailAccount::query()->whereKey((int) $value)->where('is_active', true)->first();
        $alreadyUsed = $this->route('leadProfile')?->sender_email_account_id === (int) $value;

        if ($account === null || ( ! $alreadyUsed && $account->user_id !== $this->user()?->id)) {
            $fail('Choose one of your own connected mailboxes.');
        }
    }

    /**
     * Each "min,max" pair must have min <= max.
     */
    private function rangesAreOrdered(string $attribute, mixed $value, \Closure $fail): void
    {
        foreach (explode(';', (string) $value) as $pair) {
            [$min, $max] = array_pad(array_map('intval', explode(',', $pair)), 2, 0);
            if ($min > $max) {
                $fail('Each company size range must have its minimum no larger than its maximum.');

                return;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function list(string $field): array
    {
        $parts = preg_split('/[,\n]+/', (string) $this->input($field)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));
    }
}
