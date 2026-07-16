<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Jobs\SendCampaignEmail;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\EmailSuppression;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailFailureAndSuppressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_run_where_everything_fails_marks_the_campaign_failed(): void
    {
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('smtp down'));

        $campaign = EmailCampaign::factory()->create([
            'status' => EmailCampaign::STATUS_SENDING, 'sent_count' => 0, 'failed_count' => 0,
        ]);
        $log = EmailLog::factory()->create(['email_campaign_id' => $campaign->id, 'status' => EmailLog::STATUS_QUEUED]);

        (new SendCampaignEmail($log->id))->handle();

        $this->assertSame(EmailLog::STATUS_FAILED, $log->fresh()->status);
        $this->assertSame(EmailCampaign::STATUS_FAILED, $campaign->fresh()->status);
    }

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('admin'); // has email.view + email.edit

        return $u;
    }

    public function test_operator_can_add_and_remove_a_suppression(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('email-suppressions.store'), ['email' => 'Bounce@Example.com', 'reason' => 'bounce'])
            ->assertRedirect();
        $this->assertTrue(EmailSuppression::isSuppressed('bounce@example.com'));

        $s = EmailSuppression::first();
        $this->actingAs($admin)->delete(route('email-suppressions.destroy', $s))->assertRedirect();
        $this->assertFalse(EmailSuppression::isSuppressed('bounce@example.com'));
    }

    public function test_suppression_list_is_gated_by_email_permission(): void
    {
        $noRole = User::factory()->create(['is_active' => true]);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($noRole)->get(route('email-suppressions.index'))->assertForbidden();
    }
}
