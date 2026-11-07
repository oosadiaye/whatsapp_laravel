<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadProfile;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeadController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), Lead::STATUSES, true) ? (string) $request->query('status') : null;
        $profileId = $request->integer('profile') ?: null;

        $leads = Lead::query()
            ->with('profile:id,name')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($profileId, fn ($query) => $query->where('lead_profile_id', $profileId))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('leads.index', [
            'leads' => $leads,
            'status' => $status,
            'profileId' => $profileId,
            'statuses' => Lead::STATUSES,
            'profiles' => LeadProfile::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Lead $lead): View
    {
        $replies = $lead->replies()->with('message')->orderByDesc('id')->get();
        $view = view('leads.show', [
            'lead' => $lead->load('profile:id,name'),
            'events' => $lead->events()->orderByDesc('id')->limit(100)->get(),
            'messages' => $lead->messages()->orderBy('id')->get(),
            'replies' => $replies,
        ]);

        // Opening the lead is how a person "sees" its replies: the sidebar badge counts the unseen ones.
        $lead->replies()->whereNull('seen_at')->update(['seen_at' => now()]);

        return $view;
    }
}
