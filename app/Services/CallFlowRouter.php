<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CallLog;
use App\Support\VoiceXml;

/**
 * Decides the Voice XML for an inbound call. The engine applies, in order:
 *
 *   business hours  →  IVR menu  →  destination (agent / queue / voicemail)
 *
 * Each stage is dark behind its own config/voice.php flag; with everything off
 * this collapses to the original behaviour (dial the assigned agent, else say
 * "agents busy"). Kept as a pure service so the XML is unit-testable without a
 * live call.
 */
class CallFlowRouter
{
    public function __construct(private readonly BusinessHours $hours)
    {
    }

    /**
     * First response when AT asks what to do with a fresh inbound call.
     */
    public function entryXml(CallLog $call): VoiceXml
    {
        // 1. Business hours — closed → voicemail (if on) or a closed message.
        if (config('voice.business_hours_enabled') && ! $this->hours->isOpen()) {
            $xml = VoiceXml::make()->say($this->hours->closedMessage());

            return config('voice.voicemail_enabled')
                ? $xml->record($this->recordAttrs())
                : $xml;
        }

        // 2. IVR — present the menu and collect one digit.
        if (config('voice.ivr_enabled')) {
            return $this->menuXml();
        }

        // 3. No IVR — go straight to the default destination.
        return $this->destination($call);
    }

    /**
     * Response after the caller presses a key in the IVR.
     */
    public function digitSelectionXml(CallLog $call, string $digit): VoiceXml
    {
        $option = config("voice.ivr.options.{$digit}");

        if (! is_array($option)) {
            // Unrecognised key → say so and re-present the menu.
            return VoiceXml::make()
                ->say((string) config('voice.ivr.invalid_message', 'Sorry, that is not a valid option.'))
                ->getDigits(
                    fn (VoiceXml $p) => $p->say((string) config('voice.ivr.prompt', '')),
                    $this->getDigitsAttrs(),
                );
        }

        return match ($option['type'] ?? 'agent') {
            'queue' => $this->queueXml((string) ($option['queue'] ?? config('voice.queue.default_name'))),
            'voicemail' => $this->voicemailXml(),
            default => $this->destination($call),
        };
    }

    private function menuXml(): VoiceXml
    {
        return VoiceXml::make()->getDigits(
            fn (VoiceXml $p) => $p->say((string) config('voice.ivr.prompt', '')),
            $this->getDigitsAttrs(),
        );
    }

    /**
     * Default destination: dial the assigned agent; if none, fall back to a
     * queue (if enabled), then voicemail (if enabled), then a busy message.
     */
    private function destination(CallLog $call): VoiceXml
    {
        $agentId = $call->conversation?->assigned_to_user_id;

        if ($agentId !== null) {
            return VoiceXml::make()->dial(
                AfricasTalkingVoiceService::clientNameForUser((int) $agentId),
                ['callerId' => $call->from_phone ?: null],
            );
        }

        if (config('voice.queue_enabled')) {
            return $this->queueXml((string) config('voice.queue.default_name'));
        }

        if (config('voice.voicemail_enabled')) {
            return $this->voicemailXml();
        }

        return VoiceXml::make()->say('All our agents are currently busy. Please call again later.');
    }

    private function queueXml(string $name): VoiceXml
    {
        $attrs = ['name' => $name];
        $hold = (string) config('voice.queue.hold_music_url', '');
        if ($hold !== '') {
            $attrs['holdMusic'] = $hold;
        }

        return VoiceXml::make()->enqueue($attrs);
    }

    private function voicemailXml(): VoiceXml
    {
        return VoiceXml::make()
            ->say((string) config('voice.voicemail.greeting', 'Please leave a message after the tone.'))
            ->record($this->recordAttrs());
    }

    /**
     * @return array<string, mixed>
     */
    private function recordAttrs(): array
    {
        return [
            'finishOnKey' => '#',
            'maxLength' => (int) config('voice.voicemail.max_length_seconds', 120),
            'playBeep' => true,
            'trimSilence' => true,
            'callbackUrl' => $this->callbackUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getDigitsAttrs(): array
    {
        return [
            'numDigits' => 1,
            'timeout' => (int) config('voice.ivr.timeout', 15),
            'callbackUrl' => $this->callbackUrl(),
        ];
    }

    /**
     * The AT voice webhook URL (carrying the secret segment) that GetDigits and
     * Record post their results back to.
     */
    private function callbackUrl(): string
    {
        $secret = (string) config('voice.at_webhook_secret', '');

        return $secret !== ''
            ? route('webhook.africastalking.voice', ['secret' => $secret])
            : route('webhook.africastalking.voice');
    }
}
