<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\EvolutionApiException;
use App\Http\Requests\StoreTemplateRequest;
use App\Models\MessageTemplate;
use App\Models\WhatsAppInstance;
use App\Services\EvolutionApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class MessageTemplateController extends Controller
{
    public function __construct(private readonly EvolutionApiService $evolutionApi)
    {
    }

    public function index(): View
    {
        $templates = MessageTemplate::where('user_id', auth()->id())
            ->with('whatsappInstance')
            ->latest()
            ->get();

        $instances = WhatsAppInstance::where('user_id', auth()->id())
            ->orderBy('is_default', 'desc')
            ->orderBy('instance_name')
            ->get(['id', 'instance_name', 'phone_number', 'status']);

        return view('templates.index', [
            'templates' => $templates,
            'instances' => $instances,
        ]);
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
            'status' => MessageTemplate::STATUS_LOCAL,
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

    /**
     * Pull WhatsApp Business templates from Evolution API and upsert them locally.
     */
    public function sync(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'whatsapp_instance_id' => ['required', 'integer', 'exists:whatsapp_instances,id'],
        ]);

        $instance = WhatsAppInstance::where('user_id', auth()->id())
            ->findOrFail($validated['whatsapp_instance_id']);

        try {
            $remoteTemplates = $this->evolutionApi->fetchTemplates($instance->instance_name);
        } catch (EvolutionApiException $e) {
            return redirect()
                ->route('templates.index')
                ->with('error', "Sync failed: {$e->getMessage()}");
        } catch (Throwable $e) {
            return redirect()
                ->route('templates.index')
                ->with('error', 'Could not reach Evolution API. Check your connection settings.');
        }

        if (empty($remoteTemplates)) {
            return redirect()
                ->route('templates.index')
                ->with('warning', 'No templates returned. The instance must use the WhatsApp Cloud API integration to expose templates.');
        }

        $created = 0;
        $updated = 0;

        foreach ($remoteTemplates as $remote) {
            $result = $this->upsertRemoteTemplate($instance, $remote);
            $result === 'created' ? $created++ : $updated++;
        }

        return redirect()
            ->route('templates.index')
            ->with('success', "Synced {$created} new, {$updated} updated template(s) from WhatsApp.");
    }

    /**
     * Upsert one Evolution API template payload into the local DB.
     *
     * @param  array<string, mixed>  $remote
     * @return 'created'|'updated'
     */
    private function upsertRemoteTemplate(WhatsAppInstance $instance, array $remote): string
    {
        $name = (string) ($remote['name'] ?? 'unnamed');
        $language = (string) ($remote['language'] ?? 'en_US');
        $remoteId = (string) ($remote['id'] ?? $name);
        $components = is_array($remote['components'] ?? null) ? $remote['components'] : [];
        $bodyText = $this->extractBodyText($components);
        $category = $this->mapCategory((string) ($remote['category'] ?? ''));

        $existing = MessageTemplate::where('user_id', auth()->id())
            ->where('whatsapp_instance_id', $instance->id)
            ->where('whatsapp_template_id', $remoteId)
            ->where('language', $language)
            ->first();

        $payload = [
            'user_id' => auth()->id(),
            'whatsapp_instance_id' => $instance->id,
            'whatsapp_template_id' => $remoteId,
            'name' => $name,
            'language' => $language,
            'status' => strtoupper((string) ($remote['status'] ?? MessageTemplate::STATUS_APPROVED)),
            'content' => $bodyText,
            'components' => $components,
            'category' => $category,
            'synced_at' => Carbon::now(),
        ];

        if ($existing) {
            $existing->update($payload);
            return 'updated';
        }

        MessageTemplate::create($payload);
        return 'created';
    }

    /**
     * Extract the BODY component text from Meta's component array.
     *
     * @param  array<int, array<string, mixed>>  $components
     */
    private function extractBodyText(array $components): string
    {
        foreach ($components as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                return (string) ($component['text'] ?? '');
            }
        }

        return '';
    }

    /**
     * Map Meta template categories to the local enum.
     */
    private function mapCategory(string $metaCategory): string
    {
        return match (strtoupper($metaCategory)) {
            'MARKETING' => 'promotional',
            'UTILITY', 'AUTHENTICATION' => 'transactional',
            default => 'reminder',
        };
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
