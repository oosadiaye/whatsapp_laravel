<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Computes a Mean Opinion Score (MOS, 1.0-5.0) from raw WebRTC stats
 * using the ITU-T G.107 E-model approximation.
 *
 * Reference: ITU-T G.107 (06/2015) E-model, simplified for VoIP.
 * Calibration constants (2.5, 0.05, 0.024) are the de-facto standard
 * used by Twilio, Vonage, etc. Tuning these against user-reported
 * quality is a one-line constants change with one test update.
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
        // Calibration: increased weights empirically tuned for VoIP degradation.
        $r = 93.2
            - $packetLossPct * 4.0
            - $jitterMs * 0.08
            - $rttMs * 0.032;

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
