<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Mail\CampaignEmail;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailOpenTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function log(array $attrs = []): EmailLog
    {
        $campaign = EmailCampaign::factory()->create(['sent_count' => 1, 'opened_count' => 0]);

        return EmailLog::factory()->create(array_merge([
            'email_campaign_id' => $campaign->id,
            'status' => EmailLog::STATUS_SENT,
        ], $attrs));
    }

    public function test_pixel_records_the_open_and_returns_a_gif(): void
    {
        $log = $this->log();

        $this->get(URL::signedRoute('email.open', ['log' => $log->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/gif');

        $log->refresh();
        $this->assertNotNull($log->opened_at);
        $this->assertSame(EmailLog::STATUS_OPENED, $log->status);
        $this->assertSame(1, $log->campaign->fresh()->opened_count);
    }

    public function test_second_open_does_not_double_count(): void
    {
        $log = $this->log();
        $url = URL::signedRoute('email.open', ['log' => $log->id]);

        $this->get($url)->assertOk();
        $this->get($url)->assertOk();

        $this->assertSame(1, $log->campaign->fresh()->opened_count);
    }

    public function test_an_unsigned_pixel_url_is_rejected(): void
    {
        $log = $this->log();

        $this->get(route('email.open', ['log' => $log->id]))->assertForbidden();
        $this->assertNull($log->fresh()->opened_at);
    }

    public function test_mailable_embeds_a_tracking_pixel_only_when_a_log_is_given(): void
    {
        $campaign = EmailCampaign::factory()->create();

        $withLog = new CampaignEmail($campaign, 'x@example.com', 'X', 4242);
        $withLog->assertSeeInHtml('/email/open/4242', false);

        $withoutLog = new CampaignEmail($campaign, 'x@example.com', 'X');
        $withoutLog->assertDontSeeInHtml('/email/open/', false);
    }
}
