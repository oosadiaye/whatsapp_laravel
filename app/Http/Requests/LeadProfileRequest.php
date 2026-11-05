<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Leads\LeadSourceRegistry;
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
