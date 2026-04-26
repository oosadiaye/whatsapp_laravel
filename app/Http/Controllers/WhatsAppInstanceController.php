<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\WhatsAppApiException;
use App\Models\WhatsAppInstance;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * WhatsApp Cloud API instance lifecycle: setup, credential validation,
 * health refresh, deletion.
 *
 * Each instance is one Meta phone number with its own credentials. The
 * controller never stores credentials in plaintext — the model's
 * 'encrypted' casts handle that — and never logs them.
 */
class WhatsAppInstanceController extends Controller
{
    public function __construct(
        private readonly WhatsAppCloudApiService $cloudApi,
    ) {}

    public function index(): View
    {
        $instances = WhatsAppInstance::where('user_id', auth()->id())
            ->latest()
            ->get();

        return view('instances.index', ['instances' => $instances]);
    }

    public function create(): View
    {
        return view('instances.create');
    }

    /**
     * Create a Cloud API instance. Probes Meta on save to confirm credentials
     * are real — if Meta rejects them, we still save (so user can fix typos
     * without losing data) but flag the row CREDENTIALS_INVALID.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'instance_name' => ['required', 'string', 'max:255', 'unique:whatsapp_instances,instance_name'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'waba_id' => ['required', 'string', 'max:64'],
            'phone_number_id' => ['required', 'string', 'max:64', 'unique:whatsapp_instances,phone_number_id'],
            'access_token' => ['required', 'string'],
            'app_secret' => ['required', 'string'],
            'webhook_verify_token' => ['nullable', 'string', 'max:255'],
        ]);

        $instance = WhatsAppInstance::create([
            'user_id' => auth()->id(),
            'instance_name' => $validated['instance_name'],
            'display_name' => $validated['display_name'] ?? $validated['instance_name'],
            'waba_id' => $validated['waba_id'],
            'phone_number_id' => $validated['phone_number_id'],
            'access_token' => $validated['access_token'],
            'app_secret' => $validated['app_secret'],
            // Auto-generate verify token when blank — user copies it into Meta.
            'webhook_verify_token' => $validated['webhook_verify_token'] ?: Str::random(32),
            'status' => 'PENDING_VERIFICATION',
        ]);

        try {
            $info = $this->cloudApi->getPhoneNumberInfo($instance);

            $instance->update([
                'business_phone_number' => $info['display_phone_number'] ?? null,
                'display_name' => $info['verified_name'] ?? $instance->display_name,
                'quality_rating' => $info['quality_rating'] ?? null,
                'messaging_limit_tier' => $info['messaging_limit_tier'] ?? null,
                'status' => 'CONNECTED',
            ]);

            return redirect()
                ->route('instances.show', $instance)
                ->with('success', 'Instance connected. Copy the webhook URL and verify token into your Meta App dashboard.');
        } catch (WhatsAppApiException $e) {
            $instance->update(['status' => 'CREDENTIALS_INVALID']);

            return redirect()
                ->route('instances.show', $instance)
                ->with('warning', "Saved, but Meta rejected the credentials: {$e->getMessage()}");
        } catch (Throwable $e) {
            Log::warning('Cloud API probe failed during instance create', ['error' => $e->getMessage()]);
            $instance->update(['status' => 'UNREACHABLE']);

            return redirect()
                ->route('instances.show', $instance)
                ->with('warning', 'Saved, but could not reach graph.facebook.com. Check the instance later.');
        }
    }

    public function show(string $id): View
    {
        $instance = WhatsAppInstance::where('user_id', auth()->id())->findOrFail($id);

        $status = $instance->status;
        $phoneInfo = null;

        if ($instance->isReady()) {
            try {
                $phoneInfo = $this->cloudApi->getPhoneNumberInfo($instance);
                $status = 'CONNECTED';

                $instance->update([
                    'quality_rating' => $phoneInfo['quality_rating'] ?? $instance->quality_rating,
                    'messaging_limit_tier' => $phoneInfo['messaging_limit_tier'] ?? $instance->messaging_limit_tier,
                    'business_phone_number' => $phoneInfo['display_phone_number'] ?? $instance->business_phone_number,
                    'display_name' => $phoneInfo['verified_name'] ?? $instance->display_name,
                    'status' => 'CONNECTED',
                ]);
            } catch (Throwable $e) {
                Log::warning('Cloud API getPhoneNumberInfo failed', ['error' => $e->getMessage()]);
                $status = 'CREDENTIALS_INVALID';
            }
        }

        return view('instances.show', [
            'instance' => $instance,
            'status' => $status,
            'phoneInfo' => $phoneInfo,
            'cloudWebhookUrl' => route('webhook.cloud.handle', ['instance' => $instance->id]),
        ]);
    }

    public function destroy(string $id): RedirectResponse
    {
        $instance = WhatsAppInstance::where('user_id', auth()->id())->findOrFail($id);

        // Only the local row is removed — Meta-side configuration is owned by
        // the customer's Meta App, not by us. Customer revokes access there.
        $instance->delete();

        return redirect()
            ->route('instances.index')
            ->with('success', 'Instance deleted successfully.');
    }

    public function setDefault(string $id): RedirectResponse
    {
        $userId = auth()->id();

        WhatsAppInstance::where('user_id', $userId)->update(['is_default' => false]);
        WhatsAppInstance::where('user_id', $userId)->where('id', $id)->update(['is_default' => true]);

        return redirect()->back()->with('success', 'Default instance updated.');
    }
}
