<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Support\VoiceIce;
use Tests\TestCase;

class VoiceIceTest extends TestCase
{
    public function test_stun_only_by_default(): void
    {
        config([
            'voice.stun_urls' => ['stun:stun.l.google.com:19302'],
            'voice.turn_urls' => [],
        ]);

        $servers = VoiceIce::servers();

        $this->assertSame([['urls' => 'stun:stun.l.google.com:19302']], $servers);
    }

    public function test_appends_turn_with_credentials(): void
    {
        config([
            'voice.stun_urls' => ['stun:stun.l.google.com:19302'],
            'voice.turn_urls' => ['turn:turn.example.com:3478', 'turns:turn.example.com:5349'],
            'voice.turn_username' => 'user',
            'voice.turn_credential' => 'secret',
        ]);

        $servers = VoiceIce::servers();

        $this->assertCount(2, $servers);
        $this->assertSame([
            'urls' => ['turn:turn.example.com:3478', 'turns:turn.example.com:5349'],
            'username' => 'user',
            'credential' => 'secret',
        ], $servers[1]);
    }

    public function test_turn_without_credentials_still_included(): void
    {
        config([
            'voice.stun_urls' => [],
            'voice.turn_urls' => ['turn:open.example.com:3478'],
            'voice.turn_username' => null,
            'voice.turn_credential' => null,
        ]);

        $servers = VoiceIce::servers();

        $this->assertSame([['urls' => ['turn:open.example.com:3478']]], $servers);
    }
}
