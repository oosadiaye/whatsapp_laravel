<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Campaign;
use Livewire\Component;

class CampaignStatus extends Component
{
    public int $campaignId;

    public function mount(int $campaignId): void
    {
        $this->campaignId = $campaignId;
    }

    public function render()
    {
        $campaign = Campaign::find($this->campaignId);

        return view('livewire.campaign-status', [
            'sent' => $campaign?->sent_count ?? 0,
            'delivered' => $campaign?->delivered_count ?? 0,
            'read' => $campaign?->read_count ?? 0,
            'failed' => $campaign?->failed_count ?? 0,
            'total' => $campaign?->total_contacts ?? 0,
            'status' => $campaign?->status ?? 'DRAFT',
            'deliveryRate' => $campaign?->delivery_rate ?? 0,
            'readRate' => $campaign?->read_rate ?? 0,
        ]);
    }
}
