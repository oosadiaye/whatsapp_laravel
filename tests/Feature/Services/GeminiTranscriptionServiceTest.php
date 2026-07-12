<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\TranscriptionException;
use App\Services\GeminiTranscriptionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pins the Gemini call contract: audio in → {transcript, summary, key_points}.
 * The service is the ONLY thing that talks to generativelanguage.googleapis.com,
 * so these Http::fake tests are the guard against a request-shape or parse
 * regression that would silently break every Call Workspace summary.
 */
class GeminiTranscriptionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-2.0-flash',
            'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);
    }

    public function test_returns_transcript_summary_and_key_points_from_gemini(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'transcript' => 'Agent: Hello. Customer: Where is my order 812?',
                            'summary' => 'Customer chased delayed order 812 and asked for a refund.',
                            'key_points' => ['Order 812 is late', 'Refund requested', 'Prefers WhatsApp'],
                        ]),
                    ]]],
                ]],
            ], 200),
        ]);

        $result = app(GeminiTranscriptionService::class)
            ->transcribeAndSummarize('fake-audio-bytes', 'audio/webm');

        $this->assertStringContainsString('order 812', $result['transcript']);
        $this->assertSame('Customer chased delayed order 812 and asked for a refund.', $result['summary']);
        $this->assertCount(3, $result['key_points']);
        $this->assertContains('Refund requested', $result['key_points']);
    }

    public function test_sends_audio_inline_as_base64_to_the_configured_model(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [[
                    'text' => json_encode(['transcript' => 't', 'summary' => 's', 'key_points' => []]),
                ]]]]],
            ], 200),
        ]);

        app(GeminiTranscriptionService::class)->transcribeAndSummarize('raw-bytes', 'audio/ogg');

        Http::assertSent(function ($request) {
            $body = $request->data();
            $part = $body['contents'][0]['parts'][1]['inline_data'] ?? null;

            return str_contains($request->url(), 'models/gemini-2.0-flash:generateContent')
                // Key travels in the header, never the URL query string.
                && ! str_contains($request->url(), 'test-gemini-key')
                && $request->hasHeader('x-goog-api-key', 'test-gemini-key')
                && $part !== null
                && $part['mime_type'] === 'audio/ogg'
                && $part['data'] === base64_encode('raw-bytes');
        });
    }

    public function test_transport_failure_message_does_not_leak_the_api_key(): void
    {
        // Simulate a connection error whose message embeds the key (as cURL
        // errors sometimes do) — the thrown exception must NOT carry it.
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: connect failed key=test-gemini-key');
        });

        try {
            app(GeminiTranscriptionService::class)->transcribeAndSummarize('bytes', 'audio/webm');
            $this->fail('Expected TranscriptionException.');
        } catch (TranscriptionException $e) {
            $this->assertStringNotContainsString('test-gemini-key', $e->getMessage());
            $this->assertSame('Could not reach Gemini.', $e->getMessage());
        }
    }

    public function test_throws_when_api_key_is_missing(): void
    {
        config(['services.gemini.key' => null]);

        $this->expectException(TranscriptionException::class);

        app(GeminiTranscriptionService::class)->transcribeAndSummarize('bytes', 'audio/webm');
    }

    public function test_throws_on_non_2xx_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429),
        ]);

        $this->expectException(TranscriptionException::class);

        app(GeminiTranscriptionService::class)->transcribeAndSummarize('bytes', 'audio/webm');
    }

    public function test_throws_when_candidate_text_is_not_valid_json(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'not-json-at-all']]]]],
            ], 200),
        ]);

        $this->expectException(TranscriptionException::class);

        app(GeminiTranscriptionService::class)->transcribeAndSummarize('bytes', 'audio/webm');
    }

    public function test_key_points_are_coerced_to_a_clean_string_list(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [[
                    'text' => json_encode([
                        'transcript' => 't',
                        'summary' => 's',
                        // Messy shapes Gemini sometimes returns: blanks, numbers, whitespace.
                        'key_points' => ['  Trimmed  ', '', '  ', 42, 'Kept'],
                    ]),
                ]]]]],
            ], 200),
        ]);

        $result = app(GeminiTranscriptionService::class)->transcribeAndSummarize('b', 'audio/webm');

        $this->assertSame(['Trimmed', '42', 'Kept'], array_values($result['key_points']));
    }
}
