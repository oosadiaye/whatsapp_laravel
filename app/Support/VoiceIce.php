<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds the WebRTC ICE-server list (STUN + optional TURN) from config/voice.php
 * in the shape RTCPeerConnection expects. Rendered once server-side into the
 * layout (meta[name=bq-ice-servers]) so both the Meta path (calls.js) and the
 * Africa's Talking softphone (outbound-call.js) read one source of truth,
 * instead of STUN being duplicated in Vite env.
 */
class VoiceIce
{
    /**
     * @return array<int, array<string, mixed>> RTCIceServer[]
     */
    public static function servers(): array
    {
        $servers = array_map(
            static fn (string $urls): array => ['urls' => $urls],
            array_values((array) config('voice.stun_urls', [])),
        );

        $turnUrls = array_values((array) config('voice.turn_urls', []));
        if ($turnUrls !== []) {
            $turn = ['urls' => $turnUrls];

            $username = config('voice.turn_username');
            $credential = config('voice.turn_credential');
            if (filled($username) && filled($credential)) {
                $turn['username'] = $username;
                $turn['credential'] = $credential;
            }

            $servers[] = $turn;
        }

        return $servers;
    }
}
