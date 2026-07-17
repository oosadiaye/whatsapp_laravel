<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Regression guard for audit L10 observability wiring (AppServiceProvider).
 */
class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_is_ok_when_the_database_is_reachable(): void
    {
        // The DiagnosingHealth listener probes the DB; with it reachable, /up
        // must still return 200 (it now returns non-200 when the DB is down).
        $this->get('/up')->assertOk();
    }

    public function test_queue_backlog_event_is_logged(): void
    {
        Log::spy();

        event(new QueueBusy('redis', 'messages', 250));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Queue backlog')
                && ($context['queue'] ?? null) === 'messages'
                && ($context['size'] ?? null) === 250)
            ->once();
    }
}
