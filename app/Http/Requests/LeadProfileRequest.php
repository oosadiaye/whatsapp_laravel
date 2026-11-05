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
