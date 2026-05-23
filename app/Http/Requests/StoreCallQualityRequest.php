<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation + authorization for POST /calls/{call}/quality.
 *
 * Pulled out of CallController::quality so the action stays focused on
 * the MOS computation. The ownership check lives here too — only the
 * agent who placed the call (outbound) or whose conversation is
 * assigned the call (inbound) is permitted to post telemetry.
 *
 * Why ownership and not just permissions: each browser collects stats
 * from its OWN RTCPeerConnection. A different user posting metrics
 * for a call they didn't conduct would be stamping someone else's
 * data — meaningless and confusing in /calls history.
 *
 * The `call` route parameter is exposed via $this->route('call'), the
 * Laravel-bound CallLog model.
 */
class StoreCallQualityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $call = $this->route('call');
        $userId = $this->user()?->id;

        if ($call === null || $userId === null) {
            return false;
        }

        // Outbound: matches placed_by_user_id (only that agent has the peer).
        // Inbound:  matches the parent conversation's assigned_to_user_id
        //           (the agent who answered).
        return $call->placed_by_user_id === $userId
            || $call->conversation?->assigned_to_user_id === $userId;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // 0-10000 ms jitter range covers everything from ideal LAN
            // (<1ms) to disastrous (>1s = call unusable anyway).
            'avg_jitter_ms' => ['required', 'numeric', 'min:0', 'max:10000'],
            // 0-100% percentage; values >50 are usually instrumentation
            // errors but we accept and let MOS clamp.
            'avg_packet_loss_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            // Network sanity bound: 60s RTT means a catastrophic stall;
            // anything beyond that is almost certainly a measurement bug.
            'avg_rtt_ms' => ['required', 'integer', 'min:0', 'max:60000'],
            // Hard cap on samples — at the 5s collection interval, 1000
            // samples means an 83-minute call, well beyond realistic.
            'samples_captured' => ['required', 'integer', 'min:0', 'max:1000'],
            'ice_candidate_type' => ['required', 'string', 'in:host,srflx,relay,prflx,unknown'],
            'codec' => ['required', 'string', 'max:32'],
        ];
    }
}
