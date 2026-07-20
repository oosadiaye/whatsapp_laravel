<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailAccount>
 */
class EmailAccountFactory extends Factory
{
    protected $model = EmailAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'provider' => EmailAccount::PROVIDER_IMAP,
            'display_name' => $this->faker->name(),
            // Cast is encrypted:array — pass an array; it's encrypted on save.
            'credentials' => ['type' => 'imap', 'password' => 'pw-'.$this->faker->password()],
            'sync_state' => null,
            'is_active' => true,
            'needs_reauth' => false,
        ];
    }
}
