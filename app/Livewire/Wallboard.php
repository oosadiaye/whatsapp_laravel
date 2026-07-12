<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallLog;
use App\Models\User;
use Livewire\Component;

/**
 * Live operations wallboard: what's happening on the phones right now plus
 * today's headline numbers. Read-only; polls every few seconds (see the view's
 * wire:poll) — the same realtime approach as TeamLoad, reusing call data the
 * app already collects (statuses, durations, MOS) rather than new plumbing.
 *
 * Gated by permission:team.view at the route, so it's a manager/admin surface.
 */
class Wallboard extends Component
{
    public function render()
    {
        $liveCalls = CallLog::query()
            ->whereIn('status', CallLog::STATUSES_IN_FLIGHT)
            ->with(['contact', 'placedBy'])
            ->orderBy('started_at')
            ->get();

        // Which agents are on a live call right now (outbound placer).
        $onCallUserIds = $liveCalls->pluck('placed_by_user_id')->filter()->unique()->values()->all();

        $today = CallLog::query()
            ->whereDate('created_at', today())
            ->get(['status', 'direction', 'duration_seconds', 'connected_at', 'quality_metrics']);

        $answered = $today->whereNotNull('connected_at')->count();
        $missed = $today->where('status', CallLog::STATUS_MISSED)->count();
        $decisive = $answered + $missed; // calls that either connected or were missed

        $talkTimes = $today->where('duration_seconds', '>', 0)->pluck('duration_seconds');
        $mos = $today
            ->map(fn (CallLog $c) => $c->quality_metrics['mos'] ?? null)
            ->filter()
            ->values();

        $stats = [
            'live' => $liveCalls->count(),
            'today' => $today->count(),
            'answered' => $answered,
            'missed' => $missed,
            // Answer-seizure ratio: answered / (answered + missed).
            'answer_rate' => $decisive > 0 ? (int) round($answered / $decisive * 100) : null,
            'avg_talk_seconds' => $talkTimes->isNotEmpty() ? (int) round($talkTimes->avg()) : 0,
            'avg_mos' => $mos->isNotEmpty() ? round((float) $mos->avg(), 1) : null,
        ];

        $agents = User::query()
            ->where('role', User::ROLE_AGENT)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'presence_status', 'last_seen_at']);

        return view('livewire.wallboard', [
            'liveCalls' => $liveCalls,
            'onCallUserIds' => $onCallUserIds,
            'stats' => $stats,
            'agents' => $agents,
        ]);
    }
}
