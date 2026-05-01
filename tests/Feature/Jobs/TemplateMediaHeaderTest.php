<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for Meta error 132012:
 *   "Parameter format does not match format in the created template
 *    header: Format mismatch, expected IMAGE, received UNKNOWN"
 *
 * The bug: SendWhatsAppMessage::buildTemplateComponents() previously skipped
 * HEADER components entirely. Templates with IMAGE/VIDEO/DOCUMENT headers
 * therefore got a payload with no header parameter, and Meta rejected the send.
 *
 * The fix: include a header component in the send payload when the template
 * defines one, sourcing the URL from campaign.header_media_url. Block
 * campaign creation at form-time when the URL is missing for a media-header
 * template (StoreCampaignRequest pre-flight).
 */
class TemplateMediaHeaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_image_header_template_sends_with_header_component(): void
    {
        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $template = $this->makeImageHeaderTemplate($admin, $instance);

        $contact = Contact::factory()->create(['user_id' => $admin->id, 'phone' => '2348012345678']);
        $campaign = Campaign::factory()->create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'message_template_id' => $template->id,
            'header_media_url' => 'https://cdn.example.com/promo.jpg',
            'status' => 'RUNNING',
        ]);

        $log = MessageLog::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'phone' => $contact->phone,
            'status' => 'PENDING',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.success']]], 200)]);

        SendWhatsAppMessage::dispatch($log, $campaign, $contact);

        // Verify the outbound payload to Meta includes BOTH header (with image link) and body components
        Http::assertSent(function ($request) {
            $body = $request->data();
            if (! isset($body['template']['components'])) {
                return false;
            }

            $components = $body['template']['components'];
            $headerComponent = collect($components)->firstWhere('type', 'header');
            $bodyComponent = collect($components)->firstWhere('type', 'body');

            return $headerComponent !== null
                && $headerComponent['parameters'][0]['type'] === 'image'
                && $headerComponent['parameters'][0]['image']['link'] === 'https://cdn.example.com/promo.jpg'
                && $bodyComponent !== null;
        });

        $log->refresh();
        $this->assertSame('SENT', $log->status);
        $this->assertSame('wamid.success', $log->whatsapp_message_id);
    }

    public function test_video_header_template_sends_with_video_component(): void
    {
        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);

        $template = MessageTemplate::factory()->remote()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'name' => 'video_promo',
            'language' => 'en_US',
            'status' => MessageTemplate::STATUS_APPROVED,
            'components' => [
                ['type' => 'HEADER', 'format' => 'VIDEO'],
                ['type' => 'BODY', 'text' => 'Check this out, {{1}}!'],
            ],
        ]);

        $contact = Contact::factory()->create(['user_id' => $admin->id, 'name' => 'Alice']);
        $campaign = Campaign::factory()->create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'message_template_id' => $template->id,
            'header_media_url' => 'https://cdn.example.com/promo.mp4',
            'status' => 'RUNNING',
        ]);

        $log = MessageLog::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'phone' => $contact->phone,
            'status' => 'PENDING',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.video']]], 200)]);

        SendWhatsAppMessage::dispatch($log, $campaign, $contact);

        Http::assertSent(function ($request) {
            $components = $request->data()['template']['components'] ?? [];
            $header = collect($components)->firstWhere('type', 'header');

            return $header !== null
                && $header['parameters'][0]['type'] === 'video'
                && $header['parameters'][0]['video']['link'] === 'https://cdn.example.com/promo.mp4';
        });
    }

    public function test_text_header_template_with_no_variables_sends_without_header_component(): void
    {
        // Plain TEXT header without {{n}} placeholders requires no header parameters
        // — Meta accepts the send without a header component in the payload.
        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);

        $template = MessageTemplate::factory()->remote()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Welcome!'],  // static, no {{1}}
                ['type' => 'BODY', 'text' => 'Hi {{1}}'],
            ],
        ]);

        $contact = Contact::factory()->create(['user_id' => $admin->id]);
        $campaign = Campaign::factory()->create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'message_template_id' => $template->id,
            'status' => 'RUNNING',
        ]);

        $log = MessageLog::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'phone' => $contact->phone,
            'status' => 'PENDING',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);

        SendWhatsAppMessage::dispatch($log, $campaign, $contact);

        Http::assertSent(function ($request) {
            $components = $request->data()['template']['components'] ?? [];
            $hasHeader = collect($components)->contains('type', 'header');
            $hasBody = collect($components)->contains('type', 'body');

            return ! $hasHeader && $hasBody;  // body only, no header
        });
    }

    public function test_text_header_template_with_variable_personalizes_header(): void
    {
        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);

        $template = MessageTemplate::factory()->remote()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Hi {{1}}!'],
                ['type' => 'BODY', 'text' => 'Body content'],
            ],
        ]);

        $contact = Contact::factory()->create(['user_id' => $admin->id, 'name' => 'Alice']);
        $campaign = Campaign::factory()->create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'message_template_id' => $template->id,
            'status' => 'RUNNING',
        ]);

        $log = MessageLog::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'phone' => $contact->phone,
            'status' => 'PENDING',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);

        SendWhatsAppMessage::dispatch($log, $campaign, $contact);

        Http::assertSent(function ($request) {
            $components = $request->data()['template']['components'] ?? [];
            $header = collect($components)->firstWhere('type', 'header');

            return $header !== null
                && $header['parameters'][0]['type'] === 'text'
                && $header['parameters'][0]['text'] === 'Alice';  // {{1}} resolves to contact name
        });
    }

    public function test_form_blocks_campaign_creation_when_media_header_file_missing(): void
    {
        // Pre-flight guard: Meta would return 132012, but we surface the error
        // at form-submit time instead of letting it 500 during send.
        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $template = $this->makeImageHeaderTemplate($admin, $instance);
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'Test']);

        $this->actingAs($admin)
            ->post(route('campaigns.store'), [
                'name' => 'Promo blast',
                'message' => 'Body',
                'instance_id' => $instance->id,
                'message_template_id' => $template->id,
                'groups' => [$group->id],
                // header_media file INTENTIONALLY OMITTED
            ])
            ->assertSessionHasErrors('header_media');

        $this->assertSame(0, Campaign::count());
    }

    public function test_form_accepts_campaign_when_media_header_file_uploaded(): void
    {
        Storage::fake('public');

        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $template = $this->makeImageHeaderTemplate($admin, $instance);
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'Test']);

        $file = UploadedFile::fake()->image('promo.jpg', 800, 600);

        $this->actingAs($admin)
            ->post(route('campaigns.store'), [
                'name' => 'Promo blast',
                'message' => 'Body',
                'instance_id' => $instance->id,
                'message_template_id' => $template->id,
                'header_media' => $file,
                'groups' => [$group->id],
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $campaign = Campaign::first();
        $this->assertNotNull($campaign);
        $this->assertNotNull($campaign->header_media_url);
        // File was actually persisted on the public disk under campaign-headers/...
        $this->assertStringContainsString('/storage/campaign-headers/', $campaign->header_media_url);

        // Verify the upload landed on disk (Storage::fake intercepts the public disk).
        $relativePath = ltrim(parse_url($campaign->header_media_url, PHP_URL_PATH), '/');
        $relativePath = str_replace('storage/', '', $relativePath);  // /storage/foo.jpg → foo.jpg
        Storage::disk('public')->assertExists($relativePath);
    }

    // ──────────────────────────────────────────────────────────────────────

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    private function makeImageHeaderTemplate(User $owner, WhatsAppInstance $instance): MessageTemplate
    {
        return MessageTemplate::factory()->remote()->create([
            'user_id' => $owner->id,
            'whatsapp_instance_id' => $instance->id,
            'name' => 'product_promo',
            'language' => 'en_US',
            'status' => MessageTemplate::STATUS_APPROVED,
            'components' => [
                ['type' => 'HEADER', 'format' => 'IMAGE'],
                ['type' => 'BODY', 'text' => 'Check out {{1}}!'],
            ],
        ]);
    }
}
