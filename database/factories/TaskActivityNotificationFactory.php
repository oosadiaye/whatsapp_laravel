<?php

namespace Database\Factories;

use App\Models\TaskActivityNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskActivityNotification>
 */
class TaskActivityNotificationFactory extends Factory
{
    protected $model = TaskActivityNotification::class;

    public function definition(): array
    {
        return [
            // Required, no default: a ledger row without both ids asserts
            // nothing. See TaskActivityFactory for the same reasoning.
            'activity_id' => null,
            'user_id' => null,
            'sent_at' => now(),
        ];
    }

    public function forDelivery(int $activityId, int $userId): static
    {
        return $this->state(fn (): array => [
            'activity_id' => $activityId,
            'user_id' => $userId,
        ]);
    }
}
