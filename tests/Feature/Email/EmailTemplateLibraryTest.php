<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Support\EmailTemplateLibrary;
use Tests\TestCase;

/**
 * The starter email-template catalogue: every entry must load real, email-safe
 * HTML that keeps the personalisation token, and lookups must not escape the
 * templates directory.
 */
class EmailTemplateLibraryTest extends TestCase
{
    public function test_all_returns_templates_with_email_safe_html(): void
    {
        $templates = EmailTemplateLibrary::all();

        $this->assertNotEmpty($templates);
        $this->assertCount(count(EmailTemplateLibrary::catalogue()), $templates);

        foreach ($templates as $template) {
            $this->assertNotSame('', $template['key']);
            $this->assertNotSame('', $template['name']);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $template['accent']);
            // Table layout + inline styles = renders across email clients.
            $this->assertStringContainsString('<table', $template['html']);
            $this->assertStringContainsString('style=', $template['html']);
            // Keeps the campaign personalisation token.
            $this->assertStringContainsString('{{name}}', $template['html']);
        }
    }

    public function test_html_lookup_is_path_safe(): void
    {
        $this->assertSame('', EmailTemplateLibrary::html('../../.env'));
        $this->assertSame('', EmailTemplateLibrary::html('does-not-exist'));
        $this->assertSame('', EmailTemplateLibrary::html(''));

        $this->assertStringContainsString('{{name}}', EmailTemplateLibrary::html('welcome'));
    }
}
