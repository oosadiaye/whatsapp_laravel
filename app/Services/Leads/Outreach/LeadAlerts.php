<?php

declare(strict_types=1);

namespace App\Services\Leads\Outreach;

use App\Events\Leads\LeadReplyAlert;
use App\Mail\LeadReplyMail;
use App\Models\Lead;
use App\Models\LeadProfile;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells people. Two channels, both best-effort: a live push to the browser (Reverb) and an email.
 * A failure to notify is logged and never breaks the work that triggered it. Recipients are the lead's
 * owner (the person whose mailbox is used) and the profile's creator; if neither is known, everyone who
 * can manage leads.
 */
final class LeadAlerts
{
    public function replyArrived(Lead $lead, string $snippet): void
    {
        $name = $lead->name ?: 'A lead';
        $this->notify($lead, 'reply', "{$name} replied to your outreach", $snippet);
    }

    public function needsAttention(Lead $lead, string $why, string $snippet = ''): void
    {
        $name = $lead->name ?: 'A lead';
        $this->notify($lead, 'needs_attention', "{$name} needs your attention: {$why}", $snippet);
    }

    public function approvalWaiting(Lead $lead, string $what): void
    {
        $this->notify($lead, 'approval', "{$what} for ".($lead->name ?: 'a lead').' is waiting for your approval', '', false);
    }

    /** One alert per outreach run that left drafts waiting, so a manual-mode owner knows without opening the page. */
    public function draftsWaiting(LeadProfile $profile, int $count): void
    {
        $account = $profile->senderAccount;
        $ids = array_unique(array_filter([$account?->user_id, $profile->created_by]));
        $headline = $count === 1 ? '1 email is waiting for your approval' : "{$count} emails are waiting for your approval";
        $url = route('lead-approvals.index');

        foreach (User::query()->whereIn('id', $ids)->where('is_active', true)->get() as $user) {
            try {
                LeadReplyAlert::dispatch($user->id, 0, '', (string) $profile->name, 'approval', $headline, $url);
            } catch (\Throwable $e) {
                Log::warning('Lead alert: live push failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function notify(Lead $lead, string $kind, string $headline, string $snippet, bool $email = true): void
    {
        $url = route('leads.show', $lead);

        foreach ($this->recipients($lead) as $user) {
            try {
                LeadReplyAlert::dispatch($user->id, $lead->id, (string) $lead->name, (string) $lead->company, $kind, $headline, $url);
            } catch (\Throwable $e) {
                Log::warning('Lead alert: live push failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }

            if (! $email || blank($user->email)) {
                continue;
            }

            try {
                Mail::to($user->email)->send(new LeadReplyMail($lead, $headline, mb_substr($snippet, 0, 400), $url));
            } catch (\Throwable $e) {
                Log::warning('Lead alert: email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function recipients(Lead $lead)
    {
        $ids = array_filter([$lead->owner_user_id, $lead->profile?->created_by]);

        if ($ids !== []) {
            return User::query()->whereIn('id', array_unique($ids))->where('is_active', true)->get();
        }

        return User::permission('leads.manage')->where('is_active', true)->get();
    }
}
