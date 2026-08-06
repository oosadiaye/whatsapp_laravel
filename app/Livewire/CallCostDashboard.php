<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallLog;
use Livewire\Component;

class CallCostDashboard extends Component
{
    public string $period = 'today';

    public function render()
    {
        $query = CallLog::query()->whereNotNull('cost_estimate_kobo');

        $now = now();
        $periodStart = match ($this->period) {
            'today' => $now->copy()->startOfDay(),
            'week' => $now->copy()->startOfWeek(),
            'month' => $now->copy()->startOfMonth(),
            default => $now->copy()->startOfDay(),
        };

        $query->where('created_at', '>=', $periodStart);

        $totalCalls = (clone $query)->count();
        $totalCost = (clone $query)->sum('cost_estimate_kobo');
        $totalDuration = (clone $query)->sum('duration_seconds');
        // Average over BILLED calls (cost > 0) only — rows that never connected
        // have no charged duration and would otherwise dilute the per-call figure.
        $costedCalls = (clone $query)->where('cost_estimate_kobo', '>', 0)->count();
        $avgCost = $costedCalls > 0 ? $totalCost / $costedCalls : 0;

        $recent = (clone $query)
            ->with('contact')
            ->latest()
            ->limit(20)
            ->get();

        return view('livewire.call-cost-dashboard', [
            'totalCalls' => $totalCalls,
            'totalCost' => $totalCost,
            'totalDuration' => $totalDuration,
            'avgCost' => (int) round($avgCost),
            'recentCalls' => $recent,
        ]);
    }
}
