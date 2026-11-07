<?php

declare(strict_types=1);

namespace App\Services\Leads;

use InvalidArgumentException;

/** Resolves connectors from config/leads.php. */
final class LeadSourceRegistry
{
    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys((array) config('leads.sources', []));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, (array) config('leads.sources', []));
    }

    public function get(string $key): LeadSource
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown lead source [{$key}].");
        }

        return app(config("leads.sources.{$key}"));
    }

    /**
     * @return array<string, LeadSource>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->keys() as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }
}
