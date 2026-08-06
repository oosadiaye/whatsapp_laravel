<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallLog;
use Livewire\Component;

class CallWrapUp extends Component
{
    public CallLog $call;

    public ?string $disposition = null;

    public ?string $wrapUpNote = null;

    public bool $saving = false;

    public array $dispositions = [];

    public function mount(): void
    {
        $this->dispositions = [
            'answered' => __('Answered'),
            'voicemail' => __('Left voicemail'),
            'no_answer' => __('No answer'),
            'busy' => __('Line busy'),
            'wrong_number' => __('Wrong number'),
            'disconnected' => __('Number disconnected'),
            'callback_requested' => __('Callback requested'),
            'not_interested' => __('Not interested'),
            'qualified_lead' => __('Qualified lead'),
            'follow_up' => __('Follow-up needed'),
            'other' => __('Other'),
        ];

        $this->disposition = $this->call->disposition;
    }

    public function save(): void
    {
        $this->authorizeCallAccess();

        $this->saving = true;

        $this->validate([
            'disposition' => 'nullable|string|max:50',
            'wrapUpNote' => 'nullable|string|max:1000',
        ]);

        $this->call->update([
            'disposition' => $this->disposition,
            'wrap_up_at' => now(),
        ]);

        if ($this->wrapUpNote) {
            $this->call->notes()->create([
                'user_id' => auth()->id(),
                'body' => $this->wrapUpNote,
            ]);
        }

        $this->saving = false;

        $this->dispatch('wrap-up-saved');
    }

    /**
     * Enforce the same per-call access rule as CallController: company-wide
     * viewers, the assigned agent, or the agent who placed the call. Without
     * this the update endpoint trusts whatever CallLog the caller mounted the
     * component with — a crafted Livewire payload could disposition + annotate
     * ANY call.
     */
    private function authorizeCallAccess(): void
    {
        $user = auth()->user();
        $allowed = $user->can('conversations.view_all')
            || $this->call->placed_by_user_id === $user->id
            || $this->call->conversation?->assigned_to_user_id === $user->id;

        abort_unless($allowed, 403, 'You do not have access to this call.');
    }

    public function render()
    {
        return view('livewire.call-wrap-up');
    }
}
