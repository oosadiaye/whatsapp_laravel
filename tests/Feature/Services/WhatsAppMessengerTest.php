<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\WhatsAppApiException;
use App\Models\WhatsAppInstance;
use App\Services\EvolutionApiService;
use App\Services\WhatsAppCloudApiService;
use App\Services\WhatsAppMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Verifies the driver dispatcher routes to the right underlying service based
 * on instance->driver, and that the SendResult DTO normalizes both providers'
 * different response shapes into a single contract.
 *
 * Mocks both underlying services so we don't hit any HTTP — the dispatcher's
 * job is pure routing + response shape unification.
 */
class WhatsAppMessengerTest extends TestCase
{
    use RefreshDatabase;

    /** @var MockInterface&WhatsAppCloudApiService */
    private MockInterface $cloud;

    /** @var MockInterface&EvolutionApiService */
    private MockInterface $evolution;

    private WhatsAppMessenger $messenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cloud = Mockery::mock(WhatsAppCloudApiService::class);
        $this->evolution = Mockery::mock(EvolutionApiService::class);

        $this->messenger = new WhatsAppMessenger($this->cloud, $this->evolution);
    }

    public function test_send_text_dispatches_to_cloud_for_cloud_driver(): void
    {
        $instance = WhatsAppInstance::factory()->cloud()->create();

        $this->cloud->expects('sendText')
            ->with($instance, '234801', 'hi')
            ->andReturn(['messages' => [['id' => 'wamid.cloud_id']]]);

        $this->evolution->shouldNotReceive('sendText');

        $result = $this->messenger->sendText($instance, '234801', 'hi');

        $this->assertSame('wamid.cloud_id', $result->messageId);
    }

    public function test_send_text_dispatches_to_evolution_for_evolution_driver(): void
    {
        $instance = WhatsAppInstance::factory()->evolution()->create([
            'instance_name' => 'main_line',
        ]);

        $this->evolution->expects('sendText')
            ->with('main_line', '234801', 'hi')
            ->andReturn(['key' => ['id' => 'evo_id']]);

        $this->cloud->shouldNotReceive('sendText');

        $result = $this->messenger->sendText($instance, '234801', 'hi');

        $this->assertSame('evo_id', $result->messageId);
    }

    public function test_send_template_throws_for_evolution_driver(): void
    {
        $instance = WhatsAppInstance::factory()->evolution()->create();

        $this->cloud->shouldNotReceive('sendTemplate');
        $this->evolution->shouldNotReceive('sendTemplate');

        $this->expectException(WhatsAppApiException::class);
        $this->expectExceptionMessageMatches('/only supported for Cloud API/');

        $this->messenger->sendTemplate($instance, '234', 'hello', 'en_US', []);
    }

    public function test_send_template_dispatches_to_cloud_with_components(): void
    {
        $instance = WhatsAppInstance::factory()->cloud()->create();

        $components = [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Jane']]],
        ];

        $this->cloud->expects('sendTemplate')
            ->with($instance, '234', 'welcome_v1', 'en_US', $components)
            ->andReturn(['messages' => [['id' => 'wamid.tpl']]]);

        $result = $this->messenger->sendTemplate($instance, '234', 'welcome_v1', 'en_US', $components);

        $this->assertSame('wamid.tpl', $result->messageId);
    }

    public function test_message_id_extraction_handles_missing_id_gracefully(): void
    {
        // Cloud API returned a 200 but with no id (rare, but possible during outages).
        $instance = WhatsAppInstance::factory()->cloud()->create();

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
