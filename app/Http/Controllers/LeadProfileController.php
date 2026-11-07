<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LeadProfileRequest;
use App\Jobs\QualifyLeadProfileJob;
use App\Models\EmailAccount;
use App\Models\LeadProfile;
use App\Services\Leads\LeadPuller;
use App\Services\Leads\LeadRevealer;
use App\Support\AiConfig;
use App\Services\Leads\LeadQuery;
use App\Services\Leads\LeadQueryBuilder;
use App\Services\Leads\LeadQueryException;
use App\Services\Leads\LeadSourceRegistry;
use App\Support\LeadConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LeadProfileController extends Controller
{
    public function index(): View
    {
        return view('lead-profiles.index', [
            'profiles' => LeadProfile::query()->withCount('leads')->orderByDesc('id')->get(),
            'killSwitch' => LeadConfig::killSwitchOn(),
        ]);
    }

    public function create(LeadSourceRegistry $registry): View
    {
        return view('lead-profiles.create', ['sourceKeys' => $registry->keys(), 'mailboxes' => $this->mailboxes()]);
    }

    public function store(LeadProfileRequest $request, LeadQueryBuilder $builder): RedirectResponse
    {
        $profile = LeadProfile::create([
            'name' => $request->input('name'),
            'brief' => $request->input('brief'),
            'sources' => array_values($request->input('sources')),
            'daily_pull_cap' => (int) $request->input('daily_pull_cap'),
            'hard_filters' => $request->hardFilters(),
            ...$request->qualification(),
            ...$request->outreach(),
            'is_active' => false,
            'created_by' => auth()->id(),
        ]);

        $failure = $this->rebuild($profile, $builder);

        if ($failure !== null) {
            return redirect()->route('lead-profiles.edit', $profile)
                ->with('warning', 'Profile saved, but the search could not be generated: '.$failure);
        }

        return redirect()->route('lead-profiles.edit', $profile)
            ->with('success', 'Profile created. Review the generated search below, then switch the profile on.');
    }

    public function edit(LeadProfile $leadProfile, LeadSourceRegistry $registry): View
    {
        return view('lead-profiles.edit', [
            'profile' => $leadProfile,
            'sourceKeys' => $registry->keys(),
            'mailboxes' => $this->mailboxes($leadProfile),
            'query' => $leadProfile->hasQuery() ? LeadQuery::fromArray($leadProfile->lead_query) : null,
            'creditsUsed' => app(LeadRevealer::class)->creditsUsedThisMonth($leadProfile),
            'pipeline' => $leadProfile->leads()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function update(LeadProfileRequest $request, LeadProfile $leadProfile, LeadQueryBuilder $builder): RedirectResponse
    {
        $hardFilters = $request->hardFilters();
        $searchInputsChanged = $leadProfile->brief !== $request->input('brief')
            || $this->normaliseFilters($leadProfile->hard_filters ?? []) !== $this->normaliseFilters($hardFilters);

        $leadProfile->fill([
            'name' => $request->input('name'),
            'brief' => $request->input('brief'),
            'sources' => array_values($request->input('sources')),
            'daily_pull_cap' => (int) $request->input('daily_pull_cap'),
            'hard_filters' => $hardFilters,
            ...$request->qualification(),
            ...$request->outreach(),
        ])->save();

        $failure = ($searchInputsChanged || ! $leadProfile->hasQuery())
            ? $this->rebuild($leadProfile, $builder)
            : null;

        if ($failure !== null && $searchInputsChanged) {
            // The old search no longer matches the brief/filters: never keep pulling with it.
            $leadProfile->update(['lead_query' => null, 'pull_state' => null, 'is_active' => false]);

            return back()->with('warning', 'Saved, but the profile was switched off because its search could not be regenerated: '.$failure);
        }

        $leadProfile->refresh();
        $wantsActive = $request->boolean('is_active');
        $leadProfile->update(['is_active' => $wantsActive && $leadProfile->hasQuery()]);

        if ($failure !== null) {
            return back()->with('warning', 'Saved, but the search could not be regenerated: '.$failure);
        }

        if ($wantsActive && ! $leadProfile->is_active) {
            return back()->with('warning', 'Saved, but the profile cannot be switched on until it has a generated search.');
        }

        return back()->with('success', 'Lead profile saved.');
    }

    /**
     * Mailboxes this person may choose: their own active ones, plus the one the profile already uses.
     *
     * @return \Illuminate\Support\Collection<int, EmailAccount>
     */
    private function mailboxes(?LeadProfile $profile = null)
    {
        return EmailAccount::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('user_id', auth()->id())
                ->when($profile?->sender_email_account_id, fn ($q2, $id) => $q2->orWhere('id', $id)))
            ->orderBy('email')
            ->get();
    }

    public function rebuildQuery(LeadProfile $leadProfile, LeadQueryBuilder $builder): RedirectResponse
    {
        $failure = $this->rebuild($leadProfile, $builder);

        return $failure === null
            ? back()->with('success', 'Search regenerated from the brief.')
            : back()->with('error', $failure);
    }

    public function pullNow(LeadProfile $leadProfile, LeadPuller $puller): RedirectResponse
    {
        if (LeadConfig::killSwitchOn()) {
            return back()->with('warning', 'The lead automation kill switch is on; nothing will be pulled.');
        }

        if (! $leadProfile->is_active || ! $leadProfile->hasQuery()) {
            return back()->with('warning', 'Switch the profile on (it needs a generated search) before pulling.');
        }

        $result = $puller->pull($leadProfile);

        if ($result['errors'] !== []) {
            return back()->with('error', implode(' ', $result['errors']));
        }

        return back()->with('success', "Pulled {$result['created']} new lead(s); {$result['skipped']} already known.");
    }

    public function qualifyNow(LeadProfile $leadProfile): RedirectResponse
    {
        if (LeadConfig::killSwitchOn()) {
            return back()->with('warning', 'The lead automation kill switch is on; nothing will be qualified.');
        }

        if (! $leadProfile->is_active || ! $leadProfile->hasQuery()) {
            return back()->with('warning', 'Switch the profile on (it needs a generated search) before qualifying.');
        }

        if (! AiConfig::isConfigured()) {
            return back()->with('warning', 'No AI provider is configured. Add a key in Settings first.');
        }

        QualifyLeadProfileJob::dispatch($leadProfile->id);

        return back()->with('success', 'Qualification queued. New results appear under Leads within a few minutes.');
    }

    public function resetPull(LeadProfile $leadProfile): RedirectResponse
    {
        $leadProfile->update(['pull_state' => null, 'last_pull_error' => null]);

        return back()->with('success', 'Pull position reset; the next pull starts from the first page.');
    }

    public function destroy(LeadProfile $leadProfile): RedirectResponse
    {
        $leadProfile->delete();

        return redirect()->route('lead-profiles.index')->with('success', 'Lead profile deleted. Leads it already pulled were kept.');
    }

    /**
     * Canonical string form of the hard filters: keys and list items sorted, so only a
     * real change (not a reordering) counts as one.
     *
     * @param  array<string, mixed>  $filters
     */
    private function normaliseFilters(array $filters): string
    {
        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                $filters[$key] = array_values($value);
                sort($filters[$key], SORT_STRING | SORT_FLAG_CASE);
            }
        }
        ksort($filters);

        return (string) json_encode($filters);
    }

    /**
     * Regenerate and store the search. Returns the failure message, or null on success.
     * A new query invalidates the old cursor, so the pull restarts from page one.
     */
    private function rebuild(LeadProfile $profile, LeadQueryBuilder $builder): ?string
    {
        try {
            $query = $builder->build($profile->brief, $profile->hard_filters ?? []);
        } catch (LeadQueryException $e) {
            return $e->getMessage();
        }

        $profile->update([
            'lead_query' => $query->toArray(),
            'pull_state' => null,
            'last_pull_error' => null,
        ]);

        return null;
    }
}
