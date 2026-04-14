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
            'templates' => MessageTemplate::where('user_id', $userId)->get(),
        ]);
    }

    public function store(StoreCampaignRequest $request): RedirectResponse
    {
        $data = [
            'user_id' => auth()->id(),
            'name' => $request->validated('name'),
            'message' => $request->validated('message'),
            'instance_id' => $request->validated('instance_id'),
            'rate_per_minute' => $request->validated('rate_per_minute', 10),
            'delay_min' => $request->validated('delay_min', 3),
            'delay_max' => $request->validated('delay_max', 10),
            'scheduled_at' => $request->validated('scheduled_at'),
            'status' => 'DRAFT',
        ];

        if ($request->hasFile('media')) {
            $data['media_path'] = $request->file('media')->store('campaigns');
            $data['media_type'] = $this->resolveMediaType(
                $request->file('media')->getClientOriginalExtension(),
            );
        }

        $campaign = Campaign::create($data);
        $campaign->contactGroups()->attach($request->validated('groups'));

        return redirect()
            ->route('campaigns.show', $campaign)
            ->with('success', 'Campaign created successfully.');
    }

    public function show(string $id): View
    {
        $campaign = Campaign::where('user_id', auth()->id())
            ->with(['contactGroups', 'whatsAppInstance'])
            ->findOrFail($id);

        return view('campaigns.show', ['campaign' => $campaign]);
    }

    public function edit(string $id): View
    {
        $userId = auth()->id();
        $campaign = Campaign::where('user_id', $userId)
            ->with('contactGroups')
            ->findOrFail($id);

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

        $campaign->update([
            'name' => $request->validated('name'),
            'message' => $request->validated('message'),
            'instance_id' => $request->validated('instance_id'),
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
        $this->campaignService->cancel($campaign);

        return redirect()->back()->with('success', 'Campaign cancelled.');
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
