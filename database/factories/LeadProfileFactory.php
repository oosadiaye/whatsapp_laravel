<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeadProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadProfile>
 */
class LeadProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Profile '.fake()->unique()->numberBetween(1, 999999),
            'is_active' => false,
            'brief' => 'Founders and CEOs of small fintech companies in Lagos.',
            'hard_filters' => [],
            'sources' => ['apollo'],
            'lead_query' => null,
            'daily_pull_cap' => 50,
        ];
    }

    public function withQuery(array $query = ['titles' => ['CEO']]): static
    {
        return $this->state(fn () => ['lead_query' => $query]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }
}
