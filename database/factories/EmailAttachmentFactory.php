<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailAttachment>
 */
class EmailAttachmentFactory extends Factory
{
    protected $model = EmailAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_message_id' => EmailMessage::factory(),
            'filename' => $this->faker->word().'.pdf',
            'mime' => 'application/pdf',
            'size' => $this->faker->numberBetween(1000, 500000),
            'path' => 'email-attachments/'.$this->faker->uuid().'.pdf',
        ];
    }
}
