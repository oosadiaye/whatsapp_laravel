<?php

declare(strict_types=1);

namespace App\Services\MailClient;

/**
 * Reduces an attachment filename to a SAFE basename before it is used to build a
 * storage path (plan B5b hardening / pre-merge security review).
 *
 * The name is attacker-controlled — inbound it comes from a remote sender's MIME
 * part, outbound from a browser upload's client name — so a value like
 * `../../evil` or `/etc/x` must never reach `Storage::put()`/`storeAs()`. Beyond
 * the traversal risk, an unsanitised `..` makes Flysystem THROW, and since the
 * inbound sync loop has no per-message guard that exception aborts the whole run
 * and the cursor never advances — one crafted email would wedge an employee's
 * inbox sync permanently. Stripping to a basename removes both.
 */
final class AttachmentName
{
    public static function safe(string $name): string
    {
        // Normalise both separators so a Windows-style `..\\..` can't slip past
        // basename() (which only splits on '/').
        $base = basename(str_replace('\\', '/', $name));

        // No leading dots (`.`, `..`, `.htaccess`) and no control characters.
        $base = ltrim($base, '.');
        $base = preg_replace('/[\x00-\x1F]/', '', $base) ?? '';
        $base = trim($base);

        return $base !== '' ? $base : 'attachment';
    }
}
