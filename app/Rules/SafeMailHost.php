<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects mail hosts that point at internal infrastructure.
 *
 * The mailbox connect/update flow opens a real TCP connection to whatever
 * IMAP/SMTP host the user supplies, so an unvalidated host turns the form into
 * an SSRF vector / internal port scanner (H1). This blocks loopback, link-local
 * (including 169.254.169.254 cloud metadata), and RFC1918/ULA private ranges —
 * both as IP literals and, outside the test environment, as hostnames that
 * resolve into those ranges.
 *
 * Note: this does not fully defeat DNS-rebinding (a hostname re-resolved to an
 * internal IP at socket time); that would require pinning the validated IP down
 * in the PHPIMAP transport. Input validation plus the generic connect-error
 * message (no reflected exception text) closes the practical oracle.
 */
class SafeMailHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // required/string rules handle emptiness
        }

        // Normalise: strip an accidental scheme, path, or :port a user may paste.
        $host = preg_replace('#^[a-z]+://#i', '', trim($value)) ?? '';
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];

        if ($host === '') {
            return;
        }

        // IP literal — check directly, in every environment.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $this->isPublicIp($host)) {
                $fail('The :attribute points at a private or reserved address.');
            }
            return;
        }

        // Hostname — resolve and check every resolved IP. Skipped under testing
        // to keep the suite deterministic and free of network calls.
        if (app()->environment('testing')) {
            return;
        }

        foreach ($this->resolve($host) as $ip) {
            if (! $this->isPublicIp($ip)) {
                $fail('The :attribute resolves to a private or reserved address.');
                return;
            }
        }
    }

    private function isPublicIp(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
    }

    /**
     * Resolve a hostname to its A and AAAA addresses.
     *
     * @return array<int, string>
     */
    private function resolve(string $host): array
    {
        $ips = [];

        $a = @gethostbynamel($host);
        if (is_array($a)) {
            $ips = array_merge($ips, $a);
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (! empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }

        return $ips;
    }
}
