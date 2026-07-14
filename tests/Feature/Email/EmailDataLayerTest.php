<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailDataLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_contact_can_be_email_only_now_that_phone_is_nullable(): void
    {
        $contact = Contact::factory()->create([
            'phone' => null,
            'email' => 'prospect@example.com',
        ]);

        $this->assertNull($contact->fresh()->phone);
        $this->assertSame('prospect@example.com', $contact->fresh()->email);
    }

    public function test_email_campaign_targets_groups_and_has_logs(): void
    {
        $user = User::factory()->create();
        $group = ContactGroup::create(['user_id' => $user->id, 'name' => 'Prospects']);
        $campaign = EmailCampaign::factory()->create(['user_id' => $user->id]);

        $campaign->contactGroups()->attach($group->id);
        EmailLog::factory()->create(['email_campaign_id' => $campaign->id, 'email' => 'x@example.com']);

        $this->assertTrue($campaign->contactGroups->contains($group));
        $this->assertSame(1, $campaign->logs()->count());
    }

    public function test_suppression_is_case_insensitive_and_idempotent(): void
    {
        EmailSuppression::suppress('Person@Example.com');
        EmailSuppression::suppress('person@example.com'); // dup, different case

        $this->assertSame(1, EmailSuppression::count());
        $this->assertTrue(EmailSuppression::isSuppressed('PERSON@example.com'));
        $this->assertFalse(EmailSuppression::isSuppressed('someone-else@example.com'));
        $this->assertFalse(EmailSuppression::isSuppressed(null));
    }
}
