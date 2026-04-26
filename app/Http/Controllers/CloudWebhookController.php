<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\MessageLog;
use App\Models\WhatsAppInstance;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Webhook handler for Meta WhatsApp Cloud API.
 *
 * Two endpoints:
 *   - GET  /webhooks/whatsapp/{instance}  → verification handshake
 *   - POST /webhooks/whatsapp/{instance}  → event delivery (status updates, inbound messages)
 *
 * Per-instance routing lets each customer use their own Meta App with its own
 * `app_secret` (for signature checks) and `webhook_verify_token` (for the
 * subscription handshake). The instance is resolved from the URL parameter,
 * not from the payload, so a single misconfigured customer can't poison
 * another tenant's webhook flow.
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks
 */
class CloudWebhookController extends Controller
{
    /**
     * Meta calls this once when the webhook URL is added or modified in the
     * App dashboard. We must echo `hub.challenge` (as plain text, not JSON)
     * if and only if `hub.verify_token` matches what we registered.
     */
    public function verify(Request $request, WhatsAppInstance $instance): Response
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        $expected = (string) $instance->webhook_verify_token;

        if ($mode !== 'subscribe' || $expected === '' || ! hash_equals($expected, $token)) {
            Log::warning('Cloud webhook verify failed', [
                'instance_id' => $instance->id,
                'mode' => $mode,
                'token_provided' => $token !== '',
            ]);

            return response('Forbidden', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Process an inbound webhook event.
     *
     * Validates the X-Hub-Signature-256 header before doing anything else.
     * Payloads with statuses[] update MessageLog; inbound messages[] are
     * acknowledged but not yet stored (Phase 6+).
     */
    public function handle(Request $request, WhatsAppInstance $instance): Response
    {
        if (! $this->verifySignature($request, $instance)) {
            Log::warning('Cloud webhook signature mismatch', [
                'instance_id' => $instance->id,
            ]);

            return response('Invalid signature', 403);
        }

        $payload = $request->json()->all();

        // Meta always wraps under entry[].changes[].value
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = (array) ($change['value'] ?? []);
                $this->processStatuses($value['statuses'] ?? []);
                // Inbound `messages[]` handling deferred — reply / inbox features land later.
            }
        }

        return response('OK', 200);
    }

    /**
     * Walk a statuses[] array and apply each one to its MessageLog.
     *
     * @param  array<int, array<string, mixed>>  $statuses
     */
    private function processStatuses(array $statuses): void
    {
        foreach ($statuses as $status) {
            $messageId = (string) ($status['id'] ?? '');
            $rawStatus = strtolower((string) ($status['status'] ?? ''));

            if ($messageId === '' || $rawStatus === '') {
                continue;
            }

            $log = MessageLog::where('whatsapp_message_id', $messageId)->first();

            if ($log === null) {
                continue;
            }

            $mappedStatus = $this->mapStatus($rawStatus);
            $updates = ['status' => $mappedStatus];

            // Meta gives a unix timestamp string; prefer it over now() for accuracy.
            $occurredAt = isset($status['timestamp'])
                ? Carbon::createFromTimestamp((int) $status['timestamp'])
                : Carbon::now();

            match ($mappedStatus) {
                'DELIVERED' => $updates['delivered_at'] = $occurredAt,
                'READ' => $updates['read_at'] = $occurredAt,
                'SENT' => $updates['sent_at'] = $log->sent_at ?? $occurredAt,
                'FAILED' => $updates['error_message'] = $this->extractErrorMessage($status),
                default => null,
            };

            $log->update($updates);

            $this->updateCampaignCounters($log->campaign, $mappedStatus);
        }
    }

    private function updateCampaignCounters(?Campaign $campaign, string $mappedStatus): void
    {
        if ($campaign === null) {
            return;
        }

        match ($mappedStatus) {
            'DELIVERED' => $campaign->increment('delivered_count'),
            'READ' => $campaign->increment('read_count'),
            'FAILED' => $campaign->increment('failed_count'),
            default => null,
        };
    }

    /**
     * Validate Meta's HMAC-SHA256 signature.
     *
     * Header format: "X-Hub-Signature-256: sha256=<hex>"
     * Computation:    hmac_sha256(raw_body, app_secret)
     *
     * Uses hash_equals() for timing-safe comparison.
     */
    private function verifySignature(Request $request, WhatsAppInstance $instance): bool
    {
        $secret = (string) $instance->app_secret;

        if ($secret === '') {
            // No secret configured → fail closed in production. Log so misconfig is visible.
            Log::warning('Cloud webhook has no app_secret configured', [
                'instance_id' => $instance->id,
            ]);

            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $providedHex = substr($header, 7);
        $expectedHex = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expectedHex, $providedHex);
    }

    /**
     * Cloud API status names → our normalized enum used by MessageLog.
     */
    private function mapStatus(string $rawStatus): string
    {
        return match ($rawStatus) {
            'sent' => 'SENT',
            'delivered' => 'DELIVERED',
            'read' => 'READ',
            'failed' => 'FAILED',
            default => strtoupper($rawStatus),
        };
    }

    /**
     * Pull the human-readable error from a failed status payload.
     *
     * @param  array<string, mixed>  $status
     */
    private function extractErrorMessage(array $status): string
    {
        $errors = (array) ($status['errors'] ?? []);
        $first = $errors[0] ?? null;

        if (! is_array($first)) {
            return 'Unknown failure';
        }

        return (string) (
            $first['message']
            ?? $first['title']
            ?? $first['error_data']['details']
            ?? 'Unknown failure'
        );
    }
}
