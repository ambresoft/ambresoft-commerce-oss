<?php

namespace App\Authorization\Domain\Service;

use App\Authorization\Domain\Exception\UnsafeAccessChange;

final class AccessSafety
{
    public static function assertUserChange(string $actorId, string $targetId, bool $active, bool $wasSuper, bool $willBeSuper, int $activeSuperCount): void
    {
        if (($actorId === $targetId && ! $active) || ($wasSuper && (! $active || ! $willBeSuper) && $activeSuperCount <= 1)) {
            throw new UnsafeAccessChange('authorization.unsafe_user');
        }
    }
}
