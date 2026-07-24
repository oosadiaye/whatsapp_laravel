<?php

declare(strict_types=1);

namespace Tests\Feature\Infra;

use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Guards audit H2: Horizon starts supervisors by matching app.env, so any
 * deploy environment must define a supervisor for EVERY queue or those jobs
 * silently never run. This pins staging to production's queue coverage so the
 * two can't drift (e.g. a new queue added to production but forgotten in
 * staging).
 */
class HorizonEnvironmentsTest extends TestCase
{
    /**
     * @param  array<string, array{queue: list<string>}>  $environment
     * @return list<string>
     */
    private function queuesOf(array $environment): array
    {
        return (new Collection($environment))
            ->flatMap(fn (array $supervisor): array => $supervisor['queue'])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function test_staging_covers_every_production_queue(): void
    {
        $environments = config('horizon.environments');

        $this->assertArrayHasKey('staging', $environments, 'a staging Horizon env must exist (H2)');
        $this->assertSame(
            $this->queuesOf($environments['production']),
            $this->queuesOf($environments['staging']),
            'staging Horizon env must run a worker for every production queue',
        );
    }

    public function test_the_mail_client_queues_have_workers_in_every_deploy_env(): void
    {
        $environments = config('horizon.environments');

        foreach (['production', 'staging'] as $name) {
            $queues = $this->queuesOf($environments[$name]);
            $this->assertContains('mail-sync', $queues, "{$name} must run the mail-sync queue");
            $this->assertContains('mail-send', $queues, "{$name} must run the mail-send queue");
        }
    }
}
