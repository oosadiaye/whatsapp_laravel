<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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

        // Fail closed: no secret configured → the feature is off, look absent.
        if ($configured === '') {
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

        $suppressed = 0;
        foreach ($parser->parse($request) as $event) {
            EmailSuppression::suppress($event->email, $event->suppressionReason());
            $suppressed++;
        }

        if ($suppressed > 0) {
            Log::info('Email webhook suppressed addresses', [
                'provider' => $provider,
                'count' => $suppressed,
            ]);
        }

        // Always 2xx on an authenticated, parseable request so the provider
        // doesn't retry — a non-suppressible event (soft bounce, open, ...) is a
        // successful no-op, not a failure.
        return response('ok', 200);
    }
}
