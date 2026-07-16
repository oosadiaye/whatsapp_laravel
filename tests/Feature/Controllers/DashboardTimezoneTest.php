<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_today_counts_the_business_day_not_utc(): void
    {
        config(['app.business_timezone' => 'Africa/Lagos']);
        $user = User::factory()->create(['is_active' => true]);

        // Now = 08:00 Lagos on the 16th.
        $this->travelTo(Carbon::parse('2026-07-16 08:00', 'Africa/Lagos'));

        $campaign = Campaign::factory()->create();
        $contact = Contact::factory()->create();

        // 00:30 Lagos today == 23:30 UTC yesterday — must still count as "today".
        MessageLog::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'phone' => '2348000000001', 'status' => 'SENT', 'sent_at' => Carbon::parse('2026-07-16 00:30', 'Africa/Lagos')]);
        // Genuinely yesterday (Lagos) — must NOT count.
        MessageLog::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'phone' => '2348000000002', 'status' => 'SENT', 'sent_at' => Carbon::parse('2026-07-15 09:00', 'Africa/Lagos')]);

        $messagesToday = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->viewData('messagesToday');

        $this->assertSame(1, $messagesToday);
    }
}
