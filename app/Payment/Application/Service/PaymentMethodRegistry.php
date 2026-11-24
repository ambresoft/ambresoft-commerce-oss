<?php

namespace App\Payment\Application\Service;

use App\Payment\Application\Contract\PaymentMethod;
use Closure;
use LogicException;

/**
 * Registered payment methods and their owner (`core` or the add-on name). Core registers its own methods; add-ons
 * register through the `payment-methods` manifest contribution, resolved lazily on first use.
 */
final class PaymentMethodRegistry
{
    public const CORE = 'core';

    /** @var array<string, array{owner: string, factory: Closure(): PaymentMethod}> */
    private array $entries = [];

    /** @var array<string, PaymentMethod> */
    private array $resolved = [];

    /** @param Closure(): PaymentMethod $factory */
    public function add(string $id, string $owner, Closure $factory): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,59}$/D', $id) !== 1 || isset($this->entries[$id])) {
            throw new LogicException('Payment methods need a unique identifier: '.$id);
        }
        $this->entries[$id] = ['owner' => $owner, 'factory' => $factory];
    }

    public function has(string $id): bool
    {
        return isset($this->entries[$id]);
    }

    public function owner(string $id): ?string
    {
        return $this->entries[$id]['owner'] ?? null;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->entries);
    }

    public function get(string $id): PaymentMethod
    {
        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }
        $entry = $this->entries[$id] ?? throw new LogicException('Unknown payment method: '.$id);
        $method = ($entry['factory'])();
        if ($method->id() !== $id) {
            throw new LogicException('Payment method identifier mismatch: '.$id);
        }

        return $this->resolved[$id] = $method;
    }
}
