<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\MessageLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $userId = auth()->id();

        $totalCampaigns = Campaign::where('user_id', $userId)->count();
        $totalContacts = Contact::where('user_id', $userId)->count();

        $messagesToday = MessageLog::whereHas('campaign', fn ($q) => $q->where('user_id', $userId))
            ->whereDate('sent_at', Carbon::today())
            ->count();

        $deliveryRate = Campaign::where('user_id', $userId)
            ->where('sent_count', '>', 0)
            ->avg(DB::raw('(delivered_count / sent_count) * 100')) ?? 0;

        $recentCampaigns = Campaign::where('user_id', $userId)
            ->latest()
            ->limit(5)
            ->get();

        $messagesPerDay = MessageLog::whereHas('campaign', fn ($q) => $q->where('user_id', $userId))
            ->where('sent_at', '>=', Carbon::now()->subDays(30))
            ->select(DB::raw('DATE(sent_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $statusBreakdown = MessageLog::whereHas('campaign', fn ($q) => $q->where('user_id', $userId))
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
