<?php

namespace Database\Factories;

use App\Models\GoogleConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoogleConnection> */
class GoogleConnectionFactory extends Factory
{
    protected $model = GoogleConnection::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'google_email' => fake()->unique()->safeEmail(),
            'access_token' => 'access-'.fake()->uuid(),
            'refresh_token' => 'refresh-'.fake()->uuid(),
            'expires_at' => now()->addHour(),
            'scopes' => 'openid email https://www.googleapis.com/auth/calendar.events',
            'status' => GoogleConnection::STATUS_ACTIVE,
        ];
    }
}
