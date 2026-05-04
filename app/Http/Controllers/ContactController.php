<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\WhatsAppApiException;
use App\Http\Requests\ImportContactsRequest;
use App\Jobs\ProcessContactImport;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Conversation;
use App\Models\WhatsAppInstance;
use App\Services\ContactImportService;
use App\Services\OutboundCallService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    public function index(Request $request): View
    {
        $threshold = now()->subDays(\App\Models\Contact::ENGAGEMENT_WINDOW_DAYS);

        $query = Contact::where('user_id', auth()->id())
            ->withExists([
                // True if at least one inbound message exists for any of this
                // contact's conversations within the engagement window.
                'conversationMessages as has_recent_inbound_message' => fn ($q) =>
                    $q->where('conversation_messages.direction', 'inbound')
                      ->where('received_at', '>=', $threshold),

                // True if at least one inbound call exists within the window.
                'callLogs as has_recent_inbound_call' => fn ($q) =>
                    $q->where('call_logs.direction', \App\Models\CallLog::DIRECTION_INBOUND)
                      ->where('call_logs.created_at', '>=', $threshold),
            ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $contacts = $query->latest()->paginate(20);

        // Compose `is_engaged` from the two eager-loaded flags so the view
        // and the row-action visibility don't need to think about which
        // signal triggered engagement.
        $contacts->getCollection()->transform(function (Contact $c): Contact {
            $c->is_engaged = (bool) ($c->has_recent_inbound_message || $c->has_recent_inbound_call);
            return $c;
        });

        return view('contacts.index', ['contacts' => $contacts]);
    }

    /**
     * Open a chat thread with this contact. If no Conversation exists yet for
     * the picked WhatsApp instance, create one (find-or-create — clicking
     * Chat twice never creates duplicate rows). Pure navigation: no message
     * is sent. The thread view enforces the 24h freeform/template policy.
     */
    public function startChat(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeContactAccess($request, $contact);

        $instance = $this->resolveInstance($request);
        if ($instance === null) {
            return back()->with('error', $this->instancePickError($request));
        }

        $conversation = Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
            ['user_id' => $contact->user_id, 'unread_count' => 0],
        );

        return redirect()->route('conversations.show', $conversation);
    }

    /**
     * Place an outbound call to this contact.
     *
     * Defense in depth: the contact-list view already disables this button
     * for non-engaged contacts (Meta opt-in policy proxy), but the server
     * MUST re-check engagement so a misclick or bypassed UI cannot bypass
     * the policy gate. Calls cost real money and have quality-rating risk.
     *
     * Reuses the Voice Phase A {@see OutboundCallService::initiate} flow
     * after find-or-create on the conversation.
     */
    public function startCall(
        Request $request,
        Contact $contact,
        OutboundCallService $outboundCallService,
    ): RedirectResponse {
        $this->authorizeContactAccess($request, $contact);

        if (! $contact->isEngaged()) {
            return back()->with(
                'error',
                'Cannot call this contact yet — they must message you first '
                .'(Meta opt-in policy). Wait for an inbound message or call.',
            );
        }

        $instance = $this->resolveInstance($request);
        if ($instance === null) {
            return back()->with('error', $this->instancePickError($request));
        }

        $conversation = Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
            ['user_id' => $contact->user_id, 'unread_count' => 0],
        );

        try {
            $outboundCallService->initiate($conversation, $request->user());
        } catch (WhatsAppApiException $e) {
            return redirect()
                ->route('conversations.show', $conversation)
                ->with('error', "Could not place call: {$e->getMessage()}");
        }

        return redirect()
            ->route('conversations.show', $conversation)
            ->with('success', "Calling {$contact->name}...");
    }

    /**
     * Same-account ownership guard. Mirrors the pattern from
     * ConversationController::authorizeConversationAccess.
     */
    private function authorizeContactAccess(Request $request, Contact $contact): void
    {
        abort_unless($contact->user_id === $request->user()->id, 403);
    }

    /**
     * Resolve which WhatsApp instance to use for a contact-initiated action.
     *
     * - 1 instance → auto-pick it.
     * - 2+ instances → require `instance_id` in the request body
     *   (sent by the picker modal in contacts/index.blade.php).
     * - 0 instances → return null; caller flashes a setup error.
     *
     * Returns null on any unresolved case so the caller can return a flash
     * error rather than throwing.
     */
    private function resolveInstance(Request $request): ?WhatsAppInstance
    {
        // Only CONNECTED instances are usable for outbound API calls; a
        // DISCONNECTED or PENDING instance would fail at Meta's edge anyway.
        // ('status' is the actual schema column on whatsapp_instances —
        // there is no `is_active` column.)
        $instances = WhatsAppInstance::where('user_id', $request->user()->id)
            ->where('status', 'CONNECTED')
            ->get();

        if ($instances->count() === 0) {
            return null;
        }

        if ($instances->count() === 1) {
            return $instances->first();
        }

        $picked = (int) $request->input('instance_id', 0);
        return $instances->firstWhere('id', $picked);
    }

    /**
     * Human-readable error message when {@see resolveInstance()} returns null.
     */
    private function instancePickError(Request $request): string
    {
        $count = WhatsAppInstance::where('user_id', $request->user()->id)
            ->where('status', 'CONNECTED')
            ->count();

        return $count === 0
            ? 'Set up a WhatsApp instance before starting conversations.'
            : 'Pick which WhatsApp number to use from the picker.';
    }

    public function importForm(): View
    {
        $groups = ContactGroup::where('user_id', auth()->id())->get();

        return view('contacts.import', ['groups' => $groups]);
    }

    public function importProcess(ImportContactsRequest $request): RedirectResponse
    {
        $userId = auth()->id();
        $groupId = $request->validated('group_id');

        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('imports');

            ProcessContactImport::dispatch($userId, $path, $groupId, $request->validated('column_map'));

            return redirect()
                ->route('contacts.index')
                ->with('success', 'File uploaded. Import is being processed in the background.');
        }

        $lines = array_filter(
            array_map('trim', explode("\n", $request->validated('manual_input'))),
        );

        $group = ContactGroup::where('user_id', $userId)->findOrFail($groupId);
        $imported = 0;
        $invalid = 0;
        $importService = new ContactImportService();

        foreach ($lines as $line) {
            $parts = str_getcsv($line);
            $phone = $importService->normalizePhone($parts[0] ?? '');

            if ($phone === null) {
                $invalid++;
                continue;
            }

            $contact = Contact::updateOrCreate(
                ['user_id' => $userId, 'phone' => $phone],
                ['name' => trim($parts[1] ?? ''), 'is_active' => true],
            );

            $group->contacts()->syncWithoutDetaching([$contact->id]);
            $imported++;
        }

        $group->update(['contact_count' => $group->contacts()->count()]);

        return redirect()
            ->route('contacts.index')
            ->with('success', "{$imported} contacts imported successfully.");
    }

    public function edit(string $id): View
    {
        $contact = Contact::where('user_id', auth()->id())->findOrFail($id);

        return view('contacts.edit', ['contact' => $contact]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $contact = Contact::where('user_id', auth()->id())->findOrFail($id);
        $contact->update($validated);

        return redirect()->back()->with('success', 'Contact updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $contact = Contact::where('user_id', auth()->id())->findOrFail($id);
        $contact->delete();

        return redirect()->back()->with('success', 'Contact deleted successfully.');
    }

    public function exportGroup(string $groupId): StreamedResponse
    {
        $group = ContactGroup::where('user_id', auth()->id())->findOrFail($groupId);
        $contacts = $group->contacts()->get(['phone', 'name']);

        $filename = 'contacts_' . str_replace(' ', '_', $group->name) . '_' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($contacts) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['phone', 'name']);

            foreach ($contacts as $contact) {
                fputcsv($handle, [$contact->phone, $contact->name]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9+]/', '', trim($phone));

        return $phone ?: '';
    }
}
