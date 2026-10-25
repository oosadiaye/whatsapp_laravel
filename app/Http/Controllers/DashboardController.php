<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CallLog;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MessageLog;
use App\Models\User;
use App\Services\RoundRobinAssigner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** In-flight calls older than this are stale rows (no terminate webhook ever arrived). */
    private const LIVE_CALL_FRESHNESS_MINUTES = 30;

    /**
     * Stats are ACCOUNT-WIDE (single-tenant). Every staff member with the
     * `dashboard.view` permission sees the same numbers — total contacts
     * across the company, total campaigns sent by anyone, today's message
     * volume, etc. The previous per-user filter meant a new admin saw
     * "0 campaigns / 0 contacts" on first login even though the company
     * already had thousands of records.
     *
     * The user_id column on each row is preserved as audit metadata, but
     * is no longer used to filter dashboard aggregates.
     *
     * The call, inbox and team panels are additionally gated by the same
     * permissions as their own pages, so the dashboard never shows someone
     * data they could not open elsewhere.
     */
    public function index(RoundRobinAssigner $assigner): View
    {
        $user = auth()->user();

        $totalCampaigns = Campaign::count();
        $totalContacts = Contact::count();

        // "Today" follows the business timezone, not UTC — otherwise messages in
        // the first hour(s) of the local day fall into the wrong bucket. sent_at
        // is stored UTC, so compute the local day-start and convert back to UTC.
        $startOfToday = Carbon::now(config('app.business_timezone'))->startOfDay()->utc();
        $startOfYesterday = $startOfToday->copy()->subDay();
        $messagesToday = MessageLog::where('sent_at', '>=', $startOfToday)->count();
        $messagesYesterday = MessageLog::where('sent_at', '>=', $startOfYesterday)
            ->where('sent_at', '<', $startOfToday)
            ->count();

        $deliveryRate = Campaign::query()
            ->where('sent_count', '>', 0)
            ->avg(DB::raw('(delivered_count / sent_count) * 100')) ?? 0;

        $recentCampaigns = Campaign::latest()->limit(5)->get();
        $sendingCampaigns = Campaign::whereIn('status', ['RUNNING', 'QUEUED'])->count();

        $messagesPerDay = MessageLog::query()
            ->where('sent_at', '>=', Carbon::now()->subDays(30))
            ->select(DB::raw('DATE(sent_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Array-of-objects shape ([{status, count}, …]) to match the doughnut
        // chart JS (which maps over item.status/item.count) and the sibling
        // messagesPerDay series. A keyed pluck() would json_encode to an object
        // and break the chart's .map() once any status rows exist.
        $statusBreakdown = MessageLog::query()
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        $canSeeCalls = (bool) $user?->can('calls.view');
        $canSeeInbox = (bool) $user?->can('conversations.view_all');

        $liveCalls = $canSeeCalls
            ? CallLog::query()
                ->whereIn('status', CallLog::STATUSES_IN_FLIGHT)
                ->where('created_at', '>=', now()->subMinutes(self::LIVE_CALL_FRESHNESS_MINUTES))
                ->with(['contact', 'placedBy'])
                ->orderBy('started_at')
                ->limit(4)
                ->get()
            : collect();

        $missedCalls = $canSeeCalls
            ? CallLog::where('status', CallLog::STATUS_MISSED)->where('created_at', '>=', now()->subDay())->count()
            : 0;

        $unassignedChats = $canSeeInbox
            ? Conversation::whereNull('assigned_to_user_id')->where('unread_count', '>', 0)->count()
            : 0;

        $failedToday = MessageLog::where('status', 'FAILED')->where('sent_at', '>=', $startOfToday)->count();

        $team = $user?->can('team.view')
            ? User::callStaff()
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(6)
                ->get(['id', 'name', 'role', 'presence_status', 'last_seen_at'])
                ->map(fn (User $member) => [
                    'name' => $member->name,
                    'online' => $assigner->isAvailable($member),
                    'presence' => $member->presence_status,
                ])
            : collect();

        return view('dashboard', [
            'totalCampaigns' => $totalCampaigns,
            'totalContacts' => $totalContacts,
            'messagesToday' => $messagesToday,
            'messagesYesterday' => $messagesYesterday,
            'deliveryRate' => round((float) $deliveryRate, 1),
            'recentCampaigns' => $recentCampaigns,
            'sendingCampaigns' => $sendingCampaigns,
            'messagesPerDay' => $messagesPerDay,
            'statusBreakdown' => $statusBreakdown,
            'canSeeCalls' => $canSeeCalls,
            'liveCalls' => $liveCalls,
            'missedCalls' => $missedCalls,
            'unassignedChats' => $unassignedChats,
            'failedToday' => $failedToday,
            'team' => $team,
        ]);
    }
}
