<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Bridges the browser recording format to what Gemini accepts.
 *
 * Chrome's MediaRecorder only emits webm/opus, which is NOT in Gemini's
 * supported audio list (wav/mp3/aiff/aac/ogg/flac). This service remuxes such
 * recordings into ogg (opus copied, not re-encoded — fast + lossless) using
 * ffmpeg when it's available.
 *
 * It is strictly best-effort: no ffmpeg, or any failure, returns null and the
 * caller falls back to sending the original audio. Zero hard dependency.
 */
class AudioTranscoder
{
    /**
     * Bare MIME container types Gemini accepts directly — no transcode needed.
     */
    private const GEMINI_MIMES = [
        'audio/wav',
        'audio/x-wav',
        'audio/mp3',
        'audio/mpeg',
        'audio/aiff',
        'audio/aac',
        'audio/ogg',
        'audio/flac',
    ];

    /**
     * Does this recording need converting before Gemini will take it?
     * Compares the bare container type (codecs parameter stripped).
     */
    public function needsTranscode(string $mime): bool
    {
        $bare = trim(explode(';', $mime)[0]);

        return ! in_array($bare, self::GEMINI_MIMES, true);
    }

    /**
     * Remux arbitrary audio bytes to ogg. Returns the ogg bytes, or null if
     * ffmpeg is missing / the conversion fails (caller sends the original).
     */
    public function toOgg(string $bytes): ?string
    {
        $binary = (string) config('services.ffmpeg.path', 'ffmpeg');
        $dir = sys_get_temp_dir();
        $in = tempnam($dir, 'bqrec_');
        if ($in === false) {
            return null;
        }
        $out = $in.'.ogg';

        try {
            file_put_contents($in, $bytes);

            // -c:a copy remuxes the existing opus stream into ogg without a
            // re-encode, so no codec/library beyond the ogg muxer is required.
            $result = Process::timeout(120)->run([
                $binary, '-y', '-hide_banner', '-loglevel', 'error',
                '-i', $in, '-c:a', 'copy', '-f', 'ogg', $out,
            ]);

            if (! $result->successful() || ! is_file($out) || filesize($out) === 0) {
                Log::debug('ffmpeg transcode did not produce output', [
                    'exit' => $result->exitCode(),
                ]);

                return null;
            }

            $ogg = file_get_contents($out);

            return $ogg !== false && $ogg !== '' ? $ogg : null;
        } catch (Throwable $e) {
            // ffmpeg not installed / not on PATH / spawn failure — degrade quietly.
            Log::debug('ffmpeg unavailable for transcode', ['error' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }
}
