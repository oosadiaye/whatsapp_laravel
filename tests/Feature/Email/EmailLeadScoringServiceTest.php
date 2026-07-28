<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\Contact;
use App\Models\EmailSequence;
use App\Models\EmailSequenceRecipient;
use App\Models\User;
use App\Services\EmailLeadScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lead scoring reads EmailSequenceRecipient rows. The status branch used the
 * constants that C2 fixed (EmailSequence::RECIPIENT_SENT/COMPLETED), so these
 * also guard against that fatal-Undefined-constant regression re-appearing.
 */
class EmailLeadScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private function recipient(Contact $contact, array $overrides): EmailSequenceRecipient
    {
        $sequence = EmailSequence::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Seq',
            'status' => EmailSequence::STATUS_ACTIVE,
        ]);

        return EmailSequenceRecipient::create(array_merge([
            'email_sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'current_step' => 0,
            'status' => EmailSequence::RECIPIENT_SENT,
            'open_count' => 0,
            'click_count' => 0,
            'reply_count' => 0,
        ], $overrides));
    }

    public function test_a_reply_scores_the_highest_and_persists_to_the_contact(): void
    {
        $contact = Contact::factory()->create(['email' => 'lead@example.com']);
        $this->recipient($contact, ['status' => EmailSequence::RECIPIENT_SENT, 'reply_count' => 1]);

        $score = (new EmailLeadScoringService())->scoreContact($contact->fresh());

        $this->assertSame(EmailLeadScoringService::SCORE_REPLIED, $score);
        $contact->refresh();
        $this->assertSame(EmailLeadScoringService::SCORE_REPLIED, $contact->email_lead_score);
        $this->assertSame(1, $contact->email_reply_count);
    }

    public function test_a_merely_sent_recipient_scores_the_base_amount(): void
    {
        // Exercises the exact in_array(..., [RECIPIENT_SENT, RECIPIENT_COMPLETED])
        // line that used to fatal on an Undefined constant (C2).
        $contact = Contact::factory()->create(['email' => 'lead@example.com']);
        $this->recipient($contact, ['status' => EmailSequence::RECIPIENT_COMPLETED]);

        $score = (new EmailLeadScoringService())->scoreContact($contact->fresh());

        $this->assertSame(EmailLeadScoringService::SCORE_SENT, $score);
    }

    public function test_score_all_updates_every_contact_that_has_recipients(): void
    {
        $a = Contact::factory()->create(['email' => 'a@example.com']);
        $b = Contact::factory()->create(['email' => 'b@example.com']);
        Contact::factory()->create(['email' => 'cold@example.com']); // no recipients
        $this->recipient($a, ['open_count' => 2]);
        $this->recipient($b, ['click_count' => 1]);

        $count = (new EmailLeadScoringService())->scoreAll();

        $this->assertSame(2, $count);
        $this->assertGreaterThan(0, $a->fresh()->email_lead_score);
        $this->assertGreaterThan(0, $b->fresh()->email_lead_score);
    }
}
