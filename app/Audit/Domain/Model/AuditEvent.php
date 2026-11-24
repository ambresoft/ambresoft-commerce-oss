<?php

namespace App\Audit\Domain\Model;

final readonly class AuditEvent
{
    /** @param array<string, mixed> $metadata */
    public function __construct(public string $action, public string $entityType, public ?string $entityId = null, public string $status = 'success', public array $metadata = [], public ?string $entityLabel = null) {}
}
