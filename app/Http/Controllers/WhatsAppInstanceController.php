<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WhatsAppInstance;
use App\Services\EvolutionApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class WhatsAppInstanceController extends Controller
{
    public function __construct(
        private readonly EvolutionApiService $evolutionApi,
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
            Log::warning('Evolution API createInstance failed: ' . $e->getMessage());
        }

        $instance = WhatsAppInstance::create([
            'user_id' => auth()->id(),
            'instance_name' => $validated['instance_name'],
            'display_name' => $validated['display_name'] ?? $validated['instance_name'],
            'status' => 'DISCONNECTED',
            'api_token' => $apiToken,
        ]);

        if ($evolutionOk) {
            try {
                $webhookUrl = config('app.url') . '/webhook/evolution';
                $this->evolutionApi->setWebhook($validated['instance_name'], $webhookUrl);
            } catch (Throwable $e) {
                Log::warning('Evolution API setWebhook failed: ' . $e->getMessage());
            }

            return redirect()
                ->route('instances.show', $instance)
                ->with('success', 'Instance created successfully.');
        }

        return redirect()
            ->route('instances.show', $instance)
            ->with('warning', 'Instance saved, but Evolution API is unreachable. Check your settings. Error: ' . $evolutionError);
    }

    public function show(string $id): View
    {
        $instance = WhatsAppInstance::where('user_id', auth()->id())
            ->findOrFail($id);

        $status = $instance->status;
        try {
            $status = $this->evolutionApi->getInstanceStatus($instance->instance_name);
        } catch (Throwable $e) {
            Log::warning('Evolution API getInstanceStatus failed: ' . $e->getMessage());
        }

        return view('instances.show', [
            'instance' => $instance,
            'status' => $status,
        ]);
    }

    public function destroy(string $id): RedirectResponse
    {
        $instance = WhatsAppInstance::where('user_id', auth()->id())
            ->findOrFail($id);

        try {
            $this->evolutionApi->deleteInstance($instance->instance_name);
        } catch (Throwable $e) {
            Log::warning('Evolution API deleteInstance failed: ' . $e->getMessage());
        }

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

    public function qrStatus(string $id): JsonResponse
    {
        $instance = WhatsAppInstance::where('user_id', auth()->id())
            ->findOrFail($id);

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
            Log::warning('Evolution API qrStatus failed: ' . $e->getMessage());
        }

        return response()->json([
            'status' => $status,
            'qr_code' => $qrCode,
            'error' => $error,
        ]);
    }
}
