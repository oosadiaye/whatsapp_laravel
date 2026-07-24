<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A small catalogue of ready-made, email-safe HTML templates an operator can
 * start a campaign from (the composer's "Start from a template" picker). Each
 * body lives as a plain .html file under resources/email-templates/ — table
 * layout + inline styles so it renders across email clients — and keeps the
 * {{name}} / {{email}} personalisation tokens the campaign mailer substitutes.
 *
 * The unsubscribe footer + tracking pixel are appended by
 * {@see \App\Mail\CampaignEmail}, so templates deliberately omit them.
 */
final class EmailTemplateLibrary
{
    /**
     * @return list<array{key: string, name: string, description: string, accent: string}>
     */
    public static function catalogue(): array
    {
        return [
            ['key' => 'announcement', 'name' => 'Announcement', 'description' => 'A bold headline with a single call to action.', 'accent' => '#4f46e5'],
            ['key' => 'newsletter', 'name' => 'Newsletter', 'description' => 'Stacked content blocks for a regular update.', 'accent' => '#0ea5e9'],
            ['key' => 'promotion', 'name' => 'Promotion', 'description' => 'A sale hero with a discount code and CTA.', 'accent' => '#e11d48'],
            ['key' => 'welcome', 'name' => 'Welcome', 'description' => 'A warm onboarding email with next steps.', 'accent' => '#10b981'],
            ['key' => 'simple', 'name' => 'Simple', 'description' => 'A clean, text-first layout for best deliverability.', 'accent' => '#334155'],
        ];
    }

    /**
     * The catalogue with each template's HTML body loaded in.
     *
     * @return list<array{key: string, name: string, description: string, accent: string, html: string}>
     */
    public static function all(): array
    {
        return array_values(array_filter(array_map(
            static function (array $meta): ?array {
                $html = self::html($meta['key']);

                return $html === '' ? null : [...$meta, 'html' => $html];
            },
            self::catalogue(),
        )));
    }

    /**
     * The raw HTML for one template, or '' if the key is unknown/missing. The
     * key is sanitised to a bare slug so it can never escape the templates dir.
     */
    public static function html(string $key): string
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($key)) ?? '';
        if ($slug === '') {
            return '';
        }

        $path = resource_path("email-templates/{$slug}.html");

        return is_file($path) ? trim((string) file_get_contents($path)) : '';
    }
}
