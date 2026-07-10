<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallLog;
use Livewire\Component;

/**
 * Phase 18 — outbound call banner. Mounted by in-flight-call for an
 * in-flight outbound AT call. Heavy state (audio peer, mic stream) lives
 * in the Alpine factory (resources/js/outbound-call.js), which talks to the
 * persistent WebRTC softphone (window.bqVoiceClient) registered once per
 * page in the layout — so the agent's client is already online when AT
 * bridges the answered call to it.
 */
class OutgoingCall extends Component
{
    public CallLog $call;

    public function mount(CallLog $call): void
    {
        $this->call = $call;
    }

    public function render()
    {
        return view('livewire.outgoing-call');
    }
}
