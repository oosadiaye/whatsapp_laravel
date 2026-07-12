<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Conversation;
use Livewire\Component;

/**
 * Live chat thread. Polls every few seconds so a customer's inbound reply
 * appears without a manual page refresh — the flagship two-way-inbox behaviour
 * that the server-rendered thread was missing.
 *
 * Renders the same message + call-card timeline the show page built inline.
 * Authorizes on every render (not just mount) so an agent unassigned mid-poll
 * stops seeing the thread.
 */
class ConversationThread extends Component
{
    public int $conversationId;

    public function mount(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->authorizeAccess();
    }

    public function render()
    {
        $conversation = $this->authorizeAccess();

        $messages = $conversation->messages()->with('sentBy')->get();
        $callLogs = $conversation->callLogs()->with('placedBy')->get();
        $timeline = $messages->concat($callLogs)->sortBy('created_at')->values();

        return view('livewire.conversation-thread', [
            'timeline' => $timeline,
        ]);
    }

    private function authorizeAccess(): Conversation
    {
        $conversation = Conversation::findOrFail($this->conversationId);
        $user = auth()->user();

        $ok = $user?->can('conversations.view_all')
            || ($user?->can('conversations.view_assigned') && $conversation->assigned_to_user_id === $user->id);

        abort_unless($ok, 403);

        return $conversation;
    }
}
