<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Jobs\SendUserEmail;
use App\Models\Contact;
use App\Models\EmailAccount;
use App\Models\EmailSequence;
use App\Models\EmailSequenceRecipient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Covers the Email Sequences feature that shipped non-functional: the fatal
 * Undefined-constant crash (C2), the missing recipient-enrolment path (C3), the
 * cross-account send-as validation (H2), and the launch-without-account guard.
 */
class EmailSequenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(?string $email = null): User
    {
        $user = User::factory()->create([
            'email' => $email ?? 'admin-'.uniqid().'@example.com',
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function sequenceWithStep(User $owner, ?int $accountId = null, string $status = EmailSequence::STATUS_DRAFT): EmailSequence
    {
        $sequence = EmailSequence::create([
            'user_id' => $owner->id,
            'email_account_id' => $accountId,
            'name' => 'Cold outreach',
            'status' => $status,
        ]);
        $sequence->steps()->create([
            'order' => 0,
            'subject' => 'Hello',
            'body_text' => 'Hi there',
            'body_html' => '',
            'delay_days' => 0,
            'delay_hours' => 0,
        ]);

        return $sequence;
    }

    // ---- C3: enrolment ------------------------------------------------------

    public function test_enrol_creates_recipient_rows_only_for_contacts_with_an_email(): void
    {
        $admin = $this->admin();
        $sequence = $this->sequenceWithStep($admin);

        Contact::factory()->create(['name' => 'Has email', 'email' => 'a@example.com']);
        Contact::factory()->create(['name' => 'Also email', 'email' => 'b@example.com']);
        Contact::factory()->create(['name' => 'No email', 'email' => null]);

        $this->actingAs($admin)
            ->post(route('email-sequences.enroll', $sequence))
            ->assertRedirect()
            ->assertSessionHas('success');

        $recipients = EmailSequenceRecipient::where('email_sequence_id', $sequence->id)->get();
        $this->assertCount(2, $recipients);
        $this->assertSame(EmailSequence::RECIPIENT_PENDING, $recipients->first()->status);
        $this->assertSame(0, $recipients->first()->current_step);
    }

    public function test_enrol_is_idempotent_and_never_duplicates_a_recipient(): void
    {
        $admin = $this->admin();
        $sequence = $this->sequenceWithStep($admin);
        Contact::factory()->create(['email' => 'a@example.com']);

        $this->actingAs($admin)->post(route('email-sequences.enroll', $sequence));
        $this->actingAs($admin)->post(route('email-sequences.enroll', $sequence));

        $this->assertSame(1, EmailSequenceRecipient::where('email_sequence_id', $sequence->id)->count());
    }

    public function test_enrol_requires_at_least_one_step(): void
    {
        $admin = $this->admin();
        $sequence = EmailSequence::create([
            'user_id' => $admin->id,
            'name' => 'No steps',
            'status' => EmailSequence::STATUS_DRAFT,
        ]);
        Contact::factory()->create(['email' => 'a@example.com']);

        $this->actingAs($admin)
            ->post(route('email-sequences.enroll', $sequence))
            ->assertSessionHas('error');

        $this->assertSame(0, EmailSequenceRecipient::count());
    }

    // ---- C2: the processor runs without the Undefined-constant crash --------

    public function test_process_command_advances_a_due_recipient_without_crashing(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $account = EmailAccount::factory()->create(['user_id' => $admin->id, 'is_active' => true]);
        $sequence = $this->sequenceWithStep($admin, $account->id, EmailSequence::STATUS_ACTIVE);

        $contact = Contact::factory()->create(['email' => 'c@example.com']);
        EmailSequenceRecipient::create([
            'email_sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'email' => 'c@example.com',
            'current_step' => 0,
            'status' => EmailSequence::RECIPIENT_PENDING,
            'next_send_at' => now()->subMinute(),
        ]);

        // Before C2 this threw a fatal "Undefined constant
        // EmailSequenceRecipient::RECIPIENT_PENDING". It must now complete.
        $this->artisan('email-sequences:process')->assertExitCode(0);

        Queue::assertPushed(SendUserEmail::class);

        // One-step sequence: the recipient completes as SENT.
        $this->assertSame(
            EmailSequence::RECIPIENT_SENT,
            EmailSequenceRecipient::where('email_sequence_id', $sequence->id)->first()->status,
        );
    }

    // ---- H2: cross-account send-as ------------------------------------------

    public function test_store_rejects_an_email_account_owned_by_another_user(): void
    {
        $admin = $this->admin();
        $other = $this->admin('other@example.com');
        $othersAccount = EmailAccount::factory()->create(['user_id' => $other->id]);

        $this->actingAs($admin)
            ->post(route('email-sequences.store'), [
                'name' => 'Impersonation attempt',
                'email_account_id' => $othersAccount->id,
            ])
            ->assertSessionHasErrors('email_account_id');

        $this->assertSame(0, EmailSequence::count());
    }

    public function test_store_accepts_the_callers_own_account(): void
    {
        $admin = $this->admin();
        $ownAccount = EmailAccount::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->post(route('email-sequences.store'), [
                'name' => 'Legit',
                'email_account_id' => $ownAccount->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, EmailSequence::where('email_account_id', $ownAccount->id)->count());
    }

    // ---- launch guard -------------------------------------------------------

    public function test_launch_is_refused_when_the_owner_has_no_sending_account(): void
    {
        $admin = $this->admin();
        $sequence = $this->sequenceWithStep($admin);

        $this->actingAs($admin)
            ->post(route('email-sequences.launch', $sequence))
            ->assertSessionHas('error');

        $this->assertSame(EmailSequence::STATUS_DRAFT, $sequence->fresh()->status);
    }

    public function test_launch_falls_back_to_the_owners_active_account(): void
    {
        $admin = $this->admin();
        $account = EmailAccount::factory()->create(['user_id' => $admin->id, 'is_active' => true]);
        $sequence = $this->sequenceWithStep($admin);

        $this->actingAs($admin)->post(route('email-sequences.launch', $sequence));

        $sequence->refresh();
        $this->assertSame(EmailSequence::STATUS_ACTIVE, $sequence->status);
        $this->assertSame($account->id, $sequence->email_account_id);
    }
}
