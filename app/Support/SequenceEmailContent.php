<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Builds the per-recipient tracking content for an automated sequence email:
 * the open-tracking pixel, click-through link wrapping, and the one-click
 * unsubscribe URL. Every URL is a signed route so it can't be forged.
 *
 * Kept as a pure static helper so the rewriting logic is directly unit-testable
 * without exercising the queue/mail pipeline.
 */
class SequenceEmailContent
{
    public static function bodyHtml(?string $bodyHtml, int $recipientId): string
    {
        $html = (string) $bodyHtml;

        if ($html !== '') {
            $html = self::rewriteLinks($html, $recipientId);
            $html .= '<img src="'.e(self::pixelUrl($recipientId)).'" width="1" height="1" alt="" style="display:none">';
        }

        return $html;
    }

    public static function pixelUrl(int $recipientId): string
    {
        return URL::signedRoute('email.sequence-open', ['recipient' => $recipientId]);
    }

    public static function unsubscribeUrl(int $recipientId): string
    {
        return URL::signedRoute('email.sequence-unsubscribe', ['recipient' => $recipientId]);
    }

    /**
     * Rewrite every http(s) href to a signed click-through URL so engagement is
     * tracked while the recipient still lands on the original destination.
     * Non-http links (mailto:, tel:, relative, javascript:) are left untouched.
     */
    private static function rewriteLinks(string $html, int $recipientId): string
    {
        return (string) preg_replace_callback(
            '/href\s*=\s*(["\'])(https?:\/\/[^"\']+)(["\'])/i',
            static function (array $m) use ($recipientId): string {
                // The stored HTML may already HTML-encode & as &amp; — decode so
                // the redirect target is the exact URL the operator wrote.
                $target = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);
                $redirect = URL::signedRoute('email.sequence-click', [
                    'recipient' => $recipientId,
                    'url' => $target,
                ]);

                return 'href='.$m[1].e($redirect).$m[3];
            },
            $html,
        );
    }
}
