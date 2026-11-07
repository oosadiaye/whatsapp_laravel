<?php

declare(strict_types=1);

namespace Tests\Unit\Leads;

use App\Services\Leads\LeadData;
use PHPUnit\Framework\TestCase;

final class LeadDataTest extends TestCase
{
    public function test_with_returns_a_changed_copy_and_leaves_the_original(): void
    {
        $lead = new LeadData(source: 'apollo', sourceId: '1', name: 'Ada Ob***e', title: 'CEO', company: 'Acme');

        $revealed = $lead->with(['name' => 'Ada Obi', 'email' => 'ada@acme.test', 'emailStatus' => 'verified']);

        $this->assertSame('Ada Ob***e', $lead->name);
        $this->assertNull($lead->email);
        $this->assertSame('Ada Obi', $revealed->name);
        $this->assertSame('ada@acme.test', $revealed->email);
        $this->assertSame('Acme', $revealed->company);
    }
}
