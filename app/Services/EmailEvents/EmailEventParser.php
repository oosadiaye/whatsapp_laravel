<?php

declare(strict_types=1);

namespace App\Services\EmailEvents;

use Illuminate\Http\Request;

/**
 * Translates one email provider's bounce/complaint webhook into normalized
 * {@see EmailEvent}s. Adding a provider (SES SNS, Mailgun, SendGrid, ...) is a
 * single class implementing this interface, registered in
 * {@see EmailEventParserFactory}.
 */
interface EmailEventParser
{
    /**
     * Provider-specific request authentication BEYOND the URL path secret the
     * controller already checks — e.g. an HMAC signature (Mailgun) or an SNS
     * message signature (SES). Providers that authenticate purely via the URL
     * secret (Postmark) return true.
     */
    public function verify(Request $request): bool;

    /**
     * Extract the suppressible events from the payload. Only permanent bounces
     * and complaints are returned; everything else yields an empty array.
     *
     * @return array<int, EmailEvent>
     */
    public function parse(Request $request): array;
}
