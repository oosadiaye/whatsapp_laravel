<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Mail\CampaignEmail;
use App\Models\EmailCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignEmailMailableTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(array $overrides = []): EmailCampaign
    {
        return EmailCampaign::factory()->create(array_merge([
            'subject' => 'Hi {{name}}, an offer for you',
            'from_name' => 'Acme Sales',
            'reply_to' => 'sales@acme.test',
            'body_html' => '<p>Hello {{name}} at {{email}}</p>',
        ], $overrides));
    }

    public function test_personalizes_subject_and_body(): void
    {
        $mailable = new CampaignEmail($this->campaign(), 'jane@example.com', 'Jane');

        $mailable->assertHasSubject('Hi Jane, an offer for you');
        $mailable->assertSeeInHtml('Hello Jane at jane@example.com', false);
    }

    public function test_sets_from_name_and_reply_to(): void
    {
        $mailable = new CampaignEmail($this->campaign(), 'jane@example.com', 'Jane');

        $mailable->assertFrom(config('mail.from.address'), 'Acme Sales');
        $mailable->assertHasReplyTo('sales@acme.test');
    }

    public function test_appends_an_unsubscribe_link_and_header(): void
    {
        $mailable = new CampaignEmail($this->campaign(), 'jane@example.com', 'Jane');

        // Footer link + tamper-proof signature present.
        $mailable->assertSeeInHtml('Unsubscribe', false);
        $mailable->assertSeeInHtml('signature=', false);

        // RFC 2369/8058 headers are set on the built message.
        $headers = $mailable->render() ? $mailable->headers() : null;
        $this->assertSame(
            '<'.\Illuminate\Support\Facades\URL::signedRoute('email.unsubscribe', ['email' => 'jane@example.com']).'>',
            $headers->text['List-Unsubscribe'] ?? null,
        );
        $this->assertSame('List-Unsubscribe=One-Click', $headers->text['List-Unsubscribe-Post'] ?? null);
    }

    public function test_missing_name_renders_without_placeholder_artifacts(): void
    {
        $mailable = new CampaignEmail($this->campaign(), 'jane@example.com', null);

        $mailable->assertDontSeeInHtml('{{name}}', false);
        $mailable->assertDontSeeInHtml('{{email}}', false);
    }

    public function test_html_in_contact_name_is_escaped_in_the_body(): void
    {
        // Audit H1: a contact name is attacker-influenceable free text. It must
        // be HTML-escaped before substitution into the raw email body, or the
        // markup is injected verbatim into every email.
        $mailable = new CampaignEmail(
            $this->campaign(['body_html' => '<p>Hello {{name}}</p>']),
            'jane@example.com',
            '<script>alert(1)</script>',
        );

        // The raw tag must not survive into the body; the escaped form must.
        $mailable->assertDontSeeInHtml('<script>alert(1)</script>', false);
        $mailable->assertSeeInHtml('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_subject_still_substitutes_plain_text_without_escaping(): void
    {
        // The subject is a plain-text context — an ampersand in a name must stay
        // a literal '&', not become '&amp;'.
        $mailable = new CampaignEmail(
            $this->campaign(['subject' => 'Deal for {{name}}']),
            'jane@example.com',
            'Ben & Co',
        );

        $mailable->assertHasSubject('Deal for Ben & Co');
    }
}
