<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Events\Calling\CallTerminated;
use App\Models\CallLog;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CleanupStaleCallsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_thirty_minute_stale_ringing_marked_missed(): void
    {
        $call = $this->makeCall(CallLog::STATUS_RINGING, now()->subMinutes(35));

        $this->artisan('calls:cleanup-stale')->assertSuccessful();

        $call->refresh();
        $this->assertSame(CallLog::STATUS_MISSED, $call->status);
        $this->assertSame('stale - no terminate webhook received', $call->failure_reason);
        $this->assertNotNull($call->ended_at);
    }

    public function test_thirty_minute_stale_connected_marked_ended(): void
    {
        $call = $this->makeCall(CallLog::STATUS_CONNECTED, now()->subMinutes(45));

        $this->artisan('calls:cleanup-stale')->assertSuccessful();

        $call->refresh();
        $this->assertSame(CallLog::STATUS_ENDED, $call->status);
        $this->assertSame('stale - no terminate webhook received', $call->failure_reason);
    }

    public function test_recent_calls_untouched(): void
    {
        $recent = $this->makeCall(CallLog::STATUS_RINGING, now()->subMinutes(5));

        $this->artisan('calls:cleanup-stale')->assertSuccessful();

        $this->assertSame(CallLog::STATUS_RINGING, $recent->fresh()->status);
        $this->assertNull($recent->fresh()->ended_at);
    }

    public function test_stale_call_dispatches_terminated_event(): void
    {
        Event::fake([CallTerminated::class]);
        $call = $this->makeCall(CallLog::STATUS_RINGING, now()->subMinutes(35));

        $this->artisan('calls:cleanup-stale')->assertSuccessful();

        Event::assertDispatched(CallTerminated::class, function ($event) use ($call) {
            return $event->call->id === $call->id && $event->reason === 'stale_cleanup';
        });
    }

    private function makeCall(string $status, \Illuminate\Support\Carbon $startedAt): CallLog
    {
        $owner = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $owner->assignRole(User::ROLE_ADMIN);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'is_active' => true]);
        $agent->assignRole(User::ROLE_AGENT);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $owner->id]);
        $contact = Contact::factory()->create(['user_id' => $owner->id, 'phone' => '23480'.fake()->unique()->numerify('########')]);
        $conversation = \App\Models\Conversation::create([
            'user_id' => $owner->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $agent->id,
            'unread_count' => 0,
        ]);

        return CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'meta_call_id' => 'wacid.'.fake()->unique()->numerify('########'),
            'status' => $status,
            'started_at' => $startedAt,
            'from_phone' => $contact->phone,
            'to_phone' => $instance->phone_number ?? '2348000000000',
        ]);
    }
}
