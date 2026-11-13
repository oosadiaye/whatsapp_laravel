<?php

namespace Database\Factories;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Meeting> */
class MeetingFactory extends Factory
{
    protected $model = Meeting::class;

    public function definition(): array
    {
        $start = now()->addDay()->startOfHour();

        return [
            'organizer_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => null,
            'location' => null,
            'video_url' => null,
            'starts_at' => $start,
            'ends_at' => $start->clone()->addHour(),
            'status' => Meeting::STATUS_SCHEDULED,
            'uid' => (string) Str::uuid(),
            'sequence' => 0,
        ];
    }
}
