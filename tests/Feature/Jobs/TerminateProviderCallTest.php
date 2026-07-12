<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\TerminateProviderCall;
use App\Models\CallLog;
use App\Models\Setting;
use App\Services\AfricasTalkingVoiceService;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TerminateProviderCallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('africastalking_username', 'sandbox');
        Setting::set('africastalking_api_key', Crypt::encryptString('atsk_test_key'));
        Setting::set('africastalking_virtual_number', '+2348100000000');
    }

    private function runJob(int $callId): void
    {
        (new TerminateProviderCall($callId))->handle(
            app(AfricasTalkingVoiceService::class),
            app(WhatsAppCloudApiService::class),
        );
    }

    public function test_terminates_an_at_call_via_the_at_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $call = CallLog::factory()->create([
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => 'sess_live',
        ]);

        $this->runJob($call->id);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'voice.africastalking.com'));
    }

    public function test_noop_for_at_call_without_a_session_id(): void
    {
        Http::fake();
        $call = CallLog::factory()->create([
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => null,
        ]);

        $this->runJob($call->id);

        Http::assertNothingSent();
    }

    public function test_noop_when_the_call_no_longer_exists(): void
    {
        Http::fake();

        $this->runJob(999999);

        Http::assertNothingSent();
    }
}
