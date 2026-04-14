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
