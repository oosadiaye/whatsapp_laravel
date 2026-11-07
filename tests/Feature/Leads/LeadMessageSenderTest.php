<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Jobs\SendUserEmail;
use App\Models\EmailAccount;
use App\Models\EmailSuppression;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadProfile;
use App\Services\Leads\Outreach\LeadMessageSender;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class LeadMessageSenderTest extends TestCase
{
    use RefreshDatabase;

    private function profile(array $o = []): LeadProfile
    {
        $account = EmailAccount::factory()->create();

        return LeadProfile::factory()->create(array_merge([
            'sender_email_account_id' => $account->id, 'daily_send_cap' => 20, 'followup_count' => 2, 'followup_delay_days' => 3,
        ], $o));
    }

    private function message(LeadProfile $profile, array $lead = [], array $msg = []): LeadMessage
    {
        $lead = Lead::factory()->create(array_merge([
            'lead_profile_id' => $profile->id, 'email' => 'ada@acme.test', 'status' => Lead::STATUS_QUALIFIED,
        ], $lead));

        return LeadMessage::create(array_merge([
            'lead_id' => $lead->id, 'kind' => 'first', 'status' => 'approved', 'subject' => 'Hello', 'body' => "Hi Ada\n\nA proper body.",
        ], $msg));
    }

    private function sender(): LeadMessageSender
    {
        return app(LeadMessageSender::class);
    }

    public function test_an_approved_first_email_is_queued_with_an_unsubscribe_link_and_the_lead_becomes_contacted(): void
    {
        Queue::fake();
        $message = $this->message($this->profile());

        $this->assertSame('sent', $this->sender()->send($message));

        Queue::assertPushed(SendUserEmail::class, function (SendUserEmail $job) {
            return $job->email->to === ['ada@acme.test']
                && $job->email->subject === 'Hello'
                && str_contains((string) $job->email->unsubscribeUrl, '/email/lead-unsubscribe/');
        });
        $lead = $message->lead()->first();
        $this->assertSame(Lead::STATUS_CONTACTED, $lead->status);
        $this->assertNotNull($lead->outreach_started_at);
        $this->assertNotNull($lead->next_followup_at, 'a follow-up is scheduled');
        $this->assertSame('sent', $message->fresh()->status);
        $this->assertDatabaseHas('lead_events', ['lead_id' => $lead->id, 'type' => 'email_sent']);
    }

    public function test_a_message_is_sent_only_once(): void
    {
        Queue::fake();
        $message = $this->message($this->profile());

        $this->sender()->send($message);
        $this->assertSame('skipped', $this->sender()->send($message));

        Queue::assertPushed(SendUserEmail::class, 1);
    }

    public function test_the_kill_switch_holds_everything_back(): void
    {
        Queue::fake();
        LeadConfig::setKillSwitch(true);
        $message = $this->message($this->profile());

        $this->assertSame('waiting', $this->sender()->send($message));
        $this->assertSame(0, $this->sender()->sweep());

        Queue::assertNothingPushed();
        $this->assertSame('approved', $message->fresh()->status);
    }

    public function test_a_suppressed_address_is_never_emailed_and_the_lead_is_unsubscribed(): void
    {
        Queue::fake();
        EmailSuppression::suppress('ada@acme.test');
        $message = $this->message($this->profile());

        $this->assertSame('discarded', $this->sender()->send($message));

        Queue::assertNothingPushed();
        $this->assertSame(Lead::STATUS_UNSUBSCRIBED, $message->lead()->first()->status);
    }

    public function test_a_lead_that_already_replied_gets_no_more_outreach_but_can_get_an_answer(): void
    {
        Queue::fake();
        $profile = $this->profile();
        $followUp = $this->message($profile, ['status' => Lead::STATUS_REPLIED], ['kind' => 'followup']);
        $answer = $this->message($profile, ['status' => Lead::STATUS_REPLIED, 'email' => 'bo@acme.test'], ['kind' => 'reply']);

        $this->assertSame('discarded', $this->sender()->send($followUp));
        $this->assertSame('sent', $this->sender()->send($answer));
    }

    public function test_the_daily_send_cap_defers_extra_messages_to_the_next_sweep(): void
    {
        Queue::fake();
        $profile = $this->profile(['daily_send_cap' => 1]);
        $first = $this->message($profile);
        $second = $this->message($profile, ['email' => 'bo@acme.test']);

        $this->assertSame(1, $this->sender()->sweep());

        $this->assertSame('sent', $first->fresh()->status);
        $this->assertSame('approved', $second->fresh()->status, 'still waiting, not lost');
    }

    public function test_replies_are_not_counted_against_the_cap(): void
    {
        Queue::fake();
        $profile = $this->profile(['daily_send_cap' => 1]);
        $this->message($profile);
        $reply = $this->message($profile, ['email' => 'bo@acme.test', 'status' => Lead::STATUS_REPLIED], ['kind' => 'reply']);

        $this->sender()->sweep();

        $this->assertSame('sent', $reply->fresh()->status);
    }

    public function test_a_missing_sender_mailbox_fails_the_message_audibly(): void
    {
        Queue::fake();
        $profile = $this->profile(['sender_email_account_id' => null]);
        $message = $this->message($profile);

        $this->assertSame('failed', $this->sender()->send($message));

        Queue::assertNothingPushed();
        $this->assertStringContainsString('mailbox', $message->fresh()->failure);
        $this->assertStringContainsString('mailbox', $profile->fresh()->last_outreach_error);
    }

    public function test_follow_ups_stop_being_scheduled_after_the_last_one(): void
    {
        Queue::fake();
        $profile = $this->profile(['followup_count' => 1]);
        $message = $this->message($profile, ['status' => Lead::STATUS_CONTACTED, 'followups_sent' => 0], ['kind' => 'followup']);

        $this->sender()->send($message);

        $lead = $message->lead()->first();
        $this->assertSame(1, $lead->followups_sent);
        $this->assertNull($lead->next_followup_at);
    }

    public function test_the_unsubscribe_link_suppresses_the_address_and_discards_waiting_mail(): void
    {
        $profile = $this->profile();
        $message = $this->message($profile, [], ['status' => 'pending_approval']);
        $url = \Illuminate\Support\Facades\URL::signedRoute('email.lead-unsubscribe', ['lead' => $message->lead_id]);

        $this->get($url)->assertOk();

        $this->assertTrue(EmailSuppression::isSuppressed('ada@acme.test'));
        $this->assertSame(Lead::STATUS_UNSUBSCRIBED, $message->lead()->first()->status);
        $this->assertSame('discarded', $message->fresh()->status);
    }

    public function test_an_unsigned_unsubscribe_link_is_refused(): void
    {
        $lead = Lead::factory()->create(['email' => 'x@acme.test']);

        $this->get('/email/lead-unsubscribe/'.$lead->id)->assertForbidden();
    }
}
