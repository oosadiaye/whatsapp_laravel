<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Campaign;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies the scheduled sync command:
 *  - hits Meta only for Cloud-driver instances actually used in campaigns
 *  - upserts templates idempotently
 *  - --instance flag scopes to one instance
 *  - HTTP failure on one instance doesn't abort the whole run
 *
 * Without the "used in campaigns" filter the schedule would hit Meta for
 * every customer's instance every 15 minutes regardless of activity, which
 * is wasteful and pushes against Meta's rate limit.
 */
class SyncMessageTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_cloud_instances_with_missing_credentials(): void
    {
        $user = User::factory()->create();
        $cloud = WhatsAppInstance::factory()->create([
            'user_id' => $user->id,
            'access_token' => null,
        ]);
        Campaign::factory()->create(['user_id' => $user->id, 'instance_id' => $cloud->id]);

        Http::fake();
        Artisan::call('templates:sync-status');
        Http::assertNothingSent();
    }

    public function test_skips_cloud_instances_never_used_in_campaigns(): void
    {
        // No campaign → no point hitting Meta for this instance.
        $user = User::factory()->create();
        WhatsAppInstance::factory()->create(['user_id' => $user->id]);

        Http::fake();
        Artisan::call('templates:sync-status');
        Http::assertNothingSent();
    }

    public function test_syncs_active_cloud_instance_and_creates_templates(): void
    {
        $user = User::factory()->create();
        $cloud = WhatsAppInstance::factory()->create(['user_id' => $user->id]);
        Campaign::factory()->create(['user_id' => $user->id, 'instance_id' => $cloud->id]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [
                    [
                        'id' => '1001',
                        'name' => 'order_confirm',
                        'language' => 'en_US',
                        'status' => 'APPROVED',
                        'category' => 'UTILITY',
                        'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}']],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('templates:sync-status');

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, MessageTemplate::count());
        $this->assertSame('APPROVED', MessageTemplate::first()->status);
    }

    public function test_failure_on_one_instance_does_not_abort_others(): void
    {
        $user = User::factory()->create();

        $broken = WhatsAppInstance::factory()->create([
            'user_id' => $user->id,
            'waba_id' => 'BROKEN_WABA',
        ]);
        $working = WhatsAppInstance::factory()->create([
            'user_id' => $user->id,
            'waba_id' => 'WORKING_WABA',
        ]);
        Campaign::factory()->create(['user_id' => $user->id, 'instance_id' => $broken->id]);
        Campaign::factory()->create(['user_id' => $user->id, 'instance_id' => $working->id]);

        Http::fake([
            'graph.facebook.com/v20.0/BROKEN_WABA/*' => Http::response(['error' => 'oops'], 500),
            'graph.facebook.com/v20.0/WORKING_WABA/*' => Http::response([
                'data' => [[
                    'id' => '2002',
                    'name' => 'a',
                    'language' => 'en_US',
                    'status' => 'APPROVED',
                    'category' => 'UTILITY',
                    'components' => [],
                ]],
            ], 200),
        ]);

        $exitCode = Artisan::call('templates:sync-status');

        // Non-zero exit because one instance failed, but the working one still synced.
        $this->assertNotSame(0, $exitCode);
        $this->assertSame(1, MessageTemplate::where('whatsapp_instance_id', $working->id)->count());
    }

    public function test_instance_flag_scopes_to_a_single_instance(): void
    {
        $user = User::factory()->create();
        $a = WhatsAppInstance::factory()->create(['user_id' => $user->id, 'waba_id' => 'A']);
        $b = WhatsAppInstance::factory()->create(['user_id' => $user->id, 'waba_id' => 'B']);
        // Note: no campaigns required when --instance is supplied; flag bypasses the activity filter.

        Http::fake([
            'graph.facebook.com/v20.0/A/*' => Http::response(['data' => [
                ['id' => '3001', 'name' => 'a_only', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => []],
            ]], 200),
            'graph.facebook.com/v20.0/B/*' => Http::response(['data' => []], 200),
        ]);

        Artisan::call('templates:sync-status', ['--instance' => $a->id]);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/A/'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/B/'));
        $this->assertSame(1, MessageTemplate::count());
    }
}
