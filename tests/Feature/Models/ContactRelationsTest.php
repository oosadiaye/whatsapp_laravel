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
}
