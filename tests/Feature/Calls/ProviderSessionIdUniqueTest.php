<?php

declare(strict_types=1);

namespace Tests\Feature\Calls;

use App\Models\CallLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderSessionIdUniqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_calls_cannot_share_a_provider_session_id(): void
    {
        CallLog::factory()->create(['provider_session_id' => 'ATVoice-session-123']);

        $this->expectException(QueryException::class);

        // The duplicate-inbound race: a second row for the same AT session must
        // be rejected by the DB, not silently created.
        CallLog::factory()->create(['provider_session_id' => 'ATVoice-session-123']);
    }

    public function test_multiple_calls_may_have_null_provider_session_id(): void
    {
        // Meta calls key on meta_call_id and leave provider_session_id null;
        // the unique index must still allow many nulls.
        CallLog::factory()->count(3)->create(['provider_session_id' => null]);

        $this->assertSame(3, CallLog::whereNull('provider_session_id')->count());
    }
}
