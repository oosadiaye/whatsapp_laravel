<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Third-party text (a lead's fields, a prospect's reply) goes into prompts as data. These helpers
 * strip the markup and control characters that could close a fence or fake an instruction block.
 */
final class PromptText
{
    /** One short line with no angle brackets or control characters. */
    public static function line(?string $value, int $limit = 200): string
    {
        $cleaned = trim((string) preg_replace('/[<>[:cntrl:]\s]+/u', ' ', (string) $value));

        return mb_substr($cleaned, 0, $limit);
    }

    /** Several lines kept (paragraphs matter in a reply), angle brackets and control characters removed. */
    public static function block(?string $value, int $limit = 1500): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $text = (string) preg_replace('/[<>\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return mb_substr(trim($text), 0, $limit);
    }

    /** The new part of a reply: drops the quoted history so the model sees only what the person just wrote. */
    public static function withoutQuotedHistory(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $kept = [];
        foreach (explode("\n", $text) as $line) {
            $trimmed = trim($line);
            $isAttribution = (bool) preg_match('/^(on .{5,200} wrote:|-{2,}\s*original message\s*-{2,}|from:\s.+)$/i', $trimmed);
            if ($isAttribution || str_starts_with($trimmed, '>')) {
                break;
            }
            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }
}
