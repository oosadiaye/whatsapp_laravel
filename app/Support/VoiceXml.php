<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Response;

/**
 * Fluent builder for Africa's Talking Voice XML responses.
 *
 * AT drives a live call by POSTing to the callback URL and executing whatever
 * `<Response>` XML we return: Say/Play/GetDigits/Dial/Record/Enqueue/Dequeue/
 * Redirect/Reject. Centralising the XML here (instead of string-concatenating
 * in the controller) keeps escaping correct and the call-flow readable, and
 * makes the output trivially assertable in tests.
 *
 * Example:
 *   VoiceXml::make()
 *       ->getDigits(fn ($p) => $p->say('Press 1 for sales'),
 *           ['numDigits' => 1, 'timeout' => 15, 'callbackUrl' => $url])
 *       ->toResponse();
 */
class VoiceXml
{
    /** @var array<int, string> */
    private array $parts = [];

    public static function make(): self
    {
        return new self();
    }

    public function say(string $text, array $attrs = []): self
    {
        $this->parts[] = '<Say'.$this->attrs($attrs).'>'.$this->esc($text).'</Say>';

        return $this;
    }

    public function play(string $url): self
    {
        $this->parts[] = '<Play url="'.$this->esc($url).'"/>';

        return $this;
    }

    public function dial(string $phoneNumbers, array $attrs = []): self
    {
        $this->parts[] = '<Dial'.$this->attrs(['phoneNumbers' => $phoneNumbers] + $attrs).'/>';

        return $this;
    }

    public function record(array $attrs = []): self
    {
        $this->parts[] = '<Record'.$this->attrs($attrs).'/>';

        return $this;
    }

    public function enqueue(array $attrs = []): self
    {
        $this->parts[] = '<Enqueue'.$this->attrs($attrs).'/>';

        return $this;
    }

    public function dequeue(string $name, array $attrs = []): self
    {
        $this->parts[] = '<Dequeue'.$this->attrs(['name' => $name] + $attrs).'/>';

        return $this;
    }

    public function redirect(string $url): self
    {
        $this->parts[] = '<Redirect>'.$this->esc($url).'</Redirect>';

        return $this;
    }

    public function reject(): self
    {
        $this->parts[] = '<Reject/>';

        return $this;
    }

    /**
     * <GetDigits> with a nested prompt (built via the callback). AT posts the
     * pressed keys as `dtmfDigits` to the callbackUrl.
     */
    public function getDigits(callable $prompt, array $attrs = []): self
    {
        $child = self::make();
        $prompt($child);

        $this->parts[] = '<GetDigits'.$this->attrs($attrs).'>'.$child->inner().'</GetDigits>';

        return $this;
    }

    /** Inner element markup, without the <Response> envelope. */
    public function inner(): string
    {
        return implode('', $this->parts);
    }

    public function render(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Response>'.$this->inner().'</Response>';
    }

    public function toResponse(): Response
    {
        return response($this->render(), 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Render an attribute string, skipping null values and XML-escaping the
     * rest. Booleans render as "true"/"false" (AT's convention).
     *
     * @param  array<string, mixed>  $attrs
     */
    private function attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $out .= ' '.$key.'="'.$this->esc((string) $value).'"';
        }

        return $out;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
