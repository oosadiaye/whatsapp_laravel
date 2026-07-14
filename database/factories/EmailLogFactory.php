<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailCampaign;
use App\Models\EmailLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailLog>
 */
class EmailLogFactory extends Factory
{
    protected $model = EmailLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_campaign_id' => EmailCampaign::factory(),
            'email' => $this->faker->safeEmail(),
            'status' => EmailLog::STATUS_QUEUED,
        ];
    }
}
