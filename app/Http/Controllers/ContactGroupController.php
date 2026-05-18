<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactGroupRequest;
use App\Models\ContactGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Single-tenant: every contact group is visible to every user with the
 * groups.* permissions. The user_id column on a row is audit metadata
 * (who first created the group) but does NOT scope read or write queries.
 * The route-level permission middleware is the only authorization layer.
 *
 * Previously each user only saw groups they themselves created — that
 * was multi-tenant residue and meant a newly-added admin saw an empty
 * /groups list even when the company already had named contact lists.
 */
class ContactGroupController extends Controller
{
    public function index(): View
    {
        $groups = ContactGroup::withCount('contacts')
            ->latest()
            ->get();

        return view('groups.index', ['groups' => $groups]);
    }

    public function store(StoreContactGroupRequest $request): RedirectResponse
    {
        ContactGroup::create([
            // Audit: tag the row with the creator's id. The column stays
            // populated so support can ask "who set this group up?" — it
            // just no longer scopes lookups.
            'user_id' => auth()->id(),
            ...$request->validated(),
        ]);

        return redirect()->back()->with('success', 'Group created successfully.');
    }

    public function show(string $id): View
    {
        $group = ContactGroup::findOrFail($id);

        $contacts = $group->contacts()->paginate(20);

        return view('groups.show', [
            'group' => $group,
            'contacts' => $contacts,
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $group = ContactGroup::findOrFail($id);

        $group->update($validated);

        return redirect()->back()->with('success', 'Group updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $group = ContactGroup::findOrFail($id);

        $group->delete();

        return redirect()
            ->route('groups.index')
            ->with('success', 'Group deleted successfully.');
    }
}
