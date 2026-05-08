<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallLog;
use App\Services\AfricasTalkingVoiceService;
use Livewire\Component;

/**
 * Phase 17 — replaces the simple "Open conversation" button on the
 * Phase 14.1 call banner with full Accept/Decline/in-call WebRTC UI.
 *
 * The Livewire side is intentionally minimal: it owns the CallLog
 * binding, the Alpine factory in resources/js/calls.js owns the entire
 * RTCPeerConnection lifecycle (Livewire cannot hold a JS object across
 * re-renders, so the heavy state is JS-side).
 *
 * Phase 18: when the call's provider is Africa's Talking the view
 * branches to the AT factory (window.incomingAtCall) which uses the
 * AT JS SDK rather than raw WebRTC; we need an AT client token for that
 * branch, generated server-side here.
 */
class IncomingCall extends Component
{
    public CallLog $call;
    public string $atToken = '';

    public function mount(CallLog $call): void
    {
        $this->call = $call;
        if ($call->provider === CallLog::PROVIDER_AFRICAS_TALKING) {
            $this->atToken = app(AfricasTalkingVoiceService::class)
                ->generateClientToken(auth()->user());
        }
    }

    public function render()
    {
        return view('livewire.incoming-call');
    }
}
