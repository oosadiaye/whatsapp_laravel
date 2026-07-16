<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\WhatsAppApiException;
use App\Models\Setting;
use App\Models\WhatsAppInstance;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class SettingsController extends Controller
{
    public function __construct(private readonly WhatsAppCloudApiService $cloudApi)
    {
    }

    /**
     * Keys whose database value is ciphertext (encrypted via Crypt::encryptString
     * in {@see update()}). The form NEVER pre-fills these — it just shows a
     * "•••" placeholder when one is present — so the view only needs a boolean
     * "is set" signal, not the value. We replace the ciphertext with the literal
     * string '1' here so $settings[$key] stays truthy in the blade without ever
     * leaking ciphertext into the rendered HTML.
     *
     * If a future field reads $settings[<encrypted_key>] expecting plaintext,
     * it will get '1' instead — failing loudly instead of silently echoing
     * encrypted bytes into the page source.
     */
    private const ENCRYPTED_SETTING_KEYS = ['africastalking_api_key'];

    public function index(): View
    {
        $settings = Setting::all()->pluck('value', 'key');

        foreach (self::ENCRYPTED_SETTING_KEYS as $encryptedKey) {
            if ($settings->has($encryptedKey)) {
                $settings[$encryptedKey] = '1';
            }
        }

        return view('settings.index', [
            'settings' => $settings,
            // Single-instance app: the one WhatsApp number is configured here.
            'instance' => WhatsAppInstance::primary(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'default_rate_per_minute' => ['nullable', 'integer', 'min:1', 'max:60'],
            'default_delay_min' => ['nullable', 'integer', 'min:1', 'max:30'],
            'default_delay_max' => ['nullable', 'integer', 'min:1', 'max:60'],
            'round_robin_cap_per_agent' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'africastalking_username' => ['nullable', 'string', 'max:64'],
            'africastalking_api_key' => ['nullable', 'string', 'min:10', 'max:512'],
            'africastalking_virtual_number' => ['nullable', 'string', 'regex:/^\+\d{10,15}$/'],
            'africastalking_rate_per_minute_kobo' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        foreach ($validated as $key => $value) {
            if ($value === null || $value === '') {
                // Don't overwrite existing values with empty input — protects the API key
                // password field's "leave blank to keep existing" UX.
                continue;
            }

            if ($key === 'africastalking_api_key') {
                Setting::setEncrypted($key, (string) $value);
            } else {
                Setting::set($key, (string) $value);
            }
        }

        $warning = $this->upsertWhatsAppInstance($request);

        $redirect = redirect()->back()->with('success', 'Settings updated successfully.');

        return $warning !== null ? $redirect->with('warning', $warning) : $redirect;
    }

    /**
     * Upsert THE single WhatsApp Cloud API number from the Settings form.
     *
     * Single-instance app: there is exactly one WhatsAppInstance row and it is
     * configured here (not on a separate Instances page). Secrets follow the
     * same "leave blank to keep existing" rule as the AT key — the token/secret
     * are only written when the form actually carries a new value, and the model
     * encrypts them at rest.
     *
     * After saving, if the instance has the credentials to reach Meta, we probe
     * graph.facebook.com to VALIDATE them (this is the auto-verify the setup docs
     * describe — restored after the unify into Settings dropped it). Returns a
     * user-facing warning string when the probe fails, else null.
     */
    private function upsertWhatsAppInstance(Request $request): ?string
    {
        $data = $request->validate([
            'wa_display_name' => ['nullable', 'string', 'max:255'],
            'wa_phone_number_id' => ['nullable', 'string', 'max:64'],
            'wa_waba_id' => ['nullable', 'string', 'max:64'],
            'wa_access_token' => ['nullable', 'string', 'max:2048'],
            'wa_app_secret' => ['nullable', 'string', 'max:512'],
            'wa_webhook_verify_token' => ['nullable', 'string', 'max:255'],
        ]);

        $instance = WhatsAppInstance::primary();
        $anyProvided = collect($data)->contains(fn ($v) => filled($v));

        // Nothing to do: WhatsApp not configured yet and the form carried no
        // WhatsApp fields.
        if ($instance === null && ! $anyProvided) {
            return null;
        }

        if ($instance === null) {
            $instance = new WhatsAppInstance([
                'user_id' => auth()->id(),
                'instance_name' => 'primary-'.Str::random(8),
                'is_default' => true,
                'status' => WhatsAppInstance::STATUS_PENDING,
                'webhook_verify_token' => Str::random(32),
            ]);
        }

        // Non-secret fields — set only when provided (blank leaves existing).
        foreach ([
            'display_name' => 'wa_display_name',
            'phone_number_id' => 'wa_phone_number_id',
            'waba_id' => 'wa_waba_id',
            'webhook_verify_token' => 'wa_webhook_verify_token',
        ] as $column => $field) {
            if (filled($data[$field] ?? null)) {
                $instance->{$column} = $data[$field];
            }
        }

        // Secrets — "leave blank to keep existing"; model encrypts at rest.
        if (filled($data['wa_access_token'] ?? null)) {
            $instance->access_token = $data['wa_access_token'];
        }
        if (filled($data['wa_app_secret'] ?? null)) {
            $instance->app_secret = $data['wa_app_secret'];
        }

        $instance->save();

        return $this->probeInstance($instance);
    }

    /**
     * Validate the saved credentials against Meta and record the outcome.
     * Success → CONNECTED (+ auto-filled phone number / verified name / quality).
     * Meta rejection → CREDENTIALS_INVALID. Network failure → UNREACHABLE.
     * Returns a warning message on failure, null on success / not-yet-ready.
     */
    private function probeInstance(WhatsAppInstance $instance): ?string
    {
        if (! $instance->isReady()) {
            return null; // not enough credentials to talk to Meta yet
        }

        try {
            $info = $this->cloudApi->getPhoneNumberInfo($instance);

            $update = [
                'business_phone_number' => $info['display_phone_number'] ?? $instance->business_phone_number,
                'quality_rating' => $info['quality_rating'] ?? $instance->quality_rating,
                'messaging_limit_tier' => $info['messaging_limit_tier'] ?? $instance->messaging_limit_tier,
                'status' => WhatsAppInstance::STATUS_CONNECTED,
            ];
            // Auto-fill the display name from Meta's verified name only when the
            // operator left it blank (the docs say "leave blank, auto-fills").
            if (blank($instance->display_name) && filled($info['verified_name'] ?? null)) {
                $update['display_name'] = $info['verified_name'];
            }
            $instance->update($update);

            return null;
        } catch (WhatsAppApiException $e) {
            $instance->update(['status' => 'CREDENTIALS_INVALID']);

            return 'WhatsApp saved, but Meta rejected the credentials: '.$e->getMessage();
        } catch (Throwable $e) {
            Log::warning('WhatsApp credential probe failed', ['error' => $e->getMessage()]);
            $instance->update(['status' => 'UNREACHABLE']);

            return 'WhatsApp saved, but graph.facebook.com could not be reached to verify the credentials.';
        }
    }
}
