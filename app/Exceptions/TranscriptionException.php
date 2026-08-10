<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Jobs\TranscribeCallRecording;

/**
 * Thrown when the Gemini transcription/summary call fails — missing key,
 * non-2xx response, or an unparseable body. The queued
 * {@see TranscribeCallRecording} catches it and marks the call's
 * ai_status = failed with a user-safe message (never the raw key).
 */
class TranscriptionException extends \RuntimeException {}
