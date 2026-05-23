<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Computes a Mean Opinion Score (MOS, 1.0-5.0) from raw WebRTC stats
 * using the ITU-T G.107 E-model approximation.
 *
 * Reference: ITU-T G.107 (06/2015) E-model, simplified for VoIP.
 * Calibration weights live in config/voice.php so operators can tune
 * them against user-reported quality without a code change (and
 * without rebuilding the JS bundle, since these are server-side).
 *
 * Why config-driven: the customer base spans LTE / fibre / 3G; the
 * default constants are industry-standard (Twilio/Vonage) but a real
 * deployment may want stricter weights once enough data accumulates
 * to calibrate against subjective complaints.
 */
class CallQualityCalculator
{
    public function computeMos(
        float $packetLossPct,
        float $jitterMs,
        int $rttMs,
    ): float {
        // R-factor approximation: starts at theoretical max (default 93.2),
        // subtracts a weighted contribution from each impairment metric.
        $r = (float) config('voice.mos.r_factor_max', 93.2)
            - $packetLossPct * (float) config('voice.mos.packet_loss_weight', 4.0)
            - $jitterMs * (float) config('voice.mos.jitter_weight_per_ms', 0.08)
            - $rttMs * (float) config('voice.mos.rtt_weight_per_ms', 0.032);

        // Clamp R to valid range (0-100) before MOS conversion.
        $r = max(0.0, min(100.0, $r));

        // E-model R → MOS conversion (cubic polynomial approximation).
        // The cubic coefficients (0.035, 0.000007, -60, 100) are the
        // mathematical form of the model itself, NOT calibration knobs —
        // changing them would mean using a different model entirely. Left
        // as inline constants so the formula's structure stays readable.
        $mos = 1
            + 0.035 * $r
            + 0.000007 * $r * ($r - 60) * (100 - $r);

        // Defensive clamp; G.107 yields values in (1.0, 4.5) but
        // floating-point edge cases could overshoot.
        return round(max(1.0, min(5.0, $mos)), 2);
    }
}
