<?php

namespace App\Authorization\Infrastructure;

use LogicException;

final class PermissionRegistry
{
    /** @var array<string, string> */
    private array $entries = [];

    public function add(string $ability, string $translationKey): void
    {
        if (isset($this->entries[$ability]) || preg_match('/^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+$/D', $ability) !== 1 || $translationKey === '') {
            throw new LogicException('Permissions require unique dotted capability names and translation keys.');
        }
        $this->entries[$ability] = $translationKey;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->entries;
    }
}
