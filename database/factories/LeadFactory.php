<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_profile_id' => null,
            'source' => 'apollo',
            'source_id' => uniqid('src_', true),
            'email' => null,
            'email_status' => Lead::EMAIL_UNREVEALED,
            'name' => fake()->name(),
            'title' => 'CEO',
            'company' => fake()->company(),
            'status' => Lead::STATUS_NEW,
        ];
    }
}
