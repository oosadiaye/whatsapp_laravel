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
}
