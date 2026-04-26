<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\WhatsAppInstance;
use App\Services\WhatsAppCloudApiService;
use App\Services\WhatsAppMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Verifies WhatsAppMessenger normalizes WhatsAppCloudApiService responses
 * into the unified SendResult DTO.
 *
 * Mocks the underlying Cloud service so no HTTP happens — the messenger's
 * job is pure response-shape unification, not network logic.
 */
class WhatsAppMessengerTest extends TestCase
{
    use RefreshDatabase;

    /** @var MockInterface&WhatsAppCloudApiService */
    private MockInterface $cloud;

    private WhatsAppMessenger $messenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cloud = Mockery::mock(WhatsAppCloudApiService::class);
        $this->messenger = new WhatsAppMessenger($this->cloud);
    }

    public function test_send_text_extracts_message_id_from_cloud_response(): void
    {
        $instance = WhatsAppInstance::factory()->create();

        $this->cloud->expects('sendText')
            ->with($instance, '234801', 'hi')
            ->andReturn(['messages' => [['id' => 'wamid.cloud_id']]]);

        $result = $this->messenger->sendText($instance, '234801', 'hi');

        $this->assertSame('wamid.cloud_id', $result->messageId);
    }

    public function test_send_template_passes_components_through(): void
    {
        $instance = WhatsAppInstance::factory()->create();
        $components = [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Jane']]],
        ];

        $this->cloud->expects('sendTemplate')
            ->with($instance, '234', 'welcome_v1', 'en_US', $components)
            ->andReturn(['messages' => [['id' => 'wamid.tpl']]]);

        $result = $this->messenger->sendTemplate($instance, '234', 'welcome_v1', 'en_US', $components);

        $this->assertSame('wamid.tpl', $result->messageId);
    }

    public function test_send_media_normalizes_response(): void
    {
        $instance = WhatsAppInstance::factory()->create();

        $this->cloud->expects('sendMedia')
            ->with($instance, '234', 'https://cdn/x.jpg', 'image', 'a caption')
            ->andReturn(['messages' => [['id' => 'wamid.media']]]);

        $result = $this->messenger->sendMedia($instance, '234', 'a caption', 'https://cdn/x.jpg', 'image');

        $this->assertSame('wamid.media', $result->messageId);
    }

    public function test_message_id_extraction_handles_missing_id_gracefully(): void
    {
        // Cloud API returned 200 but with no id (rare; possible during outages).
        $instance = WhatsAppInstance::factory()->create();

        $this->cloud->expects('sendText')->andReturn([]);

        $result = $this->messenger->sendText($instance, '234', 'hi');

        $this->assertNull($result->messageId);
        $this->assertSame([], $result->raw);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
