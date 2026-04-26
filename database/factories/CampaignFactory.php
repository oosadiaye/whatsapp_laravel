<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->catchPhrase(),
            'message' => $this->faker->paragraph(),
            'status' => 'DRAFT',
            'rate_per_minute' => 10,
            'delay_min' => 2,
            'delay_max' => 8,
            'total_contacts' => 0,
            'sent_count' => 0,
            'delivered_count' => 0,
            'read_count' => 0,
            'failed_count' => 0,
        ];
    }
}
