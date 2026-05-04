<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_exposes_conversation_messages_through_conversations(): void
    {
        $conv = Conversation::factory()->create();
        ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => 'inbound',
            'whatsapp_message_id' => 'wamid.1',
            'type' => 'text',
            'body' => 'hello',
            'received_at' => now(),
        ]);

        $messages = $conv->contact->conversationMessages;

        $this->assertCount(1, $messages);
        $this->assertSame('hello', $messages->first()->body);
    }

    public function test_contact_exposes_call_logs_through_conversations(): void
    {
        $conv = Conversation::factory()->create();
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $conv->whatsapp_instance_id,
        ]);

        $this->assertCount(1, $conv->contact->callLogs);
    }

    public function test_isEngaged_returns_false_for_contact_with_no_recent_activity(): void
    {
        $contact = Contact::factory()->create();

        $this->assertFalse($contact->isEngaged());
    }

    public function test_isEngaged_returns_true_when_contact_messaged_within_window(): void
    {
        $conv = Conversation::factory()->create();
        ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => 'inbound',
            'whatsapp_message_id' => 'wamid.recent',
            'type' => 'text',
            'body' => 'hi',
            'received_at' => now()->subDays(5),
        ]);

        $this->assertTrue($conv->contact->isEngaged());
    }

    public function test_isEngaged_returns_false_when_only_old_messages_exist(): void
    {
        $conv = Conversation::factory()->create();
        ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => 'inbound',
            'whatsapp_message_id' => 'wamid.old',
            'type' => 'text',
            'body' => 'old',
            'received_at' => now()->subDays(45),
        ]);

        $this->assertFalse($conv->contact->isEngaged());
    }

    public function test_isEngaged_returns_true_when_inbound_call_within_window(): void
    {
        $conv = Conversation::factory()->create();
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $conv->whatsapp_instance_id,
            'direction' => CallLog::DIRECTION_INBOUND,
            'created_at' => now()->subDays(10),
        ]);

        $this->assertTrue($conv->contact->isEngaged());
    }

    public function test_isEngaged_only_counts_inbound_messages_not_outbound(): void
    {
        $conv = Conversation::factory()->create();
        ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => 'outbound',
            'whatsapp_message_id' => 'wamid.out',
            'type' => 'text',
            'body' => 'we wrote first',
            'sent_at' => now()->subDays(2),
            'received_at' => now()->subDays(2),
        ]);

        $this->assertFalse($conv->contact->isEngaged());
    }
}
