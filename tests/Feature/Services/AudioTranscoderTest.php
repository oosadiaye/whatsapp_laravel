<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\AudioTranscoder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The transcode DECISION is pure logic and must be right — it decides whether a
 * recording is shipped to Gemini as-is or remuxed first. (The actual ffmpeg
 * shell-out is best-effort and environment-dependent, so it isn't unit-tested.)
 */
class AudioTranscoderTest extends TestCase
{
    #[DataProvider('mimeProvider')]
    public function test_needs_transcode_decision(string $mime, bool $expected): void
    {
        $this->assertSame($expected, app(AudioTranscoder::class)->needsTranscode($mime));
    }

    public static function mimeProvider(): array
    {
        return [
            'chrome webm needs transcode' => ['audio/webm;codecs=opus', true],
            'bare webm needs transcode' => ['audio/webm', true],
            'ogg is native' => ['audio/ogg', false],
            'ogg with codecs is native' => ['audio/ogg;codecs=opus', false],
            'mp3 is native' => ['audio/mpeg', false],
            'wav is native' => ['audio/wav', false],
            'aac is native' => ['audio/aac', false],
            'flac is native' => ['audio/flac', false],
            'unknown container needs transcode' => ['audio/3gpp', true],
        ];
    }
}
