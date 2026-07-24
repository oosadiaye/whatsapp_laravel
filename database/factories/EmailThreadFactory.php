<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailAccount;
use App\Models\EmailThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailThread>
 */
class EmailThreadFactory extends Factory
{
    protected $model = EmailThread::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_account_id' => EmailAccount::factory(),
            'subject' => $this->faker->sentence(),
            'last_message_at' => now(),
            'unread_count' => 0,
            'folder' => EmailThread::FOLDER_INBOX,
            'thread_ref' => $this->faker->uuid(),
        ];
    }
}
