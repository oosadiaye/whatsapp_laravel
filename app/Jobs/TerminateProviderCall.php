<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CallLog;
use App\Services\AfricasTalkingVoiceService;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Tells the voice provider to actually hang up the customer's leg, with bounded
 * retries. The agent-facing state (status = ended, CallTerminated broadcast)
 * happens synchronously in CallController::terminate() so the UI is instantly
 * consistent; this job handles the provider side out-of-band.
 *
 * Why a job: a transient provider failure (timeout, 5xx) at hang-up time would
 * otherwise orphan the live leg — the customer stays connected and billing
 * keeps running. Retrying makes teardown reliable instead of best-effort.
 */
class TerminateProviderCall implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Exponential-ish backoff between provider retry attempts (seconds). */
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly int $callLogId)
    {
    }

    public function handle(
        AfricasTalkingVoiceService $atVoice,
        WhatsAppCloudApiService $cloud,
    ): void {
        $call = CallLog::find($this->callLogId);
        if ($call === null) {
            return; // deleted between hangup and this job — nothing to terminate
        }

        if ($call->provider === CallLog::PROVIDER_AFRICAS_TALKING) {
            if (blank($call->provider_session_id)) {
                return; // never got a session id (call failed before connect)
            }
            $atVoice->endCall($call->provider_session_id);

            return;
        }

        // Meta / WhatsApp Cloud calling.
        if (blank($call->meta_call_id) || $call->whatsappInstance === null) {
            return;
        }
        $cloud->endCall($call->whatsappInstance, $call->meta_call_id);
    }

    /**
     * All retries exhausted — the provider-side hangup could not be confirmed.
     * Record it loudly; the natural-disconnect webhook is the last safety net.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('Provider-side call termination failed after retries', [
            'call_id' => $this->callLogId,
            'error' => $e->getMessage(),
        ]);
    }
}
