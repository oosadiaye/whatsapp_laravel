<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Support\VoiceXml;
use Tests\TestCase;

class VoiceXmlTest extends TestCase
{
    public function test_wraps_elements_in_a_response_envelope(): void
    {
        $xml = VoiceXml::make()->say('Hello')->render();

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?><Response>', $xml);
        $this->assertStringContainsString('<Say>Hello</Say>', $xml);
        $this->assertStringEndsWith('</Response>', $xml);
    }

    public function test_dial_puts_phone_numbers_first(): void
    {
        $xml = VoiceXml::make()->dial('agent_7', ['record' => true, 'callerId' => '+2348000000000'])->render();

        $this->assertStringContainsString('<Dial phoneNumbers="agent_7" record="true" callerId="+2348000000000"/>', $xml);
    }

    public function test_get_digits_nests_the_prompt(): void
    {
        $xml = VoiceXml::make()
            ->getDigits(fn ($p) => $p->say('Press 1 for sales'), [
                'numDigits' => 1,
                'timeout' => 15,
                'callbackUrl' => 'https://app.test/ivr',
            ])
            ->render();

        $this->assertStringContainsString(
            '<GetDigits numDigits="1" timeout="15" callbackUrl="https://app.test/ivr"><Say>Press 1 for sales</Say></GetDigits>',
            $xml,
        );
    }

    public function test_record_and_enqueue_render_as_self_closing(): void
    {
        $xml = VoiceXml::make()
            ->record(['maxLength' => 120, 'finishOnKey' => '#', 'callbackUrl' => 'https://app.test/vm'])
            ->render();
        $this->assertStringContainsString('<Record maxLength="120" finishOnKey="#" callbackUrl="https://app.test/vm"/>', $xml);

        $q = VoiceXml::make()->enqueue(['name' => 'support', 'holdMusic' => 'https://m/hold.mp3'])->render();
        $this->assertStringContainsString('<Enqueue name="support" holdMusic="https://m/hold.mp3"/>', $q);
    }

    public function test_escapes_xml_in_text_and_attributes(): void
    {
        $xml = VoiceXml::make()->say('Tom & Jerry <say> "hi"')->render();

        $this->assertStringContainsString('Tom &amp; Jerry &lt;say&gt; &quot;hi&quot;', $xml);
        $this->assertStringNotContainsString('<say>', $xml); // the literal lowercase tag must be escaped
    }

    public function test_null_attributes_are_skipped(): void
    {
        $xml = VoiceXml::make()->dial('agent_1', ['callerId' => null])->render();

        $this->assertStringContainsString('<Dial phoneNumbers="agent_1"/>', $xml);
        $this->assertStringNotContainsString('callerId', $xml);
    }

    public function test_to_response_sets_xml_content_type(): void
    {
        $response = VoiceXml::make()->reject()->toResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<Reject/>', $response->getContent());
    }
}
