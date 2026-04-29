<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'contact_id' => Contact::factory()->state(['user_id' => $user]),
            'whatsapp_instance_id' => WhatsAppInstance::factory()->state(['user_id' => $user]),
            'assigned_to_user_id' => null,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'unread_count' => 0,
        ];
    }

    /** Window-expired (last inbound > 24h ago). */
    public function windowClosed(): self
    {
        return $this->state(['last_inbound_at' => now()->subHours(25)]);
    }

    /** Assigned to a specific user. */
    public function assignedTo(User $user): self
    {
        return $this->state(['assigned_to_user_id' => $user->id]);
    }
}
