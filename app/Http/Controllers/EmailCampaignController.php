<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmailCampaignRequest;
use App\Models\ContactGroup;
use App\Models\EmailCampaign;
use App\Services\EmailCampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Bulk email campaigns to contacts (prospects). Single-tenant: every permitted
 * user sees every campaign; the route permissions (email.*) are the gate.
 */
class EmailCampaignController extends Controller
{
    public function __construct(private readonly EmailCampaignService $service)
    {
    }

    public function index(): View
    {
        return view('email-campaigns.index', [
            'campaigns' => EmailCampaign::latest()->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('email-campaigns.create', ['groups' => ContactGroup::all()]);
    }

    public function store(StoreEmailCampaignRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $campaign = EmailCampaign::create([
            'user_id' => auth()->id(),
            'name' => $data['name'],
            'subject' => $data['subject'],
            'from_name' => $data['from_name'] ?? null,
            'reply_to' => $data['reply_to'] ?? null,
            'body_html' => $data['body_html'],
            'rate_per_minute' => $data['rate_per_minute'] ?? 60,
            'recurrence' => $data['recurrence'] ?? EmailCampaign::RECURRENCE_NONE,
            'recurrence_until' => $data['recurrence_until'] ?? null,
            'status' => EmailCampaign::STATUS_DRAFT,
        ]);
        $campaign->contactGroups()->attach($data['groups']);

        return $this->applyAction($campaign, $data['action'] ?? 'draft', $data['scheduled_at'] ?? null);
    }

    public function show(string $id): View
    {
        $campaign = EmailCampaign::with('contactGroups')->findOrFail($id);

        return view('email-campaigns.show', [
            'campaign' => $campaign,
            'recipientCount' => $this->service->recipients($campaign)->count(),
        ]);
    }

    public function edit(string $id): View
    {
        $campaign = EmailCampaign::with('contactGroups')->findOrFail($id);

        abort_unless(
            in_array($campaign->status, EmailCampaign::EDITABLE_STATUSES, true),
            403,
            "Cannot edit a campaign that is {$campaign->status}.",
        );

        return view('email-campaigns.edit', [
            'campaign' => $campaign,
            'groups' => ContactGroup::all(),
        ]);
    }

    public function update(StoreEmailCampaignRequest $request, string $id): RedirectResponse
    {
        $campaign = EmailCampaign::findOrFail($id);

        abort_unless(
            in_array($campaign->status, EmailCampaign::EDITABLE_STATUSES, true),
            403,
            "Cannot update a campaign that is {$campaign->status}.",
        );

        $data = $request->validated();
        $campaign->update([
            'name' => $data['name'],
            'subject' => $data['subject'],
            'from_name' => $data['from_name'] ?? null,
            'reply_to' => $data['reply_to'] ?? null,
            'body_html' => $data['body_html'],
            'rate_per_minute' => $data['rate_per_minute'] ?? 60,
            'recurrence' => $data['recurrence'] ?? EmailCampaign::RECURRENCE_NONE,
            'recurrence_until' => $data['recurrence_until'] ?? null,
        ]);
        $campaign->contactGroups()->sync($data['groups']);

        return $this->applyAction($campaign, $data['action'] ?? 'draft', $data['scheduled_at'] ?? null);
    }

    public function launch(string $id): RedirectResponse
    {
        $campaign = EmailCampaign::findOrFail($id);

        abort_unless(
            in_array($campaign->status, [EmailCampaign::STATUS_DRAFT, EmailCampaign::STATUS_SCHEDULED, EmailCampaign::STATUS_PAUSED], true),
            403,
            "Cannot send a campaign that is {$campaign->status}.",
        );

        $this->service->launch($campaign);

        return redirect()->route('email-campaigns.show', $campaign)
            ->with('success', 'Email campaign is sending.')
            ->with('warning', $this->mailTransportWarning());
    }

    public function pause(string $id): RedirectResponse
    {
        $campaign = EmailCampaign::findOrFail($id);

        abort_unless(
            $campaign->status === EmailCampaign::STATUS_SCHEDULED,
            403,
            'Only a scheduled campaign can be paused.',
        );

        $campaign->update(['status' => EmailCampaign::STATUS_PAUSED]);

        return redirect()->route('email-campaigns.show', $campaign)->with('success', 'Email campaign paused.');
    }

    public function resume(string $id): RedirectResponse
    {
        $campaign = EmailCampaign::findOrFail($id);

        abort_unless(
            $campaign->status === EmailCampaign::STATUS_PAUSED,
            403,
            'Only a paused campaign can be resumed.',
        );

        // Back to scheduled; the cron picks it up (immediately if the time has
        // already passed).
        $campaign->update(['status' => EmailCampaign::STATUS_SCHEDULED]);

        return redirect()->route('email-campaigns.show', $campaign)->with('success', 'Email campaign resumed.');
    }

    public function cancel(string $id): RedirectResponse
    {
        $campaign = EmailCampaign::findOrFail($id);
        $campaign->update(['status' => EmailCampaign::STATUS_CANCELLED]);

        return redirect()->route('email-campaigns.show', $campaign)->with('success', 'Email campaign cancelled.');
    }

    public function destroy(string $id): RedirectResponse
    {
        EmailCampaign::findOrFail($id)->delete();

        return redirect()->route('email-campaigns.index')->with('success', 'Email campaign deleted.');
    }

    /**
     * Resolve the create/update form's action button into a status transition.
     */
    private function applyAction(EmailCampaign $campaign, string $action, ?string $scheduledAt): RedirectResponse
    {
        if ($action === 'send') {
            $this->service->launch($campaign);

            return redirect()->route('email-campaigns.show', $campaign)
                ->with('success', 'Email campaign is sending.')
                ->with('warning', $this->mailTransportWarning());
        }

        if ($action === 'schedule' && $scheduledAt !== null) {
            $campaign->update(['status' => EmailCampaign::STATUS_SCHEDULED, 'scheduled_at' => $scheduledAt]);

            return redirect()->route('email-campaigns.show', $campaign)
                ->with('success', 'Email campaign scheduled for '.$campaign->scheduled_at->format('M j, Y g:i A').'.');
        }

        return redirect()->route('email-campaigns.show', $campaign)->with('success', 'Email campaign saved as draft.');
    }

    /**
     * A warning when the configured mail transport won't actually deliver mail
     * (audit M11): `log` writes to the log file and `array` discards, so a
     * campaign reports SENT while nothing arrives. Returns null for a real
     * transport (the warning flash is skipped when null).
     */
    private function mailTransportWarning(): ?string
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array', ''], true)) {
            return "Emails are NOT being delivered: MAIL_MAILER is \"{$mailer}\". "
                .'Configure a real mail transport (SMTP/SES/etc.) for messages to actually send.';
        }

        if ($mailer === 'smtp' && blank(config('mail.mailers.smtp.host'))) {
            return 'Emails may not be delivered: the SMTP transport has no host configured. Check your MAIL_* settings.';
        }

        return null;
    }
}
