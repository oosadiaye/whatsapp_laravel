<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\WhatsAppApiException;
use App\Http\Requests\StoreTemplateRequest;
use App\Models\MessageTemplate;
use App\Models\WhatsAppInstance;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

/**
 * Handles message-template CRUD across two sources:
 *
 *   - Local templates  — handcrafted, stored only in our DB. Useful for
 *                        Evolution-driven instances or freeform 24h-window
 *                        sends. status='LOCAL', whatsapp_template_id=null.
 *
 *   - Remote templates — fetched from / submitted to Meta's Cloud API.
 *                        status mirrors Meta (PENDING/APPROVED/REJECTED).
 *                        whatsapp_template_id set to Meta's template ID.
 *
 * The {@see sync()} action pulls every template Meta knows about for an
 * instance and upserts; {@see submitToMeta()} pushes a local template up
 * for review.
 */
class MessageTemplateController extends Controller
{
    public function __construct(private readonly WhatsAppCloudApiService $cloudApi)
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
            ->get(['id', 'instance_name', 'business_phone_number', 'phone_number', 'status']);

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
            'language' => $request->validated('language') ?? 'en_US',
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

        // Remote (Meta-managed) templates can't be edited via Cloud API after submission;
        // user must delete and resubmit. Block edit attempts to prevent silent drift between
        // our DB and Meta's source of truth.
        if ($template->isRemote()) {
            return redirect()
                ->back()
                ->with('error', 'Approved templates cannot be edited. Delete and resubmit instead.');
        }

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

        // For remote templates, also tell Meta to delete — otherwise the local row
        // disappears but Meta still bills/uses the template name.
        if ($template->isRemote() && $template->whatsappInstance) {
            try {
                $this->cloudApi->deleteTemplate($template->whatsappInstance, $template->name);
            } catch (Throwable $e) {
                // Soft-fail: the local row is removed regardless, so the user can re-sync
                // to recover state. Surface a warning instead of blocking.
                return redirect()
                    ->route('templates.index')
                    ->with('warning', "Local template removed, but Meta delete failed: {$e->getMessage()}. Re-sync if needed.");
            }
        }

        $template->delete();

        return redirect()
            ->route('templates.index')
            ->with('success', 'Template deleted successfully.');
    }

    /**
     * Pull every template Meta has for the given Cloud API instance and upsert
     * each into the local DB. Idempotent — safe to run on a schedule.
     */
    public function sync(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'whatsapp_instance_id' => ['required', 'integer', 'exists:whatsapp_instances,id'],
        ]);

        $instance = WhatsAppInstance::where('user_id', auth()->id())
            ->findOrFail($validated['whatsapp_instance_id']);

        if (! $instance->isReady()) {
            return redirect()
                ->route('templates.index')
                ->with('error', 'Instance is missing Cloud API credentials. Reopen its settings to fix.');
        }

        try {
            $remoteTemplates = $this->cloudApi->fetchTemplates($instance);
        } catch (WhatsAppApiException $e) {
            return redirect()
                ->route('templates.index')
                ->with('error', "Sync failed: {$e->getMessage()}");
        } catch (Throwable $e) {
            return redirect()
                ->route('templates.index')
                ->with('error', 'Could not reach Meta. Check the instance credentials and try again.');
        }

        if (empty($remoteTemplates)) {
            return redirect()
                ->route('templates.index')
                ->with('warning', 'No templates returned. Approve at least one template in Meta Business Manager first.');
        }

        $created = 0;
        $updated = 0;

        foreach ($remoteTemplates as $remote) {
            $result = $this->upsertRemoteTemplate($instance, $remote);
            $result === 'created' ? $created++ : $updated++;
        }

        return redirect()
            ->route('templates.index')
            ->with('success', "Synced {$created} new, {$updated} updated template(s) from Meta.");
    }

    /**
     * Push a local template up to Meta for review. After this, Meta has a
     * say — the template enters PENDING and only becomes usable once it
     * transitions to APPROVED.
     */
    public function submitToMeta(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'whatsapp_instance_id' => ['required', 'integer', 'exists:whatsapp_instances,id'],
        ]);

        $template = MessageTemplate::where('user_id', auth()->id())->findOrFail($id);
        $instance = WhatsAppInstance::where('user_id', auth()->id())
            ->findOrFail($validated['whatsapp_instance_id']);

        if ($template->isRemote()) {
            return redirect()->back()->with('error', 'Template is already managed by Meta.');
        }

        if (! $instance->isReady()) {
            return redirect()->back()->with('error', 'Instance is missing Cloud API credentials.');
        }

        $components = $template->components ?: [
            ['type' => 'BODY', 'text' => $template->content],
        ];

        try {
            $response = $this->cloudApi->createTemplate(
                $instance,
                $template->name,
                $this->mapCategoryToMeta($template->category),
                $template->language ?? 'en_US',
                $components,
            );
        } catch (Throwable $e) {
            return redirect()->back()->with('error', "Meta rejected the submission: {$e->getMessage()}");
        }

        $template->update([
            'whatsapp_instance_id' => $instance->id,
            'whatsapp_template_id' => $response['id'] ?? null,
            'status' => strtoupper((string) ($response['status'] ?? MessageTemplate::STATUS_PENDING)),
            'components' => $components,
            'synced_at' => Carbon::now(),
        ]);

        return redirect()
            ->route('templates.index')
            ->with('success', 'Submitted to Meta. Status will move from PENDING to APPROVED/REJECTED — re-sync to refresh.');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────────────

    /**
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
        $category = $this->mapCategoryFromMeta((string) ($remote['category'] ?? ''));

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

    /** Meta category strings → local enum (sync direction). */
    private function mapCategoryFromMeta(string $metaCategory): string
    {
        return match (strtoupper($metaCategory)) {
            'MARKETING' => 'promotional',
            'UTILITY', 'AUTHENTICATION' => 'transactional',
            default => 'reminder',
        };
    }

    /** Local enum → Meta category strings (submission direction). */
    private function mapCategoryToMeta(string $localCategory): string
    {
        return match ($localCategory) {
            'promotional' => 'MARKETING',
            'transactional' => 'UTILITY',
            'reminder' => 'UTILITY',
            default => 'UTILITY',
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
