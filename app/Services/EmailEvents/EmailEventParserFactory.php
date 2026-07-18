<?php

declare(strict_types=1);

namespace App\Services\EmailEvents;

/**
 * Resolves a provider slug (the {provider} path segment) to its parser. This is
 * the single place a new provider is registered — add a case and a parser class.
 */
final class EmailEventParserFactory
{
    public function make(string $provider): ?EmailEventParser
    {
        return match (strtolower($provider)) {
            'postmark' => new PostmarkParser(),
            // 'ses'      => new SesParser(),      // SNS signature + subscription confirm
            // 'mailgun'  => new MailgunParser(),  // HMAC(timestamp+token)
            // 'sendgrid' => new SendgridParser(), // ECDSA signed events
            default => null,
        };
    }
}
