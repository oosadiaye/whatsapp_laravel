<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;

class AuditService
{
    public function log(string $action, ?string $resourceType = null, ?int $resourceId = null, ?array $metadata = null): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'metadata' => $metadata,
            'ip' => request()->ip(),
        ]);
    }
}
