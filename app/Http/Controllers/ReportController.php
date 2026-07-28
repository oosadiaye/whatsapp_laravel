<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CallLog;
use App\Models\EmailAccount;
use App\Models\EmailCampaign;
use App\Models\EmailSequence;
use App\Models\Setting;
use App\Models\User;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(): View
    {
        $callStats = [
            'total' => CallLog::count(),
            'today' => CallLog::whereDate('created_at', today())->count(),
            'missed' => CallLog::where('status', CallLog::STATUS_MISSED)->count(),
            'total_cost' => CallLog::sum('cost_estimate_kobo'),
            'total_duration' => CallLog::sum('duration_seconds'),
        ];

        $emailStats = [
            'accounts' => EmailAccount::count(),
            'active_accounts' => EmailAccount::where('is_active', true)->count(),
            'campaigns' => EmailCampaign::count(),
            'sequences' => EmailSequence::count(),
        ];

        $agentStats = [
            'total' => User::where('role', User::ROLE_AGENT)->count(),
            'online' => User::where('role', User::ROLE_AGENT)
                ->where('presence_status', User::PRESENCE_AVAILABLE)
                ->where('last_seen_at', '>=', now()->subMinutes(5))
                ->count(),
        ];

        $recentAudits = AuditLog::query()
            ->with('user')
            ->latest()
            ->limit(20)
            ->get();

        $atHealth = $this->checkAtHealth();

        return view('reports.index', compact('callStats', 'emailStats', 'agentStats', 'recentAudits', 'atHealth'));
    }

    private function checkAtHealth(): array
    {
        $username = Setting::get('africastalking_username');
        $apiKey = Setting::getEncrypted('africastalking_api_key');
        $virtualNumber = Setting::get('africastalking_virtual_number');

        $configured = filled($username) && filled($apiKey) && filled($virtualNumber);

        $recentSuccess = CallLog::where('provider', CallLog::PROVIDER_AFRICAS_TALKING)
            ->where('created_at', '>=', now()->subDay())
            ->where('status', CallLog::STATUS_ENDED)
            ->exists();

        $recentFailure = CallLog::where('provider', CallLog::PROVIDER_AFRICAS_TALKING)
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('status', [CallLog::STATUS_FAILED, CallLog::STATUS_MISSED])
            ->exists();

        return [
            'configured' => $configured,
            'username' => $username,
            'virtual_number' => $virtualNumber,
            'recent_success' => $recentSuccess,
            'recent_failure' => $recentFailure,
            'balance' => null,
        ];
    }
}
