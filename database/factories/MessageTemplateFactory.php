<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageTemplate>
 */
class MessageTemplateFactory extends Factory
{
    protected $model = MessageTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->unique()->slug(2, false), // Meta requires lowercase + underscores; faker slug fits
            'content' => 'Hello {{1}}, your order #{{2}} is on its way.',
            'category' => 'transactional',
            'language' => 'en_US',
            'status' => MessageTemplate::STATUS_LOCAL,
            'whatsapp_template_id' => null,
            'whatsapp_instance_id' => null,
        ];
    }

    /** Mark as a Meta-managed remote template (APPROVED by default). */
    public function remote(): self
    {
        return $this->state([
            'status' => MessageTemplate::STATUS_APPROVED,
            'whatsapp_template_id' => $this->faker->numerify('############'),
            'components' => [
                ['type' => 'BODY', 'text' => 'Hello {{1}}, your order #{{2}} is on its way.'],
            ],
            'synced_at' => now(),
        ]);
    }
}
