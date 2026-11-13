<?php

namespace Database\Factories;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MeetingAttendee> */
class MeetingAttendeeFactory extends Factory
{
    protected $model = MeetingAttendee::class;

    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'user_id' => null,
            'contact_id' => null,
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'response' => MeetingAttendee::RESPONSE_PENDING,
            'response_token' => Str::random(40),
        ];
    }
}
