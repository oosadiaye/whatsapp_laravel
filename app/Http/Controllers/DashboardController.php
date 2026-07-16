<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
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
     */
    public function index(): View
    {
        $totalCampaigns = Campaign::count();
        $totalContacts = Contact::count();

        // "Today" follows the business timezone, not UTC — otherwise messages in
        // the first hour(s) of the local day fall into the wrong bucket. sent_at
        // is stored UTC, so compute the local day-start and convert back to UTC.
        $startOfToday = Carbon::now(config('app.business_timezone'))->startOfDay()->utc();
        $messagesToday = MessageLog::where('sent_at', '>=', $startOfToday)->count();

        $deliveryRate = Campaign::query()
            ->where('sent_count', '>', 0)
            ->avg(DB::raw('(delivered_count / sent_count) * 100')) ?? 0;

        $recentCampaigns = Campaign::latest()->limit(5)->get();

        $messagesPerDay = MessageLog::query()
            ->where('sent_at', '>=', Carbon::now()->subDays(30))
            ->select(DB::raw('DATE(sent_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $statusBreakdown = MessageLog::query()
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        return view('dashboard', [
            'totalCampaigns' => $totalCampaigns,
            'totalContacts' => $totalContacts,
            'messagesToday' => $messagesToday,
            'deliveryRate' => round((float) $deliveryRate, 1),
            'recentCampaigns' => $recentCampaigns,
            'messagesPerDay' => $messagesPerDay,
            'statusBreakdown' => $statusBreakdown,
        ]);
    }
}
