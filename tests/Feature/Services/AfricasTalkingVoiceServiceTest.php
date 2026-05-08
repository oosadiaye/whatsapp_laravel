<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\ConfigurationException;
use App\Exceptions\VoiceProviderException;
use App\Models\Setting;
use App\Services\AfricasTalkingVoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AfricasTalkingVoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('africastalking_username', 'sandbox');
        Setting::set('africastalking_api_key', Crypt::encryptString('atsk_test_key'));
        Setting::set('africastalking_virtual_number', '+2348100000000');
        Setting::set('default_country_code', '234');
    }

    public function test_place_call_posts_correct_payload(): void
    {
        $service = $this->app->make(AfricasTalkingVoiceService::class);

        Http::fake([
            'voice.africastalking.com/call' => Http::response([
                'entries' => [['sessionId' => 'sess_abc', 'status' => 'Queued']],
            ], 201),
        ]);

        $sessionId = $service->placeCall('+2348011111111');

        $this->assertSame('sess_abc', $sessionId);
        Http::assertSent(function ($request) {
            $data = $request->data();
            return str_contains($request->url(), 'voice.africastalking.com/call')
                && $data['username'] === 'sandbox'
                && $data['from'] === '+2348100000000'
                && $data['to'] === '+2348011111111'
                && $request->header('apiKey')[0] === 'atsk_test_key';
        });
    }

    public function test_place_call_normalizes_local_format_to_e164(): void
    {
        $service = $this->app->make(AfricasTalkingVoiceService::class);

        Http::fake([
            'voice.africastalking.com/call' => Http::response([
                'entries' => [['sessionId' => 'sess_norm', 'status' => 'Queued']],
            ], 201),
        ]);

        // Nigerian local format → E.164 with +234 prefix.
        $service->placeCall('08011223344');

        Http::assertSent(function ($request) {
            return ($request->data()['to'] ?? null) === '+2348011223344';
        });
    }

    public function test_place_call_returns_session_id_from_response(): void
    {
        $service = $this->app->make(AfricasTalkingVoiceService::class);

        Http::fake([
            'voice.africastalking.com/call' => Http::response([
                'entries' => [['sessionId' => 'sess_xyz_99', 'status' => 'Queued']],
            ], 201),
        ]);

        $this->assertSame('sess_xyz_99', $service->placeCall('+2348012345678'));
    }

    public function test_place_call_throws_voice_provider_exception_on_http_error(): void
    {
        $service = $this->app->make(AfricasTalkingVoiceService::class);
        Http::fake(['*' => Http::response(['error' => 'bad'], 500)]);

        $this->expectException(VoiceProviderException::class);
        $service->placeCall('+2348011111111');
    }

    public function test_place_call_throws_when_status_not_queued(): void
    {
        $service = $this->app->make(AfricasTalkingVoiceService::class);
        Http::fake([
            'voice.africastalking.com/call' => Http::response([
                'entries' => [['sessionId' => null, 'status' => 'InsufficientCredit']],
            ], 200),
        ]);

        $this->expectException(VoiceProviderException::class);
        $service->placeCall('+2348011111111');
    }

    public function test_place_call_throws_configuration_exception_when_virtual_number_missing(): void
    {
        Setting::query()->where('key', 'africastalking_virtual_number')->delete();

        $service = $this->app->make(AfricasTalkingVoiceService::class);
        $this->expectException(ConfigurationException::class);
        $service->placeCall('+2348011111111');
    }

    public function test_end_call_swallows_4xx_without_throwing(): void
    {
        $service = $this->app->make(AfricasTalkingVoiceService::class);
        Http::fake(['*' => Http::response(['error' => 'no such session'], 404)]);

        // Should NOT throw — call may have ended naturally; we log + move on.
        $service->endCall('sess_abc');

        $this->assertTrue(true);
    }
}
