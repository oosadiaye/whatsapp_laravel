<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\TranscribeCallRecording;
use App\Models\CallLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function test_stores_transcript_summary_and_key_points_on_success(): void
    {
        Storage::put('call-recordings/rec.webm', 'audio-bytes');
        $call = CallLog::factory()->create([
            'recording_path' => 'call-recordings/rec.webm',
            'recording_mime' => 'audio/webm',
            'ai_status' => CallLog::AI_STATUS_PENDING,
        ]);

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

        (new TranscribeCallRecording($call->id))->handle(app(\App\Services\GeminiTranscriptionService::class));

        $call->refresh();
        $this->assertSame(CallLog::AI_STATUS_COMPLETED, $call->ai_status);
        $this->assertStringContainsString('order 812', $call->transcript);
        $this->assertSame('Chased late order 812.', $call->ai_summary);
        $this->assertSame(['Order 812 late', 'Wants update'], $call->ai_key_points);
        $this->assertNull($call->ai_error);
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

        (new TranscribeCallRecording($call->id))->handle(app(\App\Services\GeminiTranscriptionService::class));

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

        (new TranscribeCallRecording($call->id))->handle(app(\App\Services\GeminiTranscriptionService::class));

        $call->refresh();
        $this->assertSame(CallLog::AI_STATUS_UNAVAILABLE, $call->ai_status);
        Http::assertNothingSent();
    }
}
