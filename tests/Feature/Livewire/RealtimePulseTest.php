<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\RealtimePulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RealtimePulseTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_payload_for_unauthenticated_user(): void
    {
        Livewire::test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0)
            ->assertViewHas('unreadMessages', 0);
    }
}
