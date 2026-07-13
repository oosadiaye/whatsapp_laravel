<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Voicemail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Voicemail>
 */
class VoicemailFactory extends Factory
{
    protected $model = Voicemail::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_phone' => '+234'.$this->faker->numerify('##########'),
            'recording_url' => 'https://voice.africastalking.com/recordings/'.$this->faker->uuid().'.mp3',
            'duration_seconds' => $this->faker->numberBetween(5, 90),
            'is_heard' => false,
        ];
    }

    public function heard(): self
    {
        return $this->state(['is_heard' => true, 'heard_at' => now()]);
    }
}
