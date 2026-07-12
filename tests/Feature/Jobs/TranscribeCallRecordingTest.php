<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\TranscribeCallRecording;
use App\Models\CallLog;
use App\Services\AudioTranscoder;
use App\Services\GeminiTranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class TranscribeCallRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.gemini.key' => 'test-key']);
    }

    /** A transcoder stub: real needsTranscode logic, controllable toOgg output. */
    private function transcoder(?string $oggBytes): AudioTranscoder
    {
        $mock = Mockery::mock(AudioTranscoder::class)->makePartial();
        $mock->shouldReceive('toOgg')->andReturn($oggBytes);

        return $mock;
    }

    private function fakeGeminiSuccess(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [[
                    'text' => json_encode([
                        'transcript' => 'Agent: hi. Customer: order 812 late.',
                        'summary' => 'Chased late order 812.',
                        'key_points' => ['Order 812 late', 'Wants update'],
                    ]),
                ]]]]],
            ], 200),
        ]);
    }

    private function runJob(int $callId, ?string $oggBytes): void
    {
        (new TranscribeCallRecording($callId))
            ->handle(app(GeminiTranscriptionService::class), $this->transcoder($oggBytes));
    }

    public function test_stores_transcript_summary_and_key_points_on_success(): void
    {
        Storage::put('call-recordings/rec.webm', 'audio-bytes');
        $call = CallLog::factory()->create([
            'recording_path' => 'call-recordings/rec.webm',
            'recording_mime' => 'audio/webm',
            'ai_status' => CallLog::AI_STATUS_PENDING,
        ]);
        $this->fakeGeminiSuccess();

        $this->runJob($call->id, 'ogg-bytes');

        $call->refresh();
        $this->assertSame(CallLog::AI_STATUS_COMPLETED, $call->ai_status);
        $this->assertStringContainsString('order 812', $call->transcript);
        $this->assertSame('Chased late order 812.', $call->ai_summary);
        $this->assertSame(['Order 812 late', 'Wants update'], $call->ai_key_points);
        $this->assertNull($call->ai_error);
    }

    public function test_transcodes_webm_to_ogg_before_sending_to_gemini(): void
    {
        Storage::put('call-recordings/rec.webm', 'webm-bytes');
        $call = CallLog::factory()->create([
            'recording_path' => 'call-recordings/rec.webm',
            'recording_mime' => 'audio/webm;codecs=opus',
        ]);
        $this->fakeGeminiSuccess();

        $this->runJob($call->id, 'ogg-bytes');

        // Gemini must receive the ogg the transcoder produced, not the webm.
        Http::assertSent(function ($request) {
            $part = $request->data()['contents'][0]['parts'][1]['inline_data'] ?? [];

            return ($part['mime_type'] ?? null) === 'audio/ogg'
                && ($part['data'] ?? null) === base64_encode('ogg-bytes');
        });
    }

    public function test_sends_original_audio_when_transcode_unavailable(): void
    {
        Storage::put('call-recordings/rec.webm', 'webm-bytes');
        $call = CallLog::factory()->create([
            'recording_path' => 'call-recordings/rec.webm',
            'recording_mime' => 'audio/webm',
        ]);
        $this->fakeGeminiSuccess();

        // toOgg returns null (no ffmpeg) → original webm is sent as-is.
        $this->runJob($call->id, null);

        Http::assertSent(function ($request) {
            $part = $request->data()['contents'][0]['parts'][1]['inline_data'] ?? [];

            return ($part['mime_type'] ?? null) === 'audio/webm'
                && ($part['data'] ?? null) === base64_encode('webm-bytes');
        });
    }

    public function test_skips_transcode_for_gemini_native_format(): void
    {
        Storage::put('call-recordings/rec.ogg', 'ogg-native');
        $call = CallLog::factory()->create([
            'recording_path' => 'call-recordings/rec.ogg',
            'recording_mime' => 'audio/ogg',
        ]);
        $this->fakeGeminiSuccess();

        // Transcoder's toOgg must NOT be called for an already-accepted format.
        $mock = Mockery::mock(AudioTranscoder::class)->makePartial();
        $mock->shouldNotReceive('toOgg');

        (new TranscribeCallRecording($call->id))
            ->handle(app(GeminiTranscriptionService::class), $mock);

        Http::assertSent(function ($request) {
            $part = $request->data()['contents'][0]['parts'][1]['inline_data'] ?? [];

            return ($part['mime_type'] ?? null) === 'audio/ogg'
                && ($part['data'] ?? null) === base64_encode('ogg-native');
        });
    }

    public function test_marks_failed_with_message_when_gemini_errors(): void
    {
        Storage::put('call-recordings/rec.webm', 'audio-bytes');
        $call = CallLog::factory()->create([
            'recording_path' => 'call-recordings/rec.webm',
            'recording_mime' => 'audio/webm',
            'ai_status' => CallLog::AI_STATUS_PENDING,
        ]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429),
        ]);

        $this->runJob($call->id, 'ogg-bytes');

        $call->refresh();
        $this->assertSame(CallLog::AI_STATUS_FAILED, $call->ai_status);
        $this->assertNotEmpty($call->ai_error);
        $this->assertNull($call->transcript);
    }

    public function test_marks_unavailable_when_recording_file_is_gone(): void
    {
        $call = CallLog::factory()->create([
            'recording_path' => 'call-recordings/missing.webm',
            'ai_status' => CallLog::AI_STATUS_PENDING,
        ]);
        Http::fake(); // must never be hit

        $this->runJob($call->id, null);

        $call->refresh();
        $this->assertSame(CallLog::AI_STATUS_UNAVAILABLE, $call->ai_status);
        Http::assertNothingSent();
    }
}
