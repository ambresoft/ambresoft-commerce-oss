<?php

namespace App\Catalog\Application\Service;

use Closure;

final class ProductDeletionGuards
{
    /** @var list<Closure(string): ?string> */
    private array $guards = [];

    /** Register a guard returning a translatable reason key, or null. Called under the product transaction lock. */
    public function add(Closure $guard): void
    {
        $this->guards[] = $guard;
    }

    public function reason(string $id): ?string
    {
        foreach ($this->guards as $guard) {
            if (($reason = $guard($id)) !== null) {
                return $reason;
            }
        }

        return null;
    }
}
