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
}
