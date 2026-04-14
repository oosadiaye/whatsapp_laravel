<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactGroupRequest;
use App\Models\ContactGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContactGroupController extends Controller
{
    public function index(): View
    {
        $groups = ContactGroup::where('user_id', auth()->id())
            ->withCount('contacts')
            ->latest()
            ->get();

        return view('groups.index', ['groups' => $groups]);
    }

    public function store(StoreContactGroupRequest $request): RedirectResponse
    {
        ContactGroup::create([
            'user_id' => auth()->id(),
            ...$request->validated(),
        ]);

        return redirect()->back()->with('success', 'Group created successfully.');
    }

    public function show(string $id): View
    {
        $group = ContactGroup::where('user_id', auth()->id())
            ->findOrFail($id);

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

        $group = ContactGroup::where('user_id', auth()->id())
            ->findOrFail($id);

        $group->update($validated);

        return redirect()->back()->with('success', 'Group updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $group = ContactGroup::where('user_id', auth()->id())
            ->findOrFail($id);

        $group->delete();

        return redirect()
            ->route('groups.index')
            ->with('success', 'Group deleted successfully.');
    }
}
