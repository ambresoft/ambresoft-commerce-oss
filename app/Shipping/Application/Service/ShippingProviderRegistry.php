<?php

namespace App\Shipping\Application\Service;

use App\Shipping\Application\Contract\ShippingProvider;
use Closure;
use LogicException;

/** Registered carrier providers and their owner (`core` or the add-on name), resolved lazily on first use. */
final class ShippingProviderRegistry
{
    public const CORE = 'core';

    /** @var array<string, array{owner: string, factory: Closure(): ShippingProvider}> */
    private array $entries = [];

    /** @var array<string, ShippingProvider> */
    private array $resolved = [];

    /** @param Closure(): ShippingProvider $factory */
    public function add(string $id, string $owner, Closure $factory): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,59}$/D', $id) !== 1 || isset($this->entries[$id])) {
            throw new LogicException('Shipping providers need a unique identifier: '.$id);
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

    public function get(string $id): ShippingProvider
    {
        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }
        $entry = $this->entries[$id] ?? throw new LogicException('Unknown shipping provider: '.$id);
        $provider = ($entry['factory'])();
        if ($provider->id() !== $id) {
            throw new LogicException('Shipping provider identifier mismatch: '.$id);
        }

        return $this->resolved[$id] = $provider;
    }
}
