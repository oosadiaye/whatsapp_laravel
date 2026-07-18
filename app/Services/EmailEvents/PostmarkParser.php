<?php

declare(strict_types=1);

namespace App\Services\EmailEvents;

use Illuminate\Http\Request;

/**
 * Parses Postmark's Bounce + SpamComplaint webhooks
 * (https://postmarkapp.com/developer/webhooks). Postmark posts one JSON object
 * per event; it authenticates via the unguessable URL secret (checked by the
 * controller), so there is no separate signature to verify.
 */
final class PostmarkParser implements EmailEventParser
{
    /**
     * Postmark "Type" values that mean the address is permanently undeliverable.
     * Soft/Transient/DnsError/etc. recover, so they are deliberately excluded —
     * suppressing them would drop deliverable contacts.
     */
    private const PERMANENT_BOUNCE_TYPES = [
        'HardBounce',
        'BadEmailAddress',
        'ManuallyDeactivated',
        'Blocked',
    ];

    public function verify(Request $request): bool
    {
        return true;
    }

    public function parse(Request $request): array
    {
        $recordType = (string) $request->input('RecordType', '');
        $email = trim((string) $request->input('Email', ''));

        if ($email === '') {
            return [];
        }

        if (strcasecmp($recordType, 'SpamComplaint') === 0) {
            return [new EmailEvent($email, EmailEvent::TYPE_COMPLAINT, 'postmark:SpamComplaint')];
        }

        if (strcasecmp($recordType, 'Bounce') === 0) {
            $type = (string) $request->input('Type', '');
            if (in_array($type, self::PERMANENT_BOUNCE_TYPES, true)) {
                return [new EmailEvent($email, EmailEvent::TYPE_BOUNCE, 'postmark:'.$type)];
            }
        }

        return [];
    }
}
