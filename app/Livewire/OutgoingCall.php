<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallLog;
use App\Services\AfricasTalkingVoiceService;
use Livewire\Component;

/**
 * Phase 18 — outbound call banner. Mounted when CallRinging fires
 * on the agent's channel for an outbound AT call. Heavy state (audio
 * peer, mic stream) lives in the Alpine factory (resources/js/outbound-call.js)
 * because Livewire can't retain JS objects across re-renders.
 */
class OutgoingCall extends Component
{
    public CallLog $call;
    public string $atToken = '';

    public function mount(CallLog $call): void
    {
        $this->call = $call;
        $this->atToken = app(AfricasTalkingVoiceService::class)
            ->generateClientToken(auth()->user());
    }

    public function render()
    {
        return view('livewire.outgoing-call');
    }
}
