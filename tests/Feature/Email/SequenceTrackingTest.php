<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailSequence;
use App\Models\EmailSequenceRecipient;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Sequence open-tracking + unsubscribe (signed per-recipient URLs). The
 * EmailSequenceRecipient is the attribution target for automated sequence mail.
 */
class SequenceTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function recipient(string $status, string $email = 'seq@example.com'): EmailSequenceRecipient
    {
        $sequence = EmailSequence::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Seq',
            'status' => EmailSequence::STATUS_ACTIVE,
        ]);

        return EmailSequenceRecipient::create([
            'email_sequence_id' => $sequence->id,
            'email' => $email,
            'current_step' => 0,
            'status' => $status,
        ]);
    }

    public function test_pixel_records_the_first_open_only(): void
    {
        $recipient = $this->recipient(EmailSequence::RECIPIENT_SENT);
        $url = URL::signedRoute('email.sequence-open', ['recipient' => $recipient->id]);

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->get($url)->assertOk(); // second fetch — not double-counted

        $recipient->refresh();
        $this->assertSame(1, $recipient->open_count);
        $this->assertSame(EmailSequence::RECIPIENT_OPENED, $recipient->status);
    }

    public function test_a_never_sent_recipient_pixel_is_not_counted(): void
    {
        $recipient = $this->recipient(EmailSequence::RECIPIENT_PENDING);

        $this->get(URL::signedRoute('email.sequence-open', ['recipient' => $recipient->id]))->assertOk();

        $this->assertSame(0, $recipient->fresh()->open_count);
        $this->assertSame(EmailSequence::RECIPIENT_PENDING, $recipient->fresh()->status);
    }

    public function test_an_unsigned_pixel_url_is_rejected(): void
    {
        $recipient = $this->recipient(EmailSequence::RECIPIENT_SENT);

        $this->get(route('email.sequence-open', ['recipient' => $recipient->id]))->assertForbidden();
        $this->assertSame(0, $recipient->fresh()->open_count);
    }

    public function test_unsubscribe_suppresses_and_marks_the_recipient(): void
    {
        $recipient = $this->recipient(EmailSequence::RECIPIENT_SENT, 'unsub@example.com');

        $this->get(URL::signedRoute('email.sequence-unsubscribe', ['recipient' => $recipient->id]))->assertOk();

        $this->assertTrue(EmailSuppression::isSuppressed('unsub@example.com'));
        $this->assertSame(EmailSequence::RECIPIENT_UNSUBSCRIBED, $recipient->fresh()->status);
        $this->assertNotNull($recipient->fresh()->completed_at);
    }

    public function test_an_unsigned_unsubscribe_url_is_rejected(): void
    {
        $recipient = $this->recipient(EmailSequence::RECIPIENT_SENT, 'no@example.com');

        $this->get(route('email.sequence-unsubscribe', ['recipient' => $recipient->id]))->assertForbidden();
        $this->assertFalse(EmailSuppression::isSuppressed('no@example.com'));
        $this->assertSame(EmailSequence::RECIPIENT_SENT, $recipient->fresh()->status);
    }
}
