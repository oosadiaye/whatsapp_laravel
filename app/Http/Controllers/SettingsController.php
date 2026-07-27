<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\WhatsAppApiException;
use App\Models\Setting;
use App\Models\WhatsAppInstance;
use App\Services\WhatsAppCloudApiService;
use App\Support\GeminiConfig;
use App\Support\MailConfig;
use App\Support\VoiceConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
    private const ENCRYPTED_SETTING_KEYS = ['africastalking_api_key', 'gemini_api_key', 'mail_password'];

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
            // Whether the Call Workspace AI has a usable key (Settings or env).
            'geminiConfigured' => filled(GeminiConfig::key()),
            // Compliance-sensitive call recording (pre-fills the toggle + notice).
            'recordingEnabled' => VoiceConfig::recordingEnabled(),
            'recordingConsent' => VoiceConfig::recordingConsentNotice() ?? '',
            'recordingRetentionDays' => VoiceConfig::recordingRetentionDays(),
            // Bulk-email transport: the effective mailer + whether it delivers,
            // so the SMTP card can show an honest status without leaking secrets.
            'mailMailer' => MailConfig::mailer(),
            'mailDelivering' => MailConfig::isDelivering(),
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
            'gemini_api_key' => ['nullable', 'string', 'min:10', 'max:512'],
            // Bulk-email (SMTP) transport — drives campaign delivery. mail_password
            // is encrypted at rest (see ENCRYPTED_SETTING_KEYS) and follows the
            // "leave blank to keep existing" rule via the skip-empty loop below.
            'mail_mailer' => ['nullable', 'in:smtp,log'],
            'mail_host' => ['nullable', 'string', 'max:255'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_encryption' => ['nullable', 'in:tls,ssl,starttls,none'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:512'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
            // Recording is compliance-sensitive: you can't turn it on without
            // stating a consent notice.
            'voice_recording_enabled' => ['nullable', 'boolean'],
            'voice_recording_consent_notice' => ['nullable', 'required_if:voice_recording_enabled,1', 'string', 'max:1000'],
            // Retention: days to keep recording audio (0 = keep forever). ~10y cap.
            'voice_recording_retention_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);

        // The recording toggle + its consent notice are handled explicitly — a
        // checkbox doesn't submit when unchecked, so the "skip empty" loop below
        // can't persist an OFF, and clearing the notice must actually clear it.
        Setting::set('voice_recording_enabled', $request->boolean('voice_recording_enabled') ? '1' : '0');
        Setting::set('voice_recording_consent_notice', (string) $request->input('voice_recording_consent_notice', ''));
        unset($validated['voice_recording_enabled'], $validated['voice_recording_consent_notice']);

        // The mail transport is a select whose empty value means "use the .env
        // default". Handle it explicitly (not via the skip-empty loop) so that
        // choosing "Use .env default" actually clears a prior DB override — and
        // so an unrelated settings save never persists an accidental "smtp" with
        // no host that would silently hijack delivery. '' → MailConfig treats it
        // as unconfigured and env config stands.
        Setting::set('mail_mailer', (string) $request->input('mail_mailer', ''));
        unset($validated['mail_mailer']);

        foreach ($validated as $key => $value) {
            if ($value === null || $value === '') {
                // Don't overwrite existing values with empty input — protects the API key
                // password field's "leave blank to keep existing" UX.
                continue;
            }

            if (in_array($key, self::ENCRYPTED_SETTING_KEYS, true)) {
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
     * Send a one-off test email through the SAVED transport so an operator can
     * verify their SMTP settings actually deliver — the antidote to a "configured
     * but silently broken" transport (audit M11). Uses the persisted settings, so
     * save the SMTP card first, then test. Any transport error is surfaced as a
     * flash message rather than swallowed.
     */
    public function sendTestEmail(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'test_email' => ['nullable', 'email', 'max:255'],
        ]);

        $to = $data['test_email'] ?? $request->user()?->email;
        if (blank($to)) {
            return redirect()->back()->with('error', 'No address to send the test to — add one and try again.');
        }

        // Deliver through whatever the operator saved on this page (DB), not just
        // the .env default. Mirrors the campaign send path.
        MailConfig::apply();

        if (! MailConfig::isDelivering()) {
            return redirect()->back()->with('warning',
                'Test not sent: the mail transport is "'.MailConfig::mailer().'", which does not deliver. '
                .'Choose SMTP and save before testing.');
        }

        try {
            Mail::raw(
                "This is a test email from BlastIQ.\n\nIf you received it, your outbound email settings are working.",
                function ($message) use ($to): void {
                    $message->to($to)->subject('BlastIQ — test email');
                },
            );
        } catch (Throwable $e) {
            Log::warning('Settings test email failed', ['error' => $e->getMessage()]);

            return redirect()->back()->with('error', 'Test email failed: '.Str::limit($e->getMessage(), 300));
        }

        return redirect()->back()->with('success', 'Test email sent to '.$to.'. Check that inbox to confirm delivery.');
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
