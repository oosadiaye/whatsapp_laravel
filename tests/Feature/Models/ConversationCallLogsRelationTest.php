<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\CallLog;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationCallLogsRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_exposes_call_logs_relation(): void
    {
        $conv = Conversation::factory()->create();
        CallLog::factory()->count(3)->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $conv->whatsapp_instance_id,
        ]);

        $this->assertCount(3, $conv->callLogs);
    }
}
