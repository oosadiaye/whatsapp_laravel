<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CallLog;
use App\Models\CallNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallNote>
 */
class CallNoteFactory extends Factory
{
    protected $model = CallNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'call_log_id' => CallLog::factory(),
            'user_id' => User::factory(),
            'body' => $this->faker->sentence(),
        ];
    }
}
