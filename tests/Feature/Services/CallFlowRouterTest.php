<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use App\Services\CallFlowRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallFlowRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Start from a known all-off baseline; each test opts features on.
        config([
            'voice.business_hours_enabled' => false,
            'voice.ivr_enabled' => false,
            'voice.queue_enabled' => false,
            'voice.voicemail_enabled' => false,
        ]);
    }

    /**
     * @return array{0: CallLog, 1: ?int} the inbound call + the assigned agent id
     */
    private function inboundCall(bool $withAgent = true): array
    {
        $agentId = $withAgent ? User::factory()->create()->id : null;
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $agentId]);

        $call = CallLog::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => CallLog::DIRECTION_INBOUND,
            'from_phone' => '+2348011112222',
        ]);

        return [$call, $agentId];
    }

    private function router(): CallFlowRouter
    {
        return app(CallFlowRouter::class);
    }

    public function test_all_flags_off_dials_the_assigned_agent(): void
    {
        [$call, $agentId] = $this->inboundCall();

        $xml = $this->router()->entryXml($call)->render();

        $this->assertStringContainsString('<Dial phoneNumbers="agent_'.$agentId.'" callerId="+2348011112222"/>', $xml);
    }

    public function test_no_assigned_agent_and_no_features_says_busy(): void
    {
        [$call] = $this->inboundCall(withAgent: false);

        $xml = $this->router()->entryXml($call)->render();

        $this->assertStringContainsString('<Say>All our agents are currently busy. Please call again later.</Say>', $xml);
        $this->assertStringNotContainsString('<Dial', $xml);
    }

    public function test_ivr_enabled_presents_the_menu(): void
    {
        config(['voice.ivr_enabled' => true, 'voice.ivr.prompt' => 'Press one for sales']);
        [$call] = $this->inboundCall();

        $xml = $this->router()->entryXml($call)->render();

        $this->assertStringContainsString('<GetDigits', $xml);
        $this->assertStringContainsString('numDigits="1"', $xml);
        $this->assertStringContainsString('<Say>Press one for sales</Say>', $xml);
    }

    public function test_ivr_agent_option_dials_the_agent(): void
    {
        config(['voice.ivr_enabled' => true, 'voice.ivr.options' => ['1' => ['type' => 'agent']]]);
        [$call, $agentId] = $this->inboundCall();

        $xml = $this->router()->digitSelectionXml($call, '1')->render();

        $this->assertStringContainsString('<Dial phoneNumbers="agent_'.$agentId.'"', $xml);
    }

    public function test_ivr_queue_option_enqueues(): void
    {
        config([
            'voice.ivr_enabled' => true,
            'voice.ivr.options' => ['2' => ['type' => 'queue', 'queue' => 'support']],
            'voice.queue.hold_music_url' => 'https://m/hold.mp3',
        ]);
        [$call] = $this->inboundCall();

        $xml = $this->router()->digitSelectionXml($call, '2')->render();

        $this->assertStringContainsString('<Enqueue name="support" holdMusic="https://m/hold.mp3"/>', $xml);
    }

    public function test_ivr_voicemail_option_records(): void
    {
        config([
            'voice.ivr_enabled' => true,
            'voice.ivr.options' => ['3' => ['type' => 'voicemail']],
            'voice.voicemail.greeting' => 'Leave a message',
        ]);
        [$call] = $this->inboundCall();

        $xml = $this->router()->digitSelectionXml($call, '3')->render();

        $this->assertStringContainsString('<Say>Leave a message</Say>', $xml);
        $this->assertStringContainsString('<Record', $xml);
        $this->assertStringContainsString('finishOnKey="#"', $xml);
    }

    public function test_invalid_ivr_digit_reprompts(): void
    {
        config([
            'voice.ivr_enabled' => true,
            'voice.ivr.options' => ['1' => ['type' => 'agent']],
            'voice.ivr.invalid_message' => 'Not a valid option',
        ]);
        [$call] = $this->inboundCall();

        $xml = $this->router()->digitSelectionXml($call, '9')->render();

        $this->assertStringContainsString('<Say>Not a valid option</Say>', $xml);
        $this->assertStringContainsString('<GetDigits', $xml);
    }

    public function test_closed_business_hours_sends_to_voicemail(): void
    {
        config([
            'voice.business_hours_enabled' => true,
            'voice.voicemail_enabled' => true,
            'voice.business_hours.closed_message' => 'We are closed',
            'voice.business_hours.week' => ['mon' => null, 'tue' => null, 'wed' => null,
                'thu' => null, 'fri' => null, 'sat' => null, 'sun' => null],
            'voice.business_hours.timezone' => 'UTC',
        ]);
        [$call] = $this->inboundCall();

        $xml = $this->router()->entryXml($call)->render();

        $this->assertStringContainsString('<Say>We are closed</Say>', $xml);
        $this->assertStringContainsString('<Record', $xml);
        $this->assertStringNotContainsString('<Dial', $xml);
    }

    public function test_queue_fallback_when_no_agent_and_queue_enabled(): void
    {
        config(['voice.queue_enabled' => true, 'voice.queue.default_name' => 'support']);
        [$call] = $this->inboundCall(withAgent: false);

        $xml = $this->router()->entryXml($call)->render();

        $this->assertStringContainsString('<Enqueue name="support"', $xml);
    }
}
