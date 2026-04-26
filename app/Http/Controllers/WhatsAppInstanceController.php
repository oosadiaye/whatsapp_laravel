<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\EvolutionApiException;
use App\Models\WhatsAppInstance;
use App\Services\EvolutionApiService;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Handles WhatsApp instance lifecycle for both supported drivers:
 *   - 'cloud'     — Meta Cloud API (credentials pasted from Meta dashboard)
 *   - 'evolution' — legacy Baileys (QR-code scan)
 *
 * The submitted `driver` field on the create form switches between two
 * validation + provisioning paths in {@see store()}. Likewise {@see show()}
 * picks a different status-fetching strategy per driver.
 */
class WhatsAppInstanceController extends Controller
{
    public function __construct(
        private readonly EvolutionApiService $evolutionApi,
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

    public function store(Request $request): RedirectResponse
    {
        $driver = $request->input('driver', WhatsAppInstance::DRIVER_CLOUD);

        return $driver === WhatsAppInstance::DRIVER_CLOUD
            ? $this->storeCloudInstance($request)
            : $this->storeEvolutionInstance($request);
    }

    public function show(string $id): View
    {
        $instance = WhatsAppInstance::where('user_id', auth()->id())
            ->findOrFail($id);

        $status = $instance->status;
        $phoneInfo = null;

        if ($instance->isCloud() && $instance->isCloudReady()) {
            try {
                $phoneInfo = $this->cloudApi->getPhoneNumberInfo($instance);
                $status = 'CONNECTED';

                // Refresh quality + tier from Meta on each show — they change over time.
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
        } elseif ($instance->isEvolution()) {
            try {
                $status = $this->evolutionApi->getInstanceStatus($instance->instance_name);
            } catch (Throwable $e) {
                Log::warning('Evolution API getInstanceStatus failed: '.$e->getMessage());
            }
        }

        return view('instances.show', [
            'instance' => $instance,
            'status' => $status,
            'phoneInfo' => $phoneInfo,
            'cloudWebhookUrl' => $instance->isCloud()
                ? route('webhook.cloud.handle', ['instance' => $instance->id])
                : null,
        ]);
    }

    public function destroy(string $id): RedirectResponse
    {
        $instance = WhatsAppInstance::where('user_id', auth()->id())
            ->findOrFail($id);

        if ($instance->isEvolution()) {
            try {
                $this->evolutionApi->deleteInstance($instance->instance_name);
            } catch (Throwable $e) {
                Log::warning('Evolution API deleteInstance failed: '.$e->getMessage());
            }
        }
        // For cloud instances we only delete the local record — the Meta-side
        // configuration is owned by the customer's Meta App, not by us.

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

    /**
     * Polled by the evolution-driven QR scan view. Cloud API instances don't
     * use this — their connection state is managed entirely in the Meta dashboard.
     */
    public function qrStatus(string $id): JsonResponse
    {
        $instance = WhatsAppInstance::where('user_id', auth()->id())
            ->findOrFail($id);

        if ($instance->isCloud()) {
            return response()->json([
                'status' => 'CONNECTED',
                'qr_code' => null,
                'error' => 'Cloud API instances do not use QR codes.',
            ]);
        }

        $status = $instance->status;
        $qrCode = null;
        $error = null;

        try {
            $status = $this->evolutionApi->getInstanceStatus($instance->instance_name);
            if ($status !== 'open' && $status !== 'CONNECTED') {
                $qrCode = $this->evolutionApi->getQrCode($instance->instance_name);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            Log::warning('Evolution API qrStatus failed: '.$e->getMessage());
        }

        return response()->json([
            'status' => $status,
            'qr_code' => $qrCode,
            'error' => $error,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Driver-specific provisioning
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Create a Cloud API instance. Validates the pasted credentials by calling
     * GET /{phone_number_id} against Meta — if that fails, we still save the
     * instance (so the user can fix typos) but flag it as CREDENTIALS_INVALID.
     */
    private function storeCloudInstance(Request $request): RedirectResponse
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

        // Auto-generate a verify token if user didn't supply one — they'll copy
        // it into Meta's webhook config.
        $verifyToken = $validated['webhook_verify_token'] ?: Str::random(32);

        $instance = WhatsAppInstance::create([
            'user_id' => auth()->id(),
            'driver' => WhatsAppInstance::DRIVER_CLOUD,
            'instance_name' => $validated['instance_name'],
            'display_name' => $validated['display_name'] ?? $validated['instance_name'],
            'waba_id' => $validated['waba_id'],
            'phone_number_id' => $validated['phone_number_id'],
            'access_token' => $validated['access_token'],
            'app_secret' => $validated['app_secret'],
            'webhook_verify_token' => $verifyToken,
            'status' => 'PENDING_VERIFICATION',
        ]);

        // Probe Meta to confirm credentials are valid; fill phone metadata if so.
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
                ->with('success', 'Cloud API instance connected. Copy the webhook URL & verify token into your Meta App dashboard.');
        } catch (EvolutionApiException $e) {
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

    /**
     * Create a legacy Evolution/Baileys instance via QR-code scan.
     */
    private function storeEvolutionInstance(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'instance_name' => ['required', 'string', 'max:255', 'unique:whatsapp_instances,instance_name'],
            'display_name' => ['nullable', 'string', 'max:255'],
        ]);

        $apiToken = null;
        $evolutionOk = true;
        $evolutionError = null;

        try {
            $apiResult = $this->evolutionApi->createInstance($validated['instance_name']);
            $apiToken = $apiResult['hash'] ?? null;
        } catch (Throwable $e) {
            $evolutionOk = false;
            $evolutionError = $e->getMessage();
            Log::warning('Evolution API createInstance failed: '.$e->getMessage());
        }

        $instance = WhatsAppInstance::create([
            'user_id' => auth()->id(),
            'driver' => WhatsAppInstance::DRIVER_EVOLUTION,
            'instance_name' => $validated['instance_name'],
            'display_name' => $validated['display_name'] ?? $validated['instance_name'],
            'status' => 'DISCONNECTED',
            'api_token' => $apiToken,
        ]);

        if ($evolutionOk) {
            try {
                $webhookUrl = config('app.url').'/webhook/evolution';
                $this->evolutionApi->setWebhook($validated['instance_name'], $webhookUrl);
            } catch (Throwable $e) {
                Log::warning('Evolution API setWebhook failed: '.$e->getMessage());
            }

            return redirect()
                ->route('instances.show', $instance)
                ->with('success', 'Evolution instance created. Scan the QR code to connect.');
        }

        return redirect()
            ->route('instances.show', $instance)
            ->with('warning', 'Instance saved, but Evolution API is unreachable. Error: '.$evolutionError);
    }
}
