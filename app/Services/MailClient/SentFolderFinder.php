<?php

declare(strict_types=1);

namespace App\Services\MailClient;

/**
 * Picks the "Sent" folder out of a mailbox's folder list. IMAP has no universal name for it
 * (Gmail: "[Gmail]/Sent Mail", Outlook: "Sent Items", cPanel/Dovecot: "Sent" or "INBOX.Sent"),
 * so match on the folder's last path segment, most specific names first.
 */
final class SentFolderFinder
{
    /** @var list<string> lower-case, tried in this order */
    private const NAMES = ['sent', 'sent items', 'sent mail', 'sent messages', 'sent-mail'];

    /**
     * @param  list<string>  $paths  folder paths exactly as the server reports them
     */
    public static function pick(array $paths): ?string
    {
        foreach (self::NAMES as $name) {
            foreach ($paths as $path) {
                if (self::lastSegment($path) === $name) {
                    return $path;
                }
            }
        }

        return null;
    }

    private static function lastSegment(string $path): string
    {
        $segments = preg_split('#[/.\\\\]#', $path) ?: [$path];

        return mb_strtolower(trim((string) end($segments)));
    }
}
