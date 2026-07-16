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

        // Single-tenant — every user with contacts.view sees every contact.
        // user_id column stays as audit metadata. Route permission is the gate.
        $query = Contact::query()
            ->with('groups')   // prevents N+1 on the view's @foreach($contact->groups)
            ->withExists([
                // True if at least one inbound message exists for any of this
                // contact's conversations within the engagement window.
                'conversationMessages as has_recent_inbound_message' => fn ($q) =>
                    $q->where('conversation_messages.direction', \App\Models\ConversationMessage::DIRECTION_INBOUND)
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

        return view('contacts.index', [
            'contacts' => $contacts,
        ]);
    }

    /**
     * Open a chat thread with this contact. If no Conversation exists yet for
     * the picked WhatsApp instance, create one (find-or-create — clicking
     * Chat twice never creates duplicate rows). Pure navigation: no message
     * is sent. The thread view enforces the 24h freeform/template policy.
     */
    public function startChat(Contact $contact): RedirectResponse
    {
        ['instance' => $instance, 'error' => $instanceError] = $this->resolveInstanceOrError();
        if ($instance === null) {
            return back()->with('error', $instanceError);
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
        // Meta Cloud Calling is not GA and cannot connect the agent's audio;
        // this contact-initiated path is disabled until the feature ships.
        // The working call path is the in-chat button (Africa's Talking).
        if (! config('voice.meta_calling_enabled')) {
            return back()->with('error',
                'Contact-initiated calling is disabled (Meta Cloud Calling is not available in this build). Open the conversation and use the in-chat call button instead.');
        }

        if (! $contact->isEngaged()) {
            return back()->with(
                'error',
                'Cannot call this contact yet — they must message you first '
                .'(Meta opt-in policy). Wait for an inbound message or call.',
            );
        }

        ['instance' => $instance, 'error' => $instanceError] = $this->resolveInstanceOrError();
        if ($instance === null) {
            return back()->with('error', $instanceError);
        }

        $conversation = Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
            ['user_id' => $contact->user_id, 'unread_count' => 0],
        );

        try {
            $outboundCallService->initiate($conversation, $request->user());
        } catch (WhatsAppApiException $e) {
            // Log the full Meta response body server-side; flash a user-safe
            // hint that explains the cause without leaking tokens/IDs. The
            // userFacingCallError() helper on the base Controller is the
            // single source of truth for this translation.
            \Illuminate\Support\Facades\Log::error('Outbound call failed', [
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            return redirect()
                ->route('conversations.show', $conversation)
                ->with('error', $this->userFacingCallError($e->getMessage()));
        }

        return redirect()
            ->route('conversations.show', $conversation)
            ->with('success', "Calling {$contact->name}...");
    }

    /**
     * Resolve the WhatsApp instance for a contact-initiated action.
     *
     * Single-instance app: there is exactly one WhatsApp number, configured on
     * the Settings page — no send-time picker. Returns the primary instance, or
     * null + an error message the caller flashes on redirect.
     *
     * @return array{instance: ?\App\Models\WhatsAppInstance, error: ?string}
     */
    private function resolveInstanceOrError(): array
    {
        $instance = WhatsAppInstance::primary();

        if ($instance === null) {
            return [
                'instance' => null,
                'error' => 'Configure your WhatsApp number in Settings before starting conversations.',
            ];
        }

        return ['instance' => $instance, 'error' => null];
    }

    public function importForm(): View
    {
        // Single-tenant: import wizard offers every group as a destination,
        // not just ones the current user created.
        $groups = ContactGroup::all();

        return view('contacts.import', ['groups' => $groups]);
    }

    /**
     * Stream a CSV template for contact upload. Columns mirror what
     * ContactImportService can consume: phone (required), name, and two
     * optional custom-field slots. The phone samples are in Nigerian
     * format because that's the deployment target — the import service
     * normalises any locally-valid form, but operators pasting their
     * own list copy from the sample line they see.
     *
     * Streamed (not file-stored) because:
     *   1. The content is small enough (< 500 bytes) that disk I/O is wasteful
     *   2. No filesystem permissions to think about
     *   3. Always returns fresh content — no chance of a stale cached template
     *      after the column spec changes
     */
    public function downloadTemplate(): StreamedResponse
    {
        $filename = 'blastiq-contacts-template.csv';

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            // BOM so Excel on Windows opens UTF-8 names (e.g. Olu Adébáyò)
            // without garbling them. Excel auto-detects encoding from BOM;
            // without it, accented Latin or Yoruba diacritics render as
            // "Olu Adu00e9báyò" — a frequent operator complaint.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['phone', 'email', 'name', 'custom_field_1', 'custom_field_2']);
            // Sample rows. Three rows give the operator a clear visual
            // pattern (header + 1 = "is this how I fill it?"; +2-3 confirms
            // delimiters and column count). The last row is email-only — a
            // prospect with no phone (valid now that phone is optional).
            fputcsv($out, ['+2348012345678', 'adebayo@example.com', 'Adebayo Okonkwo',  'Lagos',  'VIP']);
            fputcsv($out, ['+2347098765432', '',                    'Chiamaka Nwosu',   'Abuja',  '']);
            fputcsv($out, ['',               'tunde@example.com',   'Tunde Bello',      'Ibadan', 'NewLead']);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // 'no-store' so a re-download after a template-format change
            // doesn't serve the old version from the browser cache.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function importProcess(ImportContactsRequest $request): RedirectResponse
    {
        $userId = auth()->id();
        $groupId = $request->validated('group_id');

        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('imports');

            // ProcessContactImport constructor order is (filePath, groupId,
            // columnMap, userId). The previous call had the arguments in the
            // wrong order and PHP 8's typed-promoted-properties threw a
            // TypeError synchronously — 500 before the queue ever saw it.
            // Cast to satisfy `int` typed properties (group_id/user_id
            // come through as strings via the form; auth()->id() returns
            // string|null on some auth guards).
            ProcessContactImport::dispatch(
                $path,
                (int) $groupId,
                $request->validated('column_map') ?? [],
                (int) $userId,
            );

            return redirect()
                ->route('contacts.index')
                ->with('success', 'File uploaded. Import is being processed in the background.');
        }

        $lines = array_filter(
            array_map('trim', explode("\n", $request->validated('manual_input'))),
        );

        // Single-tenant: any group is a valid target. user_id on the contact
        // row is the creating user's id (audit metadata) — updateOrCreate's
        // unique key is still (user_id, phone) so the same operator's manual
        // re-paste idempotently updates instead of duplicating.
        $group = ContactGroup::findOrFail($groupId);
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

            // Preserve an existing contact's is_active on re-import — a manual
            // re-paste must NOT silently re-activate someone who was opted out /
            // deactivated. Only default is_active=true for genuinely new rows.
            $contact = Contact::firstOrNew(['user_id' => $userId, 'phone' => $phone]);
            if (! $contact->exists) {
                $contact->is_active = true;
            }
            $name = trim($parts[1] ?? '');
            if ($name !== '') {
                $contact->name = $name;
            }
            $contact->save();

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
        // Single-tenant: any permitted user can edit any contact. Route is
        // gated by contacts.edit permission; no ownership filter needed.
        $contact = Contact::findOrFail($id);

        return view('contacts.edit', ['contact' => $contact]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:20', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $contact = Contact::findOrFail($id);
        // Phone is read-only in the form; keep the existing value regardless.
        $contact->update([
            'email' => $validated['email'] ?? null,
            'name' => $validated['name'] ?? $contact->name,
        ]);

        return redirect()->back()->with('success', 'Contact updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $contact = Contact::findOrFail($id);
        $contact->delete();

        return redirect()->back()->with('success', 'Contact deleted successfully.');
    }

    public function exportGroup(string $groupId): StreamedResponse
    {
        $group = ContactGroup::findOrFail($groupId);
        $contacts = $group->contacts()->get(['phone', 'name']);

        $filename = 'contacts_' . str_replace(' ', '_', $group->name) . '_' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($contacts) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['phone', 'name']);

            foreach ($contacts as $contact) {
                fputcsv($handle, [\App\Support\Csv::safe($contact->phone), \App\Support\Csv::safe($contact->name)]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
