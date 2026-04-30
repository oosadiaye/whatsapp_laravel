<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallLog>
 */
class CallLogFactory extends Factory
{
    protected $model = CallLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'conversation_id' => Conversation::factory(),
            'contact_id' => Contact::factory()->state(['user_id' => $user]),
            'whatsapp_instance_id' => WhatsAppInstance::factory()->state(['user_id' => $user]),
            'direction' => CallLog::DIRECTION_INBOUND,
            'meta_call_id' => 'wacid.'.$this->faker->unique()->bothify('???###??'),
            'status' => CallLog::STATUS_ENDED,
            'from_phone' => $this->faker->numerify('234##########'),
            'to_phone' => $this->faker->numerify('234##########'),
            'started_at' => now()->subMinutes(5),
            'connected_at' => now()->subMinutes(4),
            'ended_at' => now()->subMinutes(2),
            'duration_seconds' => 120,
            'failure_reason' => null,
            'placed_by_user_id' => null,
            'raw_event_log' => [],
        ];
    }

    public function inFlight(): self
    {
        return $this->state([
            'status' => CallLog::STATUS_RINGING,
            'connected_at' => null,
            'ended_at' => null,
            'duration_seconds' => null,
        ]);
    }

    public function outbound(?User $placedBy = null): self
    {
        return $this->state([
            'direction' => CallLog::DIRECTION_OUTBOUND,
            'placed_by_user_id' => $placedBy?->id ?? User::factory(),
        ]);
    }

    public function missed(): self
    {
        return $this->state([
            'status' => CallLog::STATUS_MISSED,
            'connected_at' => null,
            'duration_seconds' => 0,
            'ended_at' => now(),
        ]);
    }
}
