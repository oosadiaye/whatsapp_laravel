<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ConfigurationException;
use App\Exceptions\VoiceProviderException;
use App\Models\Setting;
use App\Models\User;
use App\Services\AfricasTalkingVoiceService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `php artisan voice:verify-live` — the Phase 0 gate runner.
 *
 * The Africa's Talking voice integration was built against AT's docs, not a
 * live account (see docs/AFRICASTALKING-VERIFICATION.md). This command runs
 * every prerequisite check that can be automated WITHOUT a live call, then —
 * when a --to number is given — places a real test outbound call and prints
 * the ordered manual checklist for the parts only live traffic can confirm.
 *
 * Exit codes: 0 = all hard checks pass (WARNings remain, follow the checklist),
 *             1 = at least one hard check failed (blocking).
 */
class VerifyVoiceLive extends Command
{
    protected $signature = 'voice:verify-live
        {--to= : Optional customer number to place a real test outbound call to (E.164, e.g. +2348012345678)}
        {--no-call : Skip placing the test call even when --to is passed}';

    protected $description = 'Phase 0 gate: verify the Africa\'s Talking voice integration against a live account';

    public function handle(AfricasTalkingVoiceService $voice): int
    {
        $this->newLine();
        $this->info('▸ Africa\'s Talking Voice — Phase 0 live verification');
        $this->line('Full walkthrough: docs/AFRICASTALKING-VERIFICATION.md');
        $this->newLine();

        $failures = 0;

        // ── Prerequisites (automated, no live call required) ────────────────
        $this->section('Prerequisites (automated)');

        $failures += $this->check(
            'Africa\'s Talking username is set (Settings → Africa\'s Talking)',
            fn () => filled(Setting::get('africastalking_username')),
            hard: true,
        );
        $failures += $this->check(
            'Africa\'s Talking API key is set',
            fn () => filled(Setting::getEncrypted('africastalking_api_key')),
            hard: true,
        );
        $failures += $this->check(
            'Virtual number is set and valid (+E.164, max 15 digits)',
            fn () => (bool) preg_match('/^\+\d{10,15}$/', (string) Setting::get('africastalking_virtual_number', '')),
            hard: true,
        );
        $failures += $this->check(
            'AT voice webhook secret configured (AT_VOICE_WEBHOOK_SECRET) — the webhook fails CLOSED without it',
            fn () => filled(config('voice.at_webhook_secret')),
            hard: true,
        );
        $failures += $this->check(
            'Voice webhook route registered (webhook.africastalking.voice)',
            fn () => app('router')->has('webhook.africastalking.voice'),
            hard: true,
        );
        $failures += $this->check(
            'Broadcast driver is "reverb" (BROADCAST_CONNECTION=reverb) — without it no banners appear',
            fn () => config('broadcasting.default') === 'reverb',
            hard: true,
        );
        $this->check(
            'Queue driver is not "sync" (QUEUE_CONNECTION) — sync runs jobs inline and never retries provider hangs-up',
            fn () => config('queue.default') !== 'sync',
            hard: false,
        );
        $this->check(
            'APP_URL is publicly reachable (not localhost/127.0.0.1) — AT must reach your webhook',
            fn () => ! preg_match('#^https?://(localhost|127\.0\.0\.1|0\.0\.0\.0)#i', (string) config('app.url', '')),
            hard: false,
        );
        $this->checkReverbReachable();

        // Capability token minting — needs an active agent; hits AT in prod.
        $this->checkCapabilityToken($voice);

        // ── Optional: place a real test outbound call ───────────────────────
        $to = (string) $this->option('to');
        if ($to !== '' && ! (bool) $this->option('no-call')) {
            $this->newLine();
            $this->section('Live test outbound call');
            try {
                $sessionId = $voice->placeCall($to);
                $this->info("  ✓ Test call placed — AT sessionId: {$sessionId}");
                $this->line('  The customer phone should ring. On answer, AT requests the voice');
                $this->line('  callback; the webhook must return <Dial agent_{userId}/> and the');
                $this->line('  agent\'s browser softphone must receive the bridged leg.');
            } catch (ConfigurationException $e) {
                $this->error("  ✗ Not configured: {$e->getMessage()}");
                $failures++;
            } catch (VoiceProviderException $e) {
                $this->error("  ✗ AT rejected the call: {$e->getMessage()}");
                $failures++;
            } catch (InvalidArgumentException $e) {
                $this->error("  ✗ Bad number: {$e->getMessage()}");
                $failures++;
            }
        } elseif ($to !== '') {
            $this->line('  Skipping test call (--no-call).');
        }

        // ── Manual checklist (only live traffic can confirm these) ──────────
        $this->printManualChecklist();

        $this->newLine();
        if ($failures > 0) {
            $this->error("  ✗ {$failures} blocking prerequisite(s) failed — fix these before going live.");

            return self::FAILURE;
        }

        $this->info('  ✓ All automated prerequisites pass. Work the manual checklist below against a live account.');

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line("  <options=bold>{$title}</>");
    }

    /**
     * Print a single ✓/⚠/✗ check result. Returns 1 when a HARD check fails so
     * the caller can accumulate a failure count.
     */
    private function check(string $label, callable $passes, bool $hard): int
    {
        try {
            $ok = (bool) $passes();
        } catch (\Throwable $e) {
            $ok = false;
        }

        if ($ok) {
            $this->line("  <fg=green>✓</> {$label}");

            return 0;
        }

        if ($hard) {
            $this->line("  <fg=red>✗</> {$label}");

            return 1;
        }

        $this->line("  <fg=yellow>⚠</> {$label}");

        return 0;
    }

    /**
     * Probe the Reverb websocket port the BROWSER connects to. WARN-only: a
     * local dev box frequently has no Reverb daemon, and the port can be
     * firewalled even when the daemon is healthy.
     */
    private function checkReverbReachable(): void
    {
        $host = (string) config('broadcasting.connections.reverb.options.host', '');
        $port = (int) config('broadcasting.connections.reverb.options.port', 0);

        $reachable = $host !== '' && $port > 0 && @fsockopen($host, $port, $errno, $errstr, 1) !== false;

        $this->check(
            "Reverb reachable on {$host}:{$port} (browser websocket port; start `php artisan reverb:start`)",
            fn () => $reachable,
            hard: false,
        );
    }

    /**
     * Attempt to mint a real AT capability token for an active agent. This is
     * the exact call the browser softphone depends on to register. WARN-only
     * because it needs a live account — in the testing env it resolves to a
     * local stub by design.
     */
    private function checkCapabilityToken(AfricasTalkingVoiceService $voice): void
    {
        $agent = User::query()
            ->where('role', User::ROLE_AGENT)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($agent === null) {
            $this->check(
                'Capability token mints for an active agent (none found to test with — create an active agent)',
                fn () => false,
                hard: false,
            );

            return;
        }

        try {
            $token = $voice->generateClientToken($agent);
            $this->check(
                "Capability token mints for agent_{$agent->id} (real token = softphone can register)",
                fn () => is_string($token) && $token !== '',
                hard: false,
            );
        } catch (\Throwable $e) {
            $this->check(
                "Capability token mints for agent_{$agent->id} ({$e->getMessage()})",
                fn () => false,
                hard: false,
            );
        }
    }

    private function printManualChecklist(): void
    {
        $this->newLine();
        $this->section('Manual checklist (live call required — work top-to-bottom)');

        $steps = [
            '1. Webhook auth: set AT\'s voice callback URL to /webhooks/africastalking/voice/<AT_VOICE_WEBHOOK_SECRET>. Place/receive a call and confirm NO 401s in laravel.log.',
            '2. Callback fields: set VOICE_DEBUG_LOG_WEBHOOKS=true, make a call, and read the captured payload in laravel.log. Confirm sessionId / direction / status / isActive / callerNumber / destinationNumber / durationInSeconds match the code (AfricasTalkingWebhookController::handle).',
            '3. Status strings: confirm AT sends exactly Ringing / InProgress / Completed / Failed (case-sensitive) and map any extras (Busy, NoAnswer, …) in handle().',
            '4. Outbound bridge: place the test call (--to). Confirm the customer rings, the agent\'s browser softphone receives the bridged leg, and two-way audio flows. Confirm provider_session_id matches AT\'s sessionId.',
            '5. Inbound: dial the virtual number from your mobile. Confirm an available agent is round-robin-assigned and their browser rings with an Accept/Decline banner.',
            '6. Server hangup: hang up in the browser and confirm BOTH legs drop (call your own mobile, then verify it ends). If the customer leg stays up, AfricasTalkingVoiceService::endCall posts the wrong endpoint (/queueStatus is doc-guessed) — replace it with AT\'s live-call termination.',
            '7. Hold / Keypad (DTMF): on a live call, confirm hold+resume and that DTMF digits reach the far end (bqVoiceClient.hold/unhold/dtmf).',
            '8. Cost: confirm AT\'s Completed callback includes amount/currency. If so, persist the real amount and use the flat estimate only as a fallback (AfricasTalkingWebhookController::finalizeCall).',
            '9. Flag-gated flows (enable ONE at a time, verify each): IVR (VOICE_IVR_ENABLED), voicemail (VOICE_VOICEMAIL_ENABLED), business hours (VOICE_BUSINESS_HOURS_ENABLED), queue (VOICE_QUEUE_ENABLED — dequeue/agent-pull is NOT built), blind transfer (VOICE_TRANSFER_ENABLED — depends on AT re-requesting control). Steps in docs/CALL-FLOW.md.',
        ];

        foreach ($steps as $step) {
            $this->line("  <fg=cyan>[ ]</> {$step}");
        }

        $this->line('');
        $this->line('  Definition of done: a test call EACH direction that rings the right agent, connects');
        $this->line('  two-way audio, shows correct status/timer, supports mute/hold/keypad, and FULLY');
        $this->line('  terminates both legs on hang up — with no auth 401s in the log.');
    }
}
