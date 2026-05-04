<?php

declare(strict_types=1);

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Translate a Meta Cloud Calling API error message into a user-safe
     * flash message that explains the cause without leaking response
     * body internals (tokens, customer IDs, etc).
     *
     * Used by ContactController::startCall and ConversationController::initiateCall.
     * The full untransformed message is logged via Log::error at the
     * call site so support can still see the raw response.
     *
     * Falls back to the generic 'Could not place call' message when no
     * known error code matches. Adding a new annotation: bump the
     * $hints array — that's the single source of truth.
     */
    protected function userFacingCallError(string $rawMessage): string
    {
        $hints = [
            // 138013 — Business-Initiated Calling (BIC) not enabled.
            // The WABA has Cloud Calling for inbound (customers calling
            // you) but Business-Initiated Calling — where YOUR side
            // initiates the call — is a separate Meta enrollment program
            // in limited GA. Most likely error for newly-set-up accounts
            // that activated Calling on the dashboard but didn't apply
            // for BIC specifically. Requires separate request to Meta
            // Business support / Solutions Provider.
            '138013' => 'Business-Initiated Calling is not enabled on this WhatsApp Business Account. '
                .'Cloud Calling lets customers call you, but YOU calling them is a separate Meta '
                .'program. Apply via Meta Business Support: WhatsApp Manager → Account Tools → '
                .'Calling → Business-initiated calls. (Currently a limited-rollout feature.)',

            // 138015 — Calling not approved at all (broader rejection
            // than 138013; covers accounts where neither inbound nor
            // outbound Cloud Calling is enabled).
            '138015' => 'This WhatsApp Business Account isn\'t approved for Cloud Calling at all. '
                .'Check your Meta Business dashboard → WhatsApp → Phone Numbers → Calling and '
                .'enable the feature on this number first.',

            // 138001 — recipient can't be called. They haven't opted in
            // to receive calls (recent inbound message) or the customer
            // is in a region that blocks Meta calls.
            '138001' => 'This contact is not callable. They must have messaged you within the '
                .'last 24 hours to be eligible for an outbound call (Meta opt-in policy).',

            // 138012 — the recipient phone number is not registered with
            // WhatsApp at all, OR the format is invalid.
            '138012' => 'Recipient is not a WhatsApp user, or the phone number format is invalid. '
                .'Verify the number includes the country code in E.164 format.',

            // 131056 — pair-rate limit. Same business+customer being
            // contacted too frequently.
            '131056' => 'Hit Meta\'s rate limit for this contact. Wait a few minutes before trying again.',

            // 131000 — generic system error, retry usually works.
            '131000' => 'Meta returned a temporary system error. Try again in a moment.',

            // 100 — invalid parameter. Usually our payload shape is wrong.
            'invalid_parameter' => 'Meta rejected the request as invalid. The Cloud Calling API '
                .'endpoint may have changed; check the server log for the full response.',
        ];

        // Cast key to string: PHP auto-converts numeric-looking string array
        // keys to ints, and str_contains() rejects non-string needles strictly.
        foreach ($hints as $needle => $hint) {
            if (str_contains($rawMessage, (string) $needle)) {
                return $hint;
            }
        }

        return 'Could not place call. Please try again or check the server log for the Meta error code.';
    }
}
