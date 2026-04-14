<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTemplateRequest;
use App\Models\MessageTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class MessageTemplateController extends Controller
{
    public function index(): View
    {
        $templates = MessageTemplate::where('user_id', auth()->id())
            ->latest()
            ->get();

        return view('templates.index', ['templates' => $templates]);
    }

    public function create(): View
    {
        return view('templates.create');
    }

    public function store(StoreTemplateRequest $request): RedirectResponse
    {
        $data = [
            'user_id' => auth()->id(),
            'name' => $request->validated('name'),
            'content' => $request->validated('content'),
            'category' => $request->validated('category'),
        ];

        if ($request->hasFile('media')) {
            $path = $request->file('media')->store('templates');
            $data['media_path'] = $path;
            $data['media_type'] = $this->resolveMediaType($request->file('media')->getClientOriginalExtension());
        }

        MessageTemplate::create($data);

        return redirect()
            ->route('templates.index')
            ->with('success', 'Template created successfully.');
    }

    public function edit(string $id): View
    {
        $template = MessageTemplate::where('user_id', auth()->id())
            ->findOrFail($id);

        return view('templates.edit', ['template' => $template]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'category' => ['required', 'in:promotional,transactional,reminder'],
            'media' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,gif,pdf,mp3,ogg'],
        ]);

        $template = MessageTemplate::where('user_id', auth()->id())
            ->findOrFail($id);

        $template->update([
            'name' => $validated['name'],
            'content' => $validated['content'],
            'category' => $validated['category'],
        ]);

        if ($request->hasFile('media')) {
            if ($template->media_path) {
                Storage::delete($template->media_path);
            }

            $path = $request->file('media')->store('templates');
            $template->update([
                'media_path' => $path,
                'media_type' => $this->resolveMediaType($request->file('media')->getClientOriginalExtension()),
            ]);
        }

        return redirect()->back()->with('success', 'Template updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $template = MessageTemplate::where('user_id', auth()->id())
            ->findOrFail($id);

        $template->delete();

        return redirect()
            ->route('templates.index')
            ->with('success', 'Template deleted successfully.');
    }

    private function resolveMediaType(string $extension): string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg', 'png', 'gif' => 'image',
            'pdf' => 'document',
            'mp3', 'ogg' => 'audio',
            default => 'document',
        };
    }
}
