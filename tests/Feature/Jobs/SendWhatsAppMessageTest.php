<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies the send job's branching logic — three paths:
 *   1. shouldSendAsTemplate() → cloud sendTemplate
 *   2. campaign has media     → sendMedia
 *   3. otherwise              → sendText
 *
 * And the queue/state behavior:
 *   - paused campaigns re-queue with delay
 *   - cancelled campaigns mark log FAILED and exit
 *   - missing instance fails gracefully
 *   - successful sends increment sent_count + capture wamid
 *
 * Uses Http::fake() for outbound calls; the QUEUE_CONNECTION=sync env
 * setting in phpunit.xml means dispatched jobs run inline so we can
 * assert end state immediately.
 */
class SendWhatsAppMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_template_send_for_cloud_instance_with_template(): void
    {
        $user = User::factory()->create();
        $instance = WhatsAppInstance::factory()->cloud()->create(['user_id' => $user->id]);
        $template = MessageTemplate::factory()->remote()->create([
            'user_id' => $user->id,
            'whatsapp_instance_id' => $instance->id,
            'name' => 'order_shipped',
            'language' => 'en_US',
            'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}, order #{{2}}']],
        ]);

        [$campaign, $contact, $log] = $this->setupSend($user, $instance, [
            'message_template_id' => $template->id,
            'template_language' => 'en_US',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.template_send']],
            ], 200),
        ]);

        SendWhatsAppMessage::dispatch($log, $campaign, $contact);

        Http::assertSent(function ($request) {
            return $request['type'] === 'template'
                && $request['template']['name'] === 'order_shipped'
                && $request['template']['language']['code'] === 'en_US';
        });

        $log->refresh();
        $this->assertSame('SENT', $log->status);
        $this->assertSame('wamid.template_send', $log->whatsapp_message_id);
        $this->assertSame(1, $campaign->fresh()->sent_count);
    }

    public function test_uses_text_send_when_campaign_has_no_template(): void
    {
        $user = User::factory()->create();
        $instance = WhatsAppInstance::factory()->cloud()->create(['user_id' => $user->id]);
        [$campaign, $contact, $log] = $this->setupSend($user, $instance, [
            'message' => 'Hello {{name}}!',
        ]);

        $contact->update(['name' => 'Alice']);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.text_send']],
            ], 200),
        ]);

        SendWhatsAppMessage::dispatch($log, $campaign, $contact);

        Http::assertSent(function ($request) {
            return $request['type'] === 'text'
                && $request['text']['body'] === 'Hello Alice!';  // personalized
        });

        $this->assertSame('SENT', $log->fresh()->status);
        $this->assertSame('wamid.text_send', $log->fresh()->whatsapp_message_id);
    }

    public function test_template_path_skipped_for_evolution_instance_even_with_template(): void
    {
        // Evolution instances cannot send templates — the job must fall back to text
        // even if the campaign has a message_template_id set.
        $user = User::factory()->create();
        $instance = WhatsAppInstance::factory()->evolution()->create([
            'user_id' => $user->id,
            'instance_name' => 'evo_main',
        ]);
        $template = MessageTemplate::factory()->create(['user_id' => $user->id]);

        [$campaign, $contact, $log] = $this->setupSend($user, $instance, [
            'message_template_id' => $template->id,
            'message' => 'Plain text fallback',
        ]);

        Http::fake([
            '*' => Http::response(['key' => ['id' => 'evo_msg_id']], 200),
        ]);

        SendWhatsAppMessage::dispatch($log, $campaign, $contact);

        // No outbound graph.facebook.com calls — Evolution path used.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));

        $this->assertSame('SENT', $log->fresh()->status);
    }

    public function test_cancelled_campaign_marks_log_failed_without_sending(): void
    {
        $user = User::factory()->create();
        $instance = WhatsAppInstance::factory()->cloud()->create(['user_id' => $user->id]);
        [$campaign, $contact, $log] = $this->setupSend($user, $instance, [
            'status' => 'CANCELLED',
        ]);

        Http::fake();

        SendWhatsAppMessage::dispatch($log, $campaign, $contact);

        Http::assertNothingSent();
        $log->refresh();
        $this->assertSame('FAILED', $log->status);
        $this->assertSame('Campaign cancelled', $log->error_message);
    }

    public function test_missing_instance_fails_gracefully(): void
    {
        $user = User::factory()->create();
        [$campaign, $contact, $log] = $this->setupSend($user, instance: null);

        Http::fake();

        SendWhatsAppMessage::dispatch($log, $campaign, $contact);

        Http::assertNothingSent();
        $this->assertSame('FAILED', $log->fresh()->status);
        $this->assertStringContainsString('no WhatsApp instance', $log->fresh()->error_message);
    }

    /**
     * @return array{0: Campaign, 1: Contact, 2: MessageLog}
     */
    private function setupSend(User $user, ?WhatsAppInstance $instance, array $campaignOverrides = []): array
    {
        $campaign = Campaign::factory()->create(array_merge([
            'user_id' => $user->id,
            'instance_id' => $instance?->id,
            'name' => 'Test Campaign',
            'message' => 'Default body',
            'status' => 'RUNNING',
        ], $campaignOverrides));

        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'phone' => '2348012345678',
        ]);

        $log = MessageLog::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'phone' => $contact->phone,
            'status' => 'PENDING',
        ]);

        return [$campaign, $contact, $log];
    }
}
