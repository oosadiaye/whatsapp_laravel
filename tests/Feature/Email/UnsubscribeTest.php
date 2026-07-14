<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class UnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_link_suppresses_the_address(): void
    {
        $url = URL::signedRoute('email.unsubscribe', ['email' => 'lead@example.com']);

        $this->get($url)
            ->assertOk()
            ->assertSee('unsubscribed');

        $this->assertTrue(EmailSuppression::isSuppressed('lead@example.com'));
    }

    public function test_a_tampered_or_unsigned_link_is_rejected(): void
    {
        // No signature at all.
        $this->get(route('email.unsubscribe', ['email' => 'lead@example.com']))
            ->assertForbidden();

        // Signed for one address but the query mutated to another → signature fails.
        $signed = URL::signedRoute('email.unsubscribe', ['email' => 'lead@example.com']);
        $tampered = str_replace('lead%40example.com', 'victim%40example.com', $signed);

        $this->get($tampered)->assertForbidden();
        $this->assertFalse(EmailSuppression::isSuppressed('victim@example.com'));
    }
}
