/**
 * Phase 19a — call quality telemetry collector.
 *
 * Used by both Phase 17 (Meta raw WebRTC, calls.js) and Phase 18 (AT SDK,
 * outbound-call.js). Both factories obtain access to the underlying
 * RTCPeerConnection and pass it here.
 *
 * Lifecycle:
 *   1. startStatsCollection(peer) — call when peer reaches 'connected' state
 *   2. handle returned via { stop }
 *   3. stop() — call on teardown / hangup. Returns aggregated payload or null.
 *   4. postQuality(callId, csrf, aggregate) — POST to /calls/{call_id}/quality
 *
 * Sample cadence: 5 seconds (5000ms). Browser cost is negligible
 * (~1-2ms per getStats call). 6+ samples = robust averages.
 */
export function startStatsCollection(peer) {
    const samples = [];
    let intervalId = null;

    const tick = async () => {
        try {
            const report = await peer.getStats();
            const sample = extractRelevantStats(report);
            if (sample) samples.push(sample);
        } catch (e) {
            // Peer torn down between tick scheduling and getStats invocation.
            // Swallow — losing 1-2 samples is invisible to averages.
        }
    };

    intervalId = setInterval(tick, 5000);

    return {
        stop() {
            if (intervalId) clearInterval(intervalId);
            return aggregate(samples);
        },
    };
}

function extractRelevantStats(report) {
    let inboundRtp, candidatePair, codec;
    report.forEach((stat) => {
        if (stat.type === 'inbound-rtp' && stat.kind === 'audio') {
            inboundRtp = stat;
        }
        if (stat.type === 'candidate-pair' && stat.state === 'succeeded') {
            candidatePair = stat;
        }
        if (stat.type === 'codec' && stat.mimeType?.includes('audio')) {
            codec = stat;
        }
    });

    if (!inboundRtp) return null;

    return {
        jitter_ms: (inboundRtp.jitter ?? 0) * 1000,           // sec → ms
        packets_lost: inboundRtp.packetsLost ?? 0,
        packets_received: inboundRtp.packetsReceived ?? 0,
        rtt_ms: (candidatePair?.currentRoundTripTime ?? 0) * 1000,
        ice_local_id: candidatePair?.localCandidateId,
        codec_mime_type: codec?.mimeType,
    };
}

function aggregate(samples) {
    if (samples.length === 0) return null;

    const avg = (key) =>
        samples.reduce((sum, s) => sum + (s[key] || 0), 0) / samples.length;

    const totalReceived = samples.reduce(
        (sum, s) => sum + (s.packets_received || 0),
        0,
    );
    const totalLost = samples.reduce((sum, s) => sum + (s.packets_lost || 0), 0);
    const totalAttempted = totalReceived + totalLost;

    const last = samples[samples.length - 1];

    return {
        avg_jitter_ms: round2(avg('jitter_ms')),
        avg_packet_loss_pct:
            totalAttempted > 0
                ? round2((totalLost / totalAttempted) * 100)
                : 0,
        avg_rtt_ms: Math.round(avg('rtt_ms')),
        samples_captured: samples.length,
        ice_candidate_type: deriveIceType(last.ice_local_id),
        codec: deriveCodec(last.codec_mime_type),
    };
}

function deriveIceType(localCandidateId) {
    // candidate-pair.localCandidateId references a separate stat in the report.
    // For v1 we accept that ice_candidate_type may be 'unknown' if the browser
    // doesn't surface the type in the candidate-pair sub-record. Most browsers
    // do; Safari may delay.
    if (!localCandidateId) return 'unknown';
    const lower = localCandidateId.toLowerCase();
    if (lower.includes('relay')) return 'relay';
    if (lower.includes('srflx')) return 'srflx';
    if (lower.includes('prflx')) return 'prflx';
    if (lower.includes('host')) return 'host';
    return 'unknown';
}

function deriveCodec(mimeType) {
    // mimeType is "audio/opus" or "audio/PCMU" etc. Strip the prefix.
    if (!mimeType) return 'unknown';
    return mimeType.split('/')[1]?.toLowerCase() || 'unknown';
}

function round2(n) {
    return Math.round(n * 100) / 100;
}

/**
 * Helper to POST aggregated payload to server. Returns the fetch promise.
 * Swallows network failures silently — lost telemetry surfaces as "—" in
 * the history page, which is acceptable degradation.
 */
export async function postQuality(callId, csrfToken, aggregate) {
    if (!aggregate || aggregate.samples_captured < 1) return;
    try {
        await fetch(`/calls/${callId}/quality`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(aggregate),
        });
    } catch (e) {
        console.warn('quality post failed (non-fatal)', e);
    }
}
