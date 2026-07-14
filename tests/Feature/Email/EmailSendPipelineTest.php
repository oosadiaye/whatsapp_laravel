<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Jobs\EmailCampaignDispatch;
use App\Jobs\SendCampaignEmail;
use App\Mail\CampaignEmail;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailSendPipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ContactGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->group = ContactGroup::create(['user_id' => $this->user->id, 'name' => 'Prospects']);
    }

    private function contactInGroup(array $attrs): Contact
    {
        $contact = Contact::factory()->create(array_merge(['user_id' => $this->user->id], $attrs));
        $this->group->contacts()->attach($contact->id);

        return $contact;
    }

    private function campaign(): EmailCampaign
    {
        $c = EmailCampaign::factory()->create(['user_id' => $this->user->id, 'rate_per_minute' => 600]);
        $c->contactGroups()->attach($this->group->id);

        return $c;
    }

    public function test_dispatch_sends_only_to_valid_recipients(): void
    {
        Mail::fake();

        $this->contactInGroup(['email' => 'ann@example.com', 'is_active' => true]);
        $this->contactInGroup(['email' => 'bob@example.com', 'is_active' => true]);
        $this->contactInGroup(['email' => 'sup@example.com', 'is_active' => true]); // suppressed
        $this->contactInGroup(['email' => null, 'phone' => '2348000000000', 'is_active' => true]); // no email
        $this->contactInGroup(['email' => 'off@example.com', 'is_active' => false]); // inactive
        EmailSuppression::suppress('sup@example.com');

        $campaign = $this->campaign();

        (new EmailCampaignDispatch($campaign->id))->handle(app(\App\Services\EmailCampaignService::class));

        Mail::assertSent(CampaignEmail::class, fn ($m) => $m->hasTo('ann@example.com'));
        Mail::assertSent(CampaignEmail::class, fn ($m) => $m->hasTo('bob@example.com'));
        Mail::assertNotSent(CampaignEmail::class, fn ($m) => $m->hasTo('sup@example.com'));
        Mail::assertNotSent(CampaignEmail::class, fn ($m) => $m->hasTo('off@example.com'));
        Mail::assertSentCount(2);

        $campaign->refresh();
        $this->assertSame(2, $campaign->total_recipients);
        $this->assertSame(2, $campaign->sent_count);
        $this->assertSame(EmailCampaign::STATUS_SENT, $campaign->status);
        $this->assertSame(2, EmailLog::where('status', EmailLog::STATUS_SENT)->count());
    }

    public function test_dedupes_recipients_by_email(): void
    {
        Mail::fake();
        $this->contactInGroup(['email' => 'Dup@Example.com', 'is_active' => true]);
        $this->contactInGroup(['email' => 'dup@example.com', 'is_active' => true]);

        (new EmailCampaignDispatch($this->campaign()->id))->handle(app(\App\Services\EmailCampaignService::class));

        Mail::assertSentCount(1);
    }

    public function test_send_job_skips_an_address_suppressed_after_fanout(): void
    {
        Mail::fake();
        $campaign = $this->campaign();
        $log = EmailLog::factory()->create([
            'email_campaign_id' => $campaign->id,
            'email' => 'late@example.com',
            'status' => EmailLog::STATUS_QUEUED,
        ]);
        EmailSuppression::suppress('late@example.com'); // after the log was queued

        (new SendCampaignEmail($log->id))->handle();

        Mail::assertNothingSent();
        $this->assertSame(EmailLog::STATUS_UNSUBSCRIBED, $log->fresh()->status);
    }

    public function test_empty_audience_completes_the_campaign(): void
    {
        Mail::fake();
        $campaign = $this->campaign(); // group has no contacts

        (new EmailCampaignDispatch($campaign->id))->handle(app(\App\Services\EmailCampaignService::class));

        Mail::assertNothingSent();
        $this->assertSame(EmailCampaign::STATUS_SENT, $campaign->fresh()->status);
        $this->assertSame(0, $campaign->fresh()->total_recipients);
    }
}
