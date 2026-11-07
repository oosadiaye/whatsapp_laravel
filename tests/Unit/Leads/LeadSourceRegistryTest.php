<?php

declare(strict_types=1);

namespace Tests\Unit\Leads;

use App\Services\Leads\LeadData;
use App\Services\Leads\LeadPage;
use App\Services\Leads\LeadQuery;
use App\Services\Leads\LeadSource;
use App\Services\Leads\LeadSourceRegistry;
use InvalidArgumentException;
use Tests\TestCase;

final class LeadSourceRegistryTest extends TestCase
{
    public function test_keys_and_has_read_the_config(): void
    {
        config(['leads.sources' => ['fake' => RegistryFakeSource::class, 'other' => RegistryFakeSource::class]]);
        $registry = new LeadSourceRegistry;

        $this->assertSame(['fake', 'other'], $registry->keys());
        $this->assertTrue($registry->has('fake'));
        $this->assertFalse($registry->has('nope'));
    }

    public function test_get_unknown_source_throws(): void
    {
        config(['leads.sources' => []]);

        $this->expectException(InvalidArgumentException::class);
        (new LeadSourceRegistry)->get('unknown');
    }

    public function test_get_and_all_resolve_configured_connectors(): void
    {
        config(['leads.sources' => ['fake' => RegistryFakeSource::class]]);
        $registry = new LeadSourceRegistry;

        $this->assertInstanceOf(RegistryFakeSource::class, $registry->get('fake'));

        $all = $registry->all();
        $this->assertSame(['fake'], array_keys($all));
        $this->assertInstanceOf(LeadSource::class, $all['fake']);
    }
}

final class RegistryFakeSource implements LeadSource
{
    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function testConnection(): void {}

    public function search(LeadQuery $query, int $page, int $perPage): LeadPage
    {
        return new LeadPage([], 0, false);
    }

    public function reveal(LeadData $lead): LeadData
    {
        return $lead;
    }
}
