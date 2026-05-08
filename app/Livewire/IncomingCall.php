<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallLog;
use Livewire\Component;

/**
 * Phase 17 — replaces the simple "Open conversation" button on the
 * Phase 14.1 call banner with full Accept/Decline/in-call WebRTC UI.
 *
 * The Livewire side is intentionally minimal: it owns the CallLog
 * binding, the Alpine factory in resources/js/calls.js owns the entire
 * RTCPeerConnection lifecycle (Livewire cannot hold a JS object across
 * re-renders, so the heavy state is JS-side).
 */
class IncomingCall extends Component
{
    public CallLog $call;

    public function mount(CallLog $call): void
    {
        $this->call = $call;
    }

    public function render()
    {
        return view('livewire.incoming-call');
    }
}
