<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ImportContactsRequest;
use App\Jobs\ProcessContactImport;
use App\Models\Contact;
use App\Models\ContactGroup;
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
