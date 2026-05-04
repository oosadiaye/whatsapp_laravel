<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ImportContactsRequest;
use App\Jobs\ProcessContactImport;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Conversation;
use App\Models\WhatsAppInstance;
use App\Services\ContactImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    public function index(): View
    {
        $contacts = Contact::where('user_id', auth()->id())
            ->latest()
            ->paginate(20);

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
        $instances = WhatsAppInstance::where('user_id', $request->user()->id)
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
