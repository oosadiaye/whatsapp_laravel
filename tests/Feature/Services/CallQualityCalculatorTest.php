<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\CallQualityCalculator;
use Tests\TestCase;

class CallQualityCalculatorTest extends TestCase
{
    public function test_excellent_call_yields_mos_above_4(): void
    {
        $calculator = new CallQualityCalculator();

        // 0% packet loss, 5ms jitter, 50ms RTT — pristine call
        $mos = $calculator->computeMos(
            packetLossPct: 0.0,
            jitterMs: 5.0,
            rttMs: 50,
        );

        $this->assertGreaterThanOrEqual(4.0, $mos, "Expected MOS ≥ 4.0 for excellent call, got {$mos}");
    }

    public function test_poor_call_yields_mos_below_3(): void
    {
        $calculator = new CallQualityCalculator();

        // 5% packet loss, 100ms jitter, 400ms RTT — degraded
        $mos = $calculator->computeMos(
            packetLossPct: 5.0,
            jitterMs: 100.0,
            rttMs: 400,
        );

        $this->assertLessThan(3.0, $mos, "Expected MOS < 3.0 for poor call, got {$mos}");
    }

    public function test_zero_inputs_yield_high_mos(): void
    {
        $calculator = new CallQualityCalculator();

        // 0/0/0 — theoretical perfect conditions
        $mos = $calculator->computeMos(0.0, 0.0, 0);

        $this->assertGreaterThan(4.0, $mos);
        $this->assertLessThanOrEqual(5.0, $mos);
    }

    public function test_extreme_packet_loss_clamped_to_min_one(): void
    {
        $calculator = new CallQualityCalculator();

        // 100% loss → R-factor goes deeply negative; MOS must clamp to 1.0
        $mos = $calculator->computeMos(100.0, 1000.0, 5000);

        $this->assertSame(1.0, $mos);
    }

    public function test_returns_two_decimal_precision(): void
    {
        $calculator = new CallQualityCalculator();

        $mos = $calculator->computeMos(1.0, 20.0, 100);

        // Verify exactly 2 decimal places by comparing to its rounded self.
        $this->assertSame(round($mos, 2), $mos);
    }

    public function test_calibration_weights_are_config_driven(): void
    {
        // Pin the config-overridability contract introduced when the
        // hardcoded magic numbers moved to config/voice.php. Doubling the
        // packet-loss weight from 4.0 → 8.0 must produce a strictly LOWER
        // MOS for the same input — proving the config value reaches the
        // calculation rather than being ignored.
        $calculator = new CallQualityCalculator();

        $packetLoss = 5.0;
        $jitter = 10.0;
        $rtt = 100;

        $baseline = $calculator->computeMos($packetLoss, $jitter, $rtt);

        // Boost the packet-loss penalty.
        config()->set('voice.mos.packet_loss_weight', 8.0);
        $stricter = $calculator->computeMos($packetLoss, $jitter, $rtt);

        $this->assertLessThan(
            $baseline,
            $stricter,
            'Doubling packet_loss_weight must lower MOS for the same input — proves config drives the formula.',
        );
    }
}
