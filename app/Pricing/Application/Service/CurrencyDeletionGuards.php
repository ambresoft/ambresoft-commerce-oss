<?php

namespace App\Pricing\Application\Service;

use Closure;

/** Future financial modules (orders, payments, refunds) register blockers for currencies their records reference. */
final class CurrencyDeletionGuards
{
    /** @var list<Closure(string): ?string> */
    private array $guards = [];

    /** Register a guard receiving the currency code and returning a translatable reason key, or null. Called under the currency transaction lock. */
    public function add(Closure $guard): void
    {
        $this->guards[] = $guard;
    }

    public function reason(string $code): ?string
    {
        foreach ($this->guards as $guard) {
            if (($reason = $guard($code)) !== null) {
                return $reason;
            }
        }

        return null;
    }
}
