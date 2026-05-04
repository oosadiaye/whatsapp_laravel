<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCampaignRequest;
use App\Models\Campaign;
use App\Models\ContactGroup;
use App\Models\MessageTemplate;
use App\Models\WhatsAppInstance;
use App\Services\CampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaignService,
    ) {}

    public function index(): View
    {
        $campaigns = Campaign::where('user_id', auth()->id())
            ->latest()
            ->paginate(20);

        return view('campaigns.index', ['campaigns' => $campaigns]);
    }

    public function create(): View
    {
        $userId = auth()->id();

        return view('campaigns.create', [
            'groups' => ContactGroup::where('user_id', $userId)->get(),
            'instances' => WhatsAppInstance::where('user_id', $userId)->get(),
            // Only APPROVED remote templates are eligible for live campaign sends.
            // Local templates remain available to manually populate the message body.
            'templates' => MessageTemplate::where('user_id', $userId)
                ->whereIn('status', [MessageTemplate::STATUS_APPROVED, MessageTemplate::STATUS_LOCAL])
                ->orderBy('whatsapp_instance_id')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(StoreCampaignRequest $request): RedirectResponse
    {
        $data = [
            'user_id' => auth()->id(),
            'name' => $request->validated('name'),
            'message' => $request->validated('message'),
            'instance_id' => $request->validated('instance_id'),
            'message_template_id' => $request->validated('message_template_id'),
            'template_language' => $request->validated('template_language'),
            'header_media_url' => $this->storeHeaderMedia($request->file('header_media')),
            'rate_per_minute' => $request->validated('rate_per_minute', 10),
            'delay_min' => $request->validated('delay_min', 3),
            'delay_max' => $request->validated('delay_max', 10),
            'scheduled_at' => $request->validated('scheduled_at'),
            'status' => 'DRAFT',
        ];

        $campaign = Campaign::create($data);
        $campaign->contactGroups()->attach($request->validated('groups'));

        return redirect()
            ->route('campaigns.show', $campaign)
            ->with('success', 'Campaign created successfully.');
    }

    /**
     * Persist an uploaded header-media file on the public disk and return an
     * absolute URL Meta can fetch. Returns null when no file was uploaded —
     * caller is responsible for falling back to the campaign's existing URL
     * (UPDATE flow) or omitting the header parameter (TEXT-header templates).
     */
    private function storeHeaderMedia(?UploadedFile $file): ?string
    {
        if ($file === null) {
            return null;
        }

        // Stored under storage/app/public/campaign-headers/{hash.ext}
        // and exposed via /storage/campaign-headers/... thanks to artisan storage:link.
        $path = $file->store('campaign-headers', 'public');

        // The 'public' disk has 'throw' => false (config/filesystems.php), so
        // permission/IO failures return false instead of throwing. Surface that
        // explicitly with a helpful exception — silently returning null would
        // create a campaign without the required header URL, then Meta would
        // reject every send with error 132012 in the queue worker.
        if ($path === false) {
            \Illuminate\Support\Facades\Log::error('Failed to store campaign header media', [
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'storage_root' => storage_path('app/public/campaign-headers'),
                'hint' => 'Check directory exists and is writable by the web user. Run: chmod -R 775 storage && chown -R www-data:www-data storage',
            ]);
            throw new \RuntimeException(
                'Could not save header media. The web server may lack write permission on storage/app/public/. '
                .'Run: chmod -R 775 storage && chown -R www-data:www-data storage'
            );
        }

        // Storage::url() returns a path-relative URL (e.g. "/storage/campaign-headers/abc.jpg").
        // Meta requires an absolute URL it can fetch publicly, so wrap with url().
        return url(Storage::disk('public')->url($path));
    }

    public function show(string $id): View
    {
        $campaign = Campaign::where('user_id', auth()->id())
            ->with(['contactGroups', 'whatsAppInstance'])
            ->findOrFail($id);

        return view('campaigns.show', ['campaign' => $campaign]);
    }

    /**
     * Editable statuses. RUNNING is excluded because workers are actively
     * reading the campaign config to send messages — mid-flight edits would
     * race with sends and could ship inconsistent state to half the recipients.
     * COMPLETED / FAILED / CANCELLED are terminal — use Clone to make a new
     * campaign instead of mutating one that's already done.
     */
    private const EDITABLE_STATUSES = ['DRAFT', 'QUEUED', 'PAUSED'];

    /**
     * Deletable statuses. Everything except RUNNING — RUNNING has live worker
     * jobs in the queue that read this campaign's config to dispatch sends;
     * deleting the row mid-flight orphans those jobs and they 500 when they
     * try to refresh the model. Pause first, THEN delete.
     */
    private const DELETABLE_STATUSES = ['DRAFT', 'QUEUED', 'PAUSED', 'COMPLETED', 'FAILED', 'CANCELLED'];

    public function edit(string $id): View
    {
        $userId = auth()->id();
        $campaign = Campaign::where('user_id', $userId)
            ->with('contactGroups')
            ->findOrFail($id);

        // Defense in depth: views hide the Edit button for non-editable statuses,
        // but a direct URL navigation must also be rejected.
        abort_unless(
            in_array($campaign->status, self::EDITABLE_STATUSES, true),
            403,
            "Cannot edit a campaign with status \"{$campaign->status}\". Clone it to make a new editable copy.",
        );

        return view('campaigns.edit', [
            'campaign' => $campaign,
            'groups' => ContactGroup::where('user_id', $userId)->get(),
            'instances' => WhatsAppInstance::where('user_id', $userId)->get(),
            'templates' => MessageTemplate::where('user_id', $userId)->get(),
        ]);
    }

    public function update(StoreCampaignRequest $request, string $id): RedirectResponse
    {
        $campaign = Campaign::where('user_id', auth()->id())->findOrFail($id);

        // Mirror the edit() guard — accept updates only for editable statuses.
        // A user could otherwise POST directly to this endpoint while bypassing
        // the edit form (curl, scripted, replayed-after-launch).
        abort_unless(
            in_array($campaign->status, self::EDITABLE_STATUSES, true),
            403,
            "Cannot update a campaign with status \"{$campaign->status}\".",
        );

        // Keep existing header URL if no new file was uploaded — users editing
        // unrelated fields (e.g. groups, schedule) shouldn't lose their image.
        $newHeaderUrl = $this->storeHeaderMedia($request->file('header_media'))
            ?? $campaign->header_media_url;

        $campaign->update([
            'name' => $request->validated('name'),
            'message' => $request->validated('message'),
            'instance_id' => $request->validated('instance_id'),
            'message_template_id' => $request->validated('message_template_id'),
            'template_language' => $request->validated('template_language'),
            'header_media_url' => $newHeaderUrl,
            'rate_per_minute' => $request->validated('rate_per_minute', 10),
            'delay_min' => $request->validated('delay_min', 3),
            'delay_max' => $request->validated('delay_max', 10),
            'scheduled_at' => $request->validated('scheduled_at'),
        ]);

        $campaign->contactGroups()->sync($request->validated('groups'));

        return redirect()
            ->route('campaigns.show', $campaign)
            ->with('success', 'Campaign updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $campaign = Campaign::where('user_id', auth()->id())->findOrFail($id);

        // Block deletion of RUNNING campaigns — see DELETABLE_STATUSES doc.
        // The user must Pause or Cancel first, which both stop worker dispatch
        // before the delete is allowed.
        if (! in_array($campaign->status, self::DELETABLE_STATUSES, true)) {
            return redirect()
                ->route('campaigns.show', $campaign)
                ->with('error', "Cannot delete a {$campaign->status} campaign. Pause or Cancel it first.");
        }

        $campaign->delete();

        return redirect()
            ->route('campaigns.index')
            ->with('success', 'Campaign deleted successfully.');
    }

    public function launch(string $id): RedirectResponse
    {
        $campaign = Campaign::where('user_id', auth()->id())->findOrFail($id);
        $this->campaignService->launch($campaign);

        return redirect()
            ->route('campaigns.show', $campaign)
            ->with('success', 'Campaign launched successfully.');
    }

    public function pause(string $id): RedirectResponse
    {
        $campaign = Campaign::where('user_id', auth()->id())->findOrFail($id);
        $this->campaignService->pause($campaign);

        return redirect()->back()->with('success', 'Campaign paused.');
    }

    public function resume(string $id): RedirectResponse
    {
        $campaign = Campaign::where('user_id', auth()->id())->findOrFail($id);
        $this->campaignService->resume($campaign);

        return redirect()->back()->with('success', 'Campaign resumed.');
    }

    public function cancel(string $id): RedirectResponse
    {
        $campaign = Campaign::where('user_id', auth()->id())->findOrFail($id);
        $cancelledLogs = $this->campaignService->cancel($campaign);

        $message = $cancelledLogs > 0
            ? "Campaign cancelled. {$cancelledLogs} pending sends aborted."
            : 'Campaign cancelled.';

        return redirect()->back()->with('success', $message);
    }

    /**
     * Bulk-cancel every QUEUED or RUNNING campaign owned by the current user.
     * Useful as a "panic button" when the queue worker has been down and a
     * backlog has accumulated. Each campaign goes through the same cancel()
     * service flow (status → CANCELLED, pending logs aborted, queue jobs
     * best-effort cleaned up) so the cleanup is consistent.
     */
    public function clearQueue(): RedirectResponse
    {
        $stuck = Campaign::where('user_id', auth()->id())
            ->whereIn('status', ['QUEUED', 'RUNNING'])
            ->get();

        $totalLogs = 0;
        foreach ($stuck as $campaign) {
            $totalLogs += $this->campaignService->cancel($campaign);
        }

        $count = $stuck->count();
        $message = $count === 0
            ? 'No queued or running campaigns to clear.'
            : "Cleared {$count} campaign(s). {$totalLogs} pending sends aborted.";

        return redirect()->route('campaigns.index')->with('success', $message);
    }

    public function clone(string $id): RedirectResponse
    {
        $campaign = Campaign::where('user_id', auth()->id())->findOrFail($id);
        $cloned = $this->campaignService->clone($campaign);

        return redirect()
            ->route('campaigns.edit', $cloned)
            ->with('success', 'Campaign cloned as draft.');
    }

    public function exportLogs(string $id): StreamedResponse
    {
        $campaign = Campaign::where('user_id', auth()->id())->findOrFail($id);

        $logs = $campaign->messageLogs()
            ->with('contact')
            ->get();

        $filename = 'campaign_' . $campaign->id . '_logs_' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($logs) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['phone', 'contact_name', 'status', 'sent_at', 'delivered_at', 'read_at', 'error_message']);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->phone,
                    $log->contact?->name ?? '',
                    $log->status,
                    $log->sent_at?->toDateTimeString(),
                    $log->delivered_at?->toDateTimeString(),
                    $log->read_at?->toDateTimeString(),
                    $log->error_message,
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function resolveMediaType(string $extension): string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg', 'png', 'gif' => 'image',
            'pdf' => 'document',
            'mp3', 'ogg' => 'audio',
            default => 'document',
        };
    }
}
