<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\TranscriptionException;
use App\Models\CallLog;
use App\Services\GeminiTranscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Sends a call's private recording to Gemini and stores the resulting
 * transcript + summary + key points on the call. Dispatched right after the
 * browser uploads the recording; the Call Workspace panel polls ai_status
 * until this flips it to completed | failed.
 */
class TranscribeCallRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Gemini can be slow on longer audio; give the job room and one retry.
    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(public readonly int $callLogId)
    {
    }

    public function handle(GeminiTranscriptionService $gemini): void
    {
        $call = CallLog::find($this->callLogId);
        if ($call === null) {
            return; // call was deleted between upload and processing — nothing to do
        }

        // Defensive: the recording must exist on disk. If it vanished (retention
        // job, manual cleanup) mark unavailable rather than erroring forever.
        if (! $call->hasRecording() || ! Storage::exists($call->recording_path)) {
            $call->update(['ai_status' => CallLog::AI_STATUS_UNAVAILABLE]);

            return;
        }

        $call->update(['ai_status' => CallLog::AI_STATUS_PROCESSING]);

        try {
            $result = $gemini->transcribeAndSummarize(
                Storage::get($call->recording_path),
                $call->recording_mime ?? 'audio/webm',
            );

            $call->update([
                'transcript' => $result['transcript'],
                'ai_summary' => $result['summary'],
                'ai_key_points' => $result['key_points'],
                'ai_status' => CallLog::AI_STATUS_COMPLETED,
                'ai_error' => null,
            ]);
        } catch (TranscriptionException $e) {
            // Domain failure (bad key, quota, unparseable) — don't retry into the
            // same wall; record a user-safe message for the panel.
            Log::warning('Call transcription failed', [
                'call_id' => $call->id,
                'error' => $e->getMessage(),
            ]);

            $call->update([
                'ai_status' => CallLog::AI_STATUS_FAILED,
                'ai_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Last-resort hook if the job dies for a non-domain reason (timeout, OOM).
     */
    public function failed(\Throwable $e): void
    {
        CallLog::where('id', $this->callLogId)->update([
            'ai_status' => CallLog::AI_STATUS_FAILED,
            'ai_error' => 'Transcription could not be completed. Try re-analysing the call.',
        ]);
    }
}
