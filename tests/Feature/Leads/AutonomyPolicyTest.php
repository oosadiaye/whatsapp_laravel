<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\LeadProfile;
use App\Services\Leads\Outreach\AutonomyPolicy;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AutonomyPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function matrix(): array
    {
        return [
            'manual' => [AutonomyPolicy::APPROVE_ALL, false, false],
            'semi' => [AutonomyPolicy::AUTO_FIRST, true, false],
            'full' => [AutonomyPolicy::FULL_AUTO, true, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('matrix')]
    public function test_every_level_answers_the_two_questions_as_the_matrix_says(string $level, bool $outreach, bool $replies): void
    {
        $profile = LeadProfile::factory()->create(['autonomy' => $level]);
        $policy = new AutonomyPolicy;

        $this->assertSame($outreach, $policy->sendsOutreachAutomatically($profile));
        $this->assertSame($replies, $policy->sendsRepliesAutomatically($profile));
    }

    public function test_a_profile_without_a_choice_uses_the_global_default(): void
    {
        LeadConfig::setAutonomyDefault(AutonomyPolicy::FULL_AUTO);

        $this->assertSame(AutonomyPolicy::FULL_AUTO, (new AutonomyPolicy)->levelFor(LeadProfile::factory()->create(['autonomy' => null])));
    }

    public function test_the_profile_choice_beats_the_global_default(): void
    {
        LeadConfig::setAutonomyDefault(AutonomyPolicy::FULL_AUTO);

        $this->assertSame(AutonomyPolicy::APPROVE_ALL, (new AutonomyPolicy)->levelFor(LeadProfile::factory()->create(['autonomy' => AutonomyPolicy::APPROVE_ALL])));
    }

    public function test_nothing_configured_means_the_safest_level(): void
    {
        $this->assertSame(AutonomyPolicy::APPROVE_ALL, LeadConfig::autonomyDefault());
        $this->assertSame(AutonomyPolicy::APPROVE_ALL, (new AutonomyPolicy)->levelFor(LeadProfile::factory()->create(['autonomy' => 'rubbish'])));
    }
}
