<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailLog;
use App\Models\EmailSuppression;
use App\Services\EmailEvents\EmailEventParserFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Ingests provider bounce/complaint webhooks and adds the affected addresses to
 * the {@see EmailSuppression} list so the send pipeline stops emailing them —
 * automating what the manual suppressions UI does by hand.
 *
 * Auth is an unguessable secret in the URL path (config services.email_webhooks
 * .secret), compared in constant time and failing CLOSED: until the secret is
 * configured the endpoint reports 404, so it's inert on a fresh install and only
 * activates once you point a real provider at it. Providers that also sign their
 * payloads (SES, Mailgun, ...) verify that signature in their parser on top.
 *
 * This is unauthenticated-by-nature (a provider POSTs here), so the route also
 * carries the shared webhook abuse rate-limit + optional IP allowlist.
 */
class EmailWebhookController extends Controller
{
    public function handle(
        Request $request,
        string $provider,
        string $secret,
        EmailEventParserFactory $factory,
    ): Response {
        $configured = (string) config('services.email_webhooks.secret', '');

        // Fail closed: an unset OR too-short secret means the feature is off (or
        // not a real secret) — look absent. .env.example recommends rand -hex 32;
        // a <16-char value is never an intentional webhook secret.
        if (strlen($configured) < 16) {
            abort(404);
        }
        // Configured but wrong secret → auth failure.
        if (! hash_equals($configured, $secret)) {
            abort(403);
        }

        $parser = $factory->make($provider);
        if ($parser === null) {
            abort(404, "Unsupported email provider: {$provider}");
        }

        if (! $parser->verify($request)) {
            abort(403);
        }

        $suppressed = [];
        foreach ($parser->parse($request) as $event) {
            // Defensive: never let a malformed payload pollute the list with a
            // non-address (the parser reads a provider-supplied field verbatim).
            if (! filter_var($event->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            EmailSuppression::suppress($event->email, $event->suppressionReason());
            $normalized = EmailSuppression::normalize($event->email);
            $suppressed[] = $normalized;

            // Reconcile the recipient's EmailLog rows + campaign counters so a
            // hard bounce/complaint is not left looking "sent" (and later
            // "opened") — previously the provider reality and the dashboard
            // diverged permanently.
            $this->reconcileLogs($normalized, $event->suppressionReason());
        }

        if ($suppressed !== []) {
            // Audit trail: which addresses, from where — so a suspicious burst of
            // suppressions is diagnosable if the URL secret ever leaks.
            Log::info('Email webhook suppressed addresses', [
                'provider' => $provider,
                'ip' => $request->ip(),
                'emails' => $suppressed,
                'count' => count($suppressed),
            ]);
        }

        // Always 2xx on an authenticated, parseable request so the provider
        // doesn't retry — a non-suppressible event (soft bounce, open, ...) is a
        // successful no-op, not a failure.
        return response('ok', 200);
    }

    /**
     * Flip the affected EmailLog rows to the provider-reported reality and keep
     * the campaign counters consistent.
     *
     *   - a BOUNCE marks sent/opened logs FAILED and queued logs FAILED
     *   - a COMPLAINT marks them UNSUBSCRIBED (the recipient explicitly rejected)
     *
     * Counters: a log that was counted in sent_count (sent/opened) is decremented
     * when it stops being "sent"; failed_count is incremented only for bounces;
     * opened_count is decremented when an opened log is reconciled.
     */
    private function reconcileLogs(string $normalizedEmail, string $reason): void
    {
        $bounced = $reason === EmailSuppression::REASON_BOUNCE;

        EmailLog::where('email', $normalizedEmail)
            ->whereIn('status', [
                EmailLog::STATUS_QUEUED,
                EmailLog::STATUS_SENT,
                EmailLog::STATUS_OPENED,
            ])
            ->chunkById(200, function ($logs) use ($bounced): void {
                foreach ($logs as $log) {
                    $campaign = $log->campaign;
                    $wasSent = in_array($log->status, [EmailLog::STATUS_SENT, EmailLog::STATUS_OPENED], true);
                    $wasOpened = $log->status === EmailLog::STATUS_OPENED;

                    $log->update([
                        'status' => $bounced ? EmailLog::STATUS_FAILED : EmailLog::STATUS_UNSUBSCRIBED,
                        'error' => $bounced ? 'Hard bounce reported by provider' : 'Spam complaint reported by provider',
                    ]);

                    if ($campaign === null) {
                        continue;
                    }
                    if ($wasSent) {
                        $campaign->decrement('sent_count');
                        if ($wasOpened) {
                            $campaign->decrement('opened_count');
                        }
                        if ($bounced) {
                            $campaign->increment('failed_count');
                        }
                    }
                }
            });
    }
}
