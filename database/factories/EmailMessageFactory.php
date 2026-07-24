<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    protected $model = EmailMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_thread_id' => EmailThread::factory(),
            // Keep the message's account consistent with its thread's account.
            'email_account_id' => fn (array $attrs) => EmailThread::find($attrs['email_thread_id'])?->email_account_id
                ?? EmailAccount::factory(),
            'direction' => EmailMessage::DIRECTION_INBOUND,
            'message_id' => '<'.$this->faker->uuid().'@example.com>',
            'from_email' => $this->faker->safeEmail(),
            'to' => [$this->faker->safeEmail()],
            'subject' => $this->faker->sentence(),
            'body_text' => $this->faker->paragraph(),
            'body_html' => '<p>'.$this->faker->paragraph().'</p>',
            'is_read' => false,
            'received_at' => now(),
        ];
    }
}
