<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailCampaign>
 */
class EmailCampaignFactory extends Factory
{
    protected $model = EmailCampaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(3, true),
            'subject' => $this->faker->sentence(),
            'from_name' => $this->faker->company(),
            'reply_to' => $this->faker->safeEmail(),
            'body_html' => '<p>'.$this->faker->paragraph().'</p>',
            'status' => EmailCampaign::STATUS_DRAFT,
            'recurrence' => EmailCampaign::RECURRENCE_NONE,
            'rate_per_minute' => 60,
        ];
    }

    public function scheduled(?\DateTimeInterface $at = null): self
    {
        return $this->state([
            'status' => EmailCampaign::STATUS_SCHEDULED,
            'scheduled_at' => $at ?? now()->addHour(),
        ]);
    }
}
