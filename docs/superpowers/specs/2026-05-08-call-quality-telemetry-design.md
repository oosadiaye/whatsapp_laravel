# Phase 19a — Call Quality Telemetry Design

**Date:** 2026-05-08
**Status:** Approved
**Builds on:** Phase 17 (Meta WebRTC inbound) + Phase 18 (Africa's Talking outbound + AT inbound)

## Summary

Capture per-call audio quality metrics during every call (Phase 17 Meta WebRTC + Phase 18 AT JS SDK) and persist a 7-field summary on the existing `call_logs` table as a JSON column. The browser polls `RTCPeerConnection.getStats()` every 5 seconds, accumulates samples in memory, and POSTs the aggregated averages to a new `/calls/{call}/quality` endpoint on hangup. The server runs a G.107 E-model formula to derive a single MOS score (1.0–5.0). The `/calls` history page renders an MOS chip per row, color-coded by quality tier.

This is the smallest possible thing that gives operators visibility into "are calls good?" Foundation for Phase 19b (TURN — needs `ice_candidate_type` distribution to justify), Phase 19c (recording — duration + quality data informs retention), Phase 19d (voicemail — depends on call-quality patterns).

## Goals

1. Capture per-call quality metrics post-call from both providers (Meta + AT) using a single shared browser-side helper.
2. Compute one canonical MOS score server-side, identical algorithm regardless of provider, so quality is comparable across rows.
3. Surface MOS on `/calls` history page so managers can spot bad calls at a glance.
4. Foundation for Phase 19b/c/d — `ice_candidate_type` distribution data informs whether TURN is needed; aggregate quality data informs recording/voicemail prioritization.
5. Tight scope: 1 schema column, 1 service class, 1 JS helper, 1 controller method, 1 display column. ~10 PHPUnit tests.

## Non-goals (deferred)

- **Team-load aggregate widget** (per-agent rolling MOS average) → add later if useful after seeing real data
- **Drill-down `/calls/{id}/quality` page** → needs granular samples; not stored in 19a
- **Real-time alerting** when MOS drops mid-call → Phase 19a+N; needs workflow design (who acts? how?) — wait for data first
- **TURN server installation** → Phase 19b, decision informed by 19a's `ice_candidate_type` data
- **Granular `call_quality_samples` time-series table** → Phase 19c+ when post-mortem debugging needs it
- **AT webhook quality fields** if AT exposes them → not used; browser `getStats()` is sole source for cross-provider parity
- **Per-codec breakdown / min/max variance** → add to JSON later if signal emerges
- **Browser-side automated testing** (Vitest for the JS helper) → Phase 19e (browser-side test infrastructure)
- **MOS calibration against user-reported quality** → one-line constants tuning once production data exists

## Brainstorming decisions reference

| Q | Decision |
|---|---|
| Q1 Sampling cadence | Every 5 seconds during the call |
| Q2 Storage shape | JSON `quality_metrics` column on `call_logs`; end-of-call summary only |
| Q3 Metrics scope | 7-field minimum viable set (mos, avg_jitter_ms, avg_packet_loss_pct, avg_rtt_ms, samples_captured, ice_candidate_type, codec) |
| Q4 Display surface | Single column on `/calls` history page; deferred others |
| Q5 Capture source | Browser `getStats()` only; AT webhook contributes only `durationInSeconds` (existing) |
| Q6 Real-time alerting | None in 19a |
| Q7 Cross-provider parity | Normalize in browser before POST via shared `call-stats-collector.js` helper |
| Q8 Sample retention | Permanent — same lifecycle as parent CallLog row |
| Q9 MOS computation | Server-side G.107 E-model formula in dedicated `CallQualityCalculator` service |
| Q10 NDPR/privacy | Ship as designed; no personal data; categorical `ice_candidate_type` (host/srflx/relay/prflx) is operational diagnostic, not identifying |

## Architecture

```
Browser-side (during call)
┌──────────────────────────────────────────────────────────────┐
│ peer (RTCPeerConnection)                                     │
│   Phase 17: raw RTCPeerConnection in calls.js                │
│   Phase 18: AT SDK exposes peer via this.atClient            │
│   ↓ setInterval(5s)                                          │
│ stats = await peer.getStats()                                │
│ samples.push({ jitter, packetsLost, packetsReceived,         │
│                rtt, ice_local_type, codec })                 │
└──────────────────────────────────────────────────────────────┘
                            │ on hangup / call-end
                            ▼
┌──────────────────────────────────────────────────────────────┐
│ resources/js/call-stats-collector.js (new shared helper)     │
│   startStatsCollection(peer) → returns {stop()}              │
│   stop() → aggregate(samples) returns:                       │
│     {avg_jitter_ms, avg_packet_loss_pct, avg_rtt_ms,         │
│      samples_captured, ice_candidate_type, codec}            │
│   POST /calls/{call}/quality with the 6-field payload        │
└──────────────────────────────────────────────────────────────┘
                            │
                            ▼
Server-side (Laravel)
┌──────────────────────────────────────────────────────────────┐
│ POST /calls/{call}/quality                                   │
│ CallController::quality(Request, CallLog,                    │
│                          CallQualityCalculator)              │
│   - validate ownership: placed_by_user_id OR conversation    │
│     assigned_to_user_id matches auth()->id()                 │
│   - validate payload shape (jitter/loss/rtt ranges)          │
│   - calculator->computeMos(loss, jitter, rtt) → mos          │
│   - $call->update(['quality_metrics' => json([...7 fields])])│
└──────────────────────────────────────────────────────────────┘
                            │
                            ▼
Display (manager-facing)
┌──────────────────────────────────────────────────────────────┐
│ /calls history page                                          │
│   - new "Quality" column                                     │
│   - MOS rendered as colored chip:                            │
│     ≥4.0 emerald (Excellent) / 3.0-3.9 amber (Fair) /        │
│     <3.0 red (Poor) / null gray (—)                          │
│   - tooltip: "MOS 4.2 · jitter 18ms · loss 0.3% · RTT 145ms" │
└──────────────────────────────────────────────────────────────┘
```

## Database

### Migration

```php
public function up(): void
{
    Schema::table('call_logs', function (Blueprint $table) {
        // 7-field call-quality summary, populated on call-end via the
        // browser POST to /calls/{call}/quality. NULL for pre-Phase-19a
        // rows and for calls where browser teardown happened before the
        // POST landed.
        //
        // Shape: {
        //   "mos": 4.2,                    // 1.0-5.0 G.107 derivation
        //   "avg_jitter_ms": 18,           // float, milliseconds
        //   "avg_packet_loss_pct": 0.3,    // float, 0.0-100.0
        //   "avg_rtt_ms": 145,             // integer, milliseconds
        //   "samples_captured": 18,        // integer; <3 = unreliable
        //   "ice_candidate_type": "host",  // host | srflx | relay | prflx
        //   "codec": "opus"                // string
        // }
        $table->json('quality_metrics')->nullable()->after('cost_estimate_kobo');
    });
}

public function down(): void
{
    Schema::table('call_logs', function (Blueprint $table) {
        $table->dropColumn('quality_metrics');
    });
}
```

No new tables. No new index. JSON queries hit the small subset of recent `call_logs` rows; aggregations like `SELECT AVG(JSON_EXTRACT(quality_metrics, '$.mos'))` run as needed.

### CallLog model changes

```php
protected $fillable = [
    // ... existing entries ...
    'cost_estimate_kobo',
    'quality_metrics',  // new
];

protected function casts(): array
{
    return [
        // ... existing casts ...
        'quality_metrics' => 'array',  // JSON ↔ PHP array
    ];
}
```

## Browser-side capture — `resources/js/call-stats-collector.js`

```js
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
 *   4. POST to /calls/{call_id}/quality on next tick.
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
        ice_local_type: candidatePair?.localCandidateId,      // resolved below
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
        ice_candidate_type: deriveIceType(last.ice_local_type),
        codec: deriveCodec(last.codec_mime_type),
    };
}

function deriveIceType(localCandidateId) {
    // candidate-pair.localCandidateId references a separate stat in the report
    // with type='local-candidate'. We don't preserve the full report between
    // ticks; for v1 we accept that ice_candidate_type may be 'unknown' if the
    // browser doesn't surface it in the candidate-pair sub-record. Most
    // browsers do; Safari may delay.
    if (!localCandidateId) return 'unknown';
    // The string usually contains 'host', 'srflx', or 'relay' as substring.
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
 * Helper to POST aggregated payload to server. Returns the fetch promise so
 * callers can await/handle errors. Swallows network failures silently — lost
 * telemetry surfaces as "—" in the history page.
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
```

## Wiring into `calls.js` (Phase 17)

Phase 17's `incomingCall` Alpine factory has access to `this.peer` (RTCPeerConnection). On the existing `'connected'` transition, start the collector. On the existing `teardown()` path, stop and POST.

```js
import { startStatsCollection, postQuality } from './call-stats-collector';

// Inside the factory's existing methods:
//   - On peer 'connected' state change: this._statsHandle = startStatsCollection(this.peer);
//   - On teardown(): const aggregate = this._statsHandle?.stop();
//                    postQuality(this.callId, this.csrf, aggregate);
```

## Wiring into `outbound-call.js` (Phase 18)

The AT SDK's `atClient` exposes the underlying peer via a member (verified during Phase 18 Task 8 deploy). Same pattern:

```js
import { startStatsCollection, postQuality } from './call-stats-collector';

// Inside both window.outgoingCall and window.incomingAtCall factories:
//   - On 'connected' event: this._statsHandle = startStatsCollection(this.atClient.peer);
//   - On teardown(): const aggregate = this._statsHandle?.stop();
//                    postQuality(this.callId, this.csrf, aggregate);
```

If the AT SDK version doesn't expose `peer`, we accept null aggregate (postQuality early-returns). Telemetry shows "—" on AT calls in that case until the SDK is upgraded.

## Service — `App\Services\CallQualityCalculator`

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Computes a Mean Opinion Score (MOS, 1.0–5.0) from raw WebRTC stats
 * using the ITU-T G.107 E-model approximation.
 *
 * Reference: ITU-T G.107 (06/2015) E-model, simplified for VoIP.
 * Calibration constants are the de-facto standard used by Twilio,
 * Vonage, etc. Tuning these against user-reported quality is a
 * one-line constants change with one test update.
 */
class CallQualityCalculator
{
    public function computeMos(
        float $packetLossPct,
        float $jitterMs,
        int $rttMs,
    ): float {
        // R-factor approximation: starts at theoretical max (93.2),
        // subtracts impairments from each metric.
        $r = 93.2
            - $packetLossPct * 2.5
            - $jitterMs * 0.05
            - $rttMs * 0.024;

        // Clamp R to valid range (0-100) before MOS conversion.
        $r = max(0.0, min(100.0, $r));

        // E-model R → MOS conversion (cubic polynomial approximation).
        $mos = 1
            + 0.035 * $r
            + 0.000007 * $r * ($r - 60) * (100 - $r);

        // Defensive clamp; G.107 yields values in (1.0, 4.5) but
        // floating-point edge cases could overshoot.
        return round(max(1.0, min(5.0, $mos)), 2);
    }
}
```

## Controller — `CallController::quality`

```php
public function quality(
    Request $request,
    CallLog $call,
    CallQualityCalculator $calculator,
): JsonResponse {
    // Ownership check: only the agent who placed/answered the call may post.
    // Handles both outbound (placed_by_user_id) and inbound (assigned_to_user_id
    // on the parent conversation) flows.
    $userId = auth()->id();
    $owns = $call->placed_by_user_id === $userId
        || $call->conversation?->assigned_to_user_id === $userId;
    if (!$owns) {
        return response()->json(['error' => 'forbidden'], 403);
    }

    $validated = $request->validate([
        'avg_jitter_ms' => ['required', 'numeric', 'min:0', 'max:10000'],
        'avg_packet_loss_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        'avg_rtt_ms' => ['required', 'integer', 'min:0', 'max:60000'],
        'samples_captured' => ['required', 'integer', 'min:0', 'max:1000'],
        'ice_candidate_type' => ['required', 'string', 'in:host,srflx,relay,prflx,unknown'],
        'codec' => ['required', 'string', 'max:32'],
    ]);

    $mos = $calculator->computeMos(
        (float) $validated['avg_packet_loss_pct'],
        (float) $validated['avg_jitter_ms'],
        (int) $validated['avg_rtt_ms'],
    );

    $call->update([
        'quality_metrics' => array_merge($validated, ['mos' => $mos]),
    ]);

    return response()->json(['mos' => $mos]);
}
```

## Routes

```php
// Inside the existing permission:conversations.reply middleware group
// (alongside Phase 17 routes):
Route::post('/calls/{call}/quality', [CallController::class, 'quality'])
    ->name('calls.quality');
```

## Display — `resources/views/calls/index.blade.php`

Add a new "Quality" column to the existing table. Insertion point: between the existing "Duration" or "Cost" column and "Status" (operator preference).

```blade
{{-- Header --}}
<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
    {{ __('Quality') }}
</th>

{{-- Cell, per row --}}
<td class="px-6 py-4">
    @if($call->quality_metrics)
        @php
            $mos = $call->quality_metrics['mos'] ?? null;
            $colorClasses = match (true) {
                $mos === null => 'bg-gray-100 text-gray-600',
                $mos >= 4.0 => 'bg-emerald-100 text-emerald-800',
                $mos >= 3.0 => 'bg-amber-100 text-amber-800',
                default => 'bg-red-100 text-red-800',
            };
            $label = match (true) {
                $mos === null => '—',
                $mos >= 4.0 => 'Excellent',
                $mos >= 3.0 => 'Fair',
                default => 'Poor',
            };
        @endphp
        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $colorClasses }}"
              title="MOS {{ $mos }} · jitter {{ $call->quality_metrics['avg_jitter_ms'] ?? '?' }}ms · loss {{ $call->quality_metrics['avg_packet_loss_pct'] ?? '?' }}% · RTT {{ $call->quality_metrics['avg_rtt_ms'] ?? '?' }}ms · ICE {{ $call->quality_metrics['ice_candidate_type'] ?? '?' }}">
            {{ $label }} {{ number_format($mos, 1) }}
        </span>
    @else
        <span class="text-xs text-gray-400">—</span>
    @endif
</td>
```

The `title` attribute renders a native browser tooltip — sufficient for v1; can be upgraded to a Tailwind `x-data` tooltip in a future polish pass.

## Data flow scenarios

| Scenario | Sequence |
|---|---|
| Happy Phase 17 (Meta inbound) | Customer rings → agent accepts → peer connects → `startStatsCollection(peer)` → samples accumulate every 5s → agent hangs up → `stop()` returns aggregate → POST /calls/{id}/quality → server computes MOS → CallLog.quality_metrics populated → row in /calls renders chip |
| Happy Phase 18 (AT outbound) | Agent clicks Call → AT placeCall → SDK peer connects → `startStatsCollection(atClient.peer)` (same helper, different peer object) → POST /calls/{id}/quality on hangup → identical server flow |
| Browser crashes mid-call | Samples lost from JS memory. Server never receives POST. CallLog.quality_metrics stays null. /calls renders gray "—". Acceptable degradation |
| Network blip during teardown POST | postQuality wrapped in try/catch — failure is logged in console, lost. CallLog.quality_metrics stays null. Acceptable |
| Pre-Phase-19a CallLog rows | quality_metrics is null. /calls renders gray "—". Historical data has no telemetry — manager scans new calls only |
| Customer hangs up before connect | Peer never reaches "connected" state. `startStatsCollection` never started (only invoked on connect event). No POST. quality_metrics null. Correct — no audio to measure |
| Agent closes tab mid-call | `beforeunload` fires teardown → helper.stop() → POST attempted. If POST hits before tab dies, captured. If browser kills the request, lost. Acceptable |
| AT SDK doesn't expose peer | `startStatsCollection(undefined)` → all `getStats` calls fail → samples stays empty → aggregate returns null → postQuality early-returns. quality_metrics stays null. Documented limitation; SDK upgrade resolves |

## Error handling

| Failure | Behavior | Why correct |
|---|---|---|
| `getStats()` throws (peer torn down between tick and call) | Caught, swallowed, sample skipped | Sample frequency means losing 1-2 is invisible to averages |
| Helper starts but no audio inbound | `inboundRtp` is undefined → sample returns null → skipped → samples=0 → POST aborted | Can't compute meaningful averages from zero samples |
| POST returns 422 (validation failure) | Console error logged, swallowed | Operator sees gray "—"; investigates if pattern emerges |
| POST returns 403 (ownership check fails) | Console error logged, swallowed | Defensive — shouldn't happen in normal flow; logs surface bugs |
| Server-side validation rejects malformed payload | 422 response, no DB write | Prevents bad data from polluting JSON column |
| Multiple POSTs for same call (browser retry) | Last write wins (CallLog.update) | Idempotent — re-aggregation with same samples produces same numbers |
| MOS calculation overflow / divide-by-zero | Clamped via `max(1.0, min(5.0, $mos))` | Defensive; G.107 R-factor clamp also prevents pathological inputs |
| Unknown `ice_candidate_type` | Stored as 'unknown' string | Validation accepts; display tooltip shows '?' for missing data |
| Browser-side `getStats()` returns no candidate-pair | `ice_candidate_type` defaults to 'unknown' | Acceptable — Phase 19b's TURN decision will track the proportion of 'relay' types; 'unknown' shrinks toward zero as browsers improve |

## Testing

10 new PHPUnit tests across 2 files. Browser-side `call-stats-collector.js` is NOT tested in PHPUnit (Phase 19e — Vitest infrastructure addition).

### `tests/Feature/Services/CallQualityCalculatorTest.php` (5 tests)

1. `test_excellent_call_yields_mos_above_4` — 0% loss, 5ms jitter, 50ms RTT → MOS ≥ 4.0
2. `test_poor_call_yields_mos_below_3` — 5% loss, 100ms jitter, 400ms RTT → MOS < 3.0
3. `test_zero_inputs_yield_max_mos` — 0/0/0 → MOS ≈ 4.4 (G.107 theoretical max)
4. `test_extreme_inputs_clamped_to_min_one` — 100% loss → MOS = 1.0 (not negative or zero)
5. `test_returns_two_decimal_precision` — output is rounded to 2 decimal places

### `tests/Feature/Http/CallQualityRouteTest.php` (5 tests)

6. `test_quality_endpoint_persists_payload_with_computed_mos` — POST valid payload → CallLog.quality_metrics has mos + all 7 fields
7. `test_quality_endpoint_validates_ownership_outbound` — outbound call's `placed_by_user_id` matches → 200; non-owner → 403
8. `test_quality_endpoint_validates_ownership_inbound` — inbound call's conversation `assigned_to_user_id` matches → 200; non-owner → 403
9. `test_quality_endpoint_rejects_invalid_payload` — POST with negative jitter → 422
10. `test_quality_endpoint_overwrites_previous_post` — second POST replaces first (last write wins, idempotent)

**Test trajectory:** 257 baseline → **267 final** (+10).

## Files

### Files to create (5)

| File | Responsibility |
|---|---|
| `database/migrations/<ts>_add_quality_metrics_to_call_logs.php` | Single JSON column |
| `app/Services/CallQualityCalculator.php` | Pure-function G.107 MOS computation |
| `resources/js/call-stats-collector.js` | Shared browser helper: startStatsCollection + aggregate + postQuality |
| `tests/Feature/Services/CallQualityCalculatorTest.php` | 5 MOS math tests |
| `tests/Feature/Http/CallQualityRouteTest.php` | 5 HTTP layer tests |

### Files to modify (6)

| File | Change |
|---|---|
| `app/Models/CallLog.php` | Add `quality_metrics` to `$fillable` and `'quality_metrics' => 'array'` to `casts()` |
| `app/Http/Controllers/CallController.php` | New `quality()` method + dependency-inject `CallQualityCalculator` |
| `routes/web.php` | New `Route::post('/calls/{call}/quality', ...)` inside `permission:conversations.reply` group |
| `resources/js/calls.js` (Phase 17) | Import collector + start on peer connected + stop & POST on teardown |
| `resources/js/outbound-call.js` (Phase 18) | Same import + same start/stop pattern in both `outgoingCall` and `incomingAtCall` factories |
| `resources/views/calls/index.blade.php` | Add "Quality" column header + per-row MOS chip with tooltip |

(11 files total: 5 created + 6 modified.)

## Operational notes

- **Storage**: ~250 bytes per populated CallLog row. At 1000 calls/day, 91MB/year. Trivial.
- **Browser overhead**: `setInterval(getStats, 5000)` runs in JS heap, ~1-2ms per tick, invisible to call performance.
- **No new infrastructure**: reuses existing routes, middleware, views. Migration is the only schema change.
- **Production deploy**: standard `bash deploy.sh`. Migration auto-runs. New POST endpoint live immediately. Existing in-flight calls don't capture telemetry (helper loads on next page reload), but new calls do.
- **Building assets**: `npm run build` includes the new `call-stats-collector.js` automatically since it's imported from existing entrypoints (`calls.js`, `outbound-call.js`).

## Known limitations / risks

- **Browser-only data source means tab-close mid-call loses telemetry.** Acceptable — call still completed; loss surfaces as gray "—" in the history. Phase 19c may add server-side fallback.
- **MOS formula is the G.107 simplified approximation.** Real-world calibration may need tuning once user-reported quality data exists. One PR + one test update.
- **AT SDK exposing `RTCPeerConnection`** is verified by Phase 18 Task 8. If a future AT SDK version hides the peer, the collector aggregate returns null and AT calls show gray "—" until the SDK is upgraded.
- **`ice_candidate_type` extraction** depends on the candidate-pair stat including a localCandidateId that contains 'host'/'srflx'/'relay' as substring. Most browsers do; some Safari versions delay the candidate-pair record. If telemetry shows lots of 'unknown' values, investigate browser-version distribution.
- **No mid-call data delivery.** If the agent's network drops mid-call and they can't POST on hangup, telemetry is lost. The data is in JS memory; we don't persist intermediate state.
- **No de-duplication on retry.** Last POST wins. If browser retries 3 times due to network blips, the third (likely identical) write replaces the first. Idempotent.

## Open follow-ups (not blockers)

- **AT SDK peer access verification**: Phase 18 Task 8 deferred this to deploy verification. If the SDK requires a different access pattern, the wiring in `outbound-call.js` adjusts; the collector helper is unchanged.
- **Codec normalization**: `mimeType.split('/')[1]?.toLowerCase()` works for `audio/opus` → `opus`. Edge cases (e.g., `audio/PCMU` → `pcmu`) are handled by `toLowerCase()`. If unusual codec names emerge in production data, add explicit normalization.
- **MOS calibration** against user-reported quality is a future tuning pass. Document the calibration constants (`2.5`, `0.05`, `0.024`) in a comment block with rationale; future tweaks can reference the comment.
