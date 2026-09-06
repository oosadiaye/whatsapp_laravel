<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\MessageLog;
use Livewire\Component;
use Livewire\WithPagination;

class MessageLogsTable extends Component
{
    use WithPagination;

    public int $campaignId;

    public string $filterStatus = 'all';

    public string $search = '';

    public int $perPage = 20;

    protected $queryString = ['filterStatus', 'search'];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        // Re-authorize on every render — Livewire updates bypass the route
        // permission gate; without this a revoked agent's open tab keeps polling
        // per-recipient phone/status data (see TeamLoad / the documented invariant).
        abort_unless((bool) auth()->user()?->can('campaigns.view'), 403);

        $query = MessageLog::where('campaign_id', $this->campaignId)
            ->with('contact');

        if ($this->filterStatus !== 'all') {
            $query->where('status', strtoupper($this->filterStatus));
        }

        if ($this->search) {
            $query->where('phone', 'like', "%{$this->search}%");
        }

        $logs = $query->latest()->paginate($this->perPage);

        return view('livewire.message-logs-table', compact('logs'));
    }
}
