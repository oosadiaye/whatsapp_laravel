<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Support\SequenceEmailContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenceEmailContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_http_links_are_wrapped_in_a_signed_click_through(): void
    {
        $html = '<a href="https://example.com/page?a=1&b=2">Buy</a> <a href="https://blog.example.com">Blog</a>';

        $out = SequenceEmailContent::bodyHtml($html, 42);

        $this->assertStringContainsString('/email/sequence-click/42', $out);
        $this->assertStringContainsString('url=https%3A%2F%2Fexample.com%2Fpage%3Fa%3D1%26b%3D2', $out);
        $this->assertStringNotContainsString('href="https://example.com/page', $out);
    }

    public function test_non_http_links_are_left_untouched(): void
    {
        $html = '<a href="mailto:hi@example.com">Mail</a> <a href="tel:+1234">Call</a> <a href="#section">Anchor</a>';

        $out = SequenceEmailContent::bodyHtml($html, 7);

        $this->assertStringContainsString('href="mailto:hi@example.com"', $out);
        $this->assertStringContainsString('href="tel:+1234"', $out);
        $this->assertStringContainsString('href="#section"', $out);
        $this->assertStringNotContainsString('/email/sequence-click/7', $out);
    }

    public function test_html_encoded_ampersands_in_hrefs_still_redirect_correctly(): void
    {
        $html = '<a href="https://example.com?a=1&amp;b=2">Go</a>';

        $out = SequenceEmailContent::bodyHtml($html, 9);

        // The decoded target (with a real &) is what the redirect records.
        $this->assertStringContainsString('url=https%3A%2F%2Fexample.com%3Fa%3D1%26b%3D2', $out);
    }

    public function test_body_html_appends_the_open_pixel(): void
    {
        $out = SequenceEmailContent::bodyHtml('<p>Hi</p>', 3);

        $this->assertStringContainsString('/email/sequence-open/3', $out);
        $this->assertStringContainsString('<img src="', $out);
    }

    public function test_an_empty_body_html_gets_no_pixel(): void
    {
        $this->assertSame('', SequenceEmailContent::bodyHtml(null, 5));
        $this->assertSame('', SequenceEmailContent::bodyHtml('', 5));
    }

    public function test_unsubscribe_url_is_signed(): void
    {
        $url = SequenceEmailContent::unsubscribeUrl(11);

        $this->assertStringContainsString('/email/sequence-unsubscribe/11', $url);
        // A signature is present (full round-trip validity is covered by the
        // HTTP-level SequenceTrackingTest::test_unsubscribe_suppresses_and_marks_the_recipient).
        $this->assertStringContainsString('signature=', $url);
    }
}
