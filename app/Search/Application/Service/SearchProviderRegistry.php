<?php

namespace App\Search\Application\Service;

use App\Search\Application\Contract\SearchProvider;
use Closure;
use LogicException;

/** Registered search providers and the add-on that owns each, resolved lazily on first use. */
final class SearchProviderRegistry
{
    /** @var array<string, array{owner: string, factory: Closure(): SearchProvider}> */
    private array $entries = [];

    /** @var array<string, SearchProvider> */
    private array $resolved = [];

    /** @param Closure(): SearchProvider $factory */
    public function add(string $id, string $owner, Closure $factory): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,59}$/D', $id) !== 1 || isset($this->entries[$id])) {
            throw new LogicException('Search providers need a unique identifier: '.$id);
        }
        $this->entries[$id] = ['owner' => $owner, 'factory' => $factory];
    }

    public function owner(string $id): ?string
    {
        return $this->entries[$id]['owner'] ?? null;
    }

    /** @return list<string> */
    public function ids(): array
    {
        $ids = array_keys($this->entries);
        sort($ids);

        return $ids;
    }

    public function get(string $id): SearchProvider
    {
        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }
        $entry = $this->entries[$id] ?? throw new LogicException('Unknown search provider: '.$id);
        $provider = ($entry['factory'])();
        if ($provider->id() !== $id) {
            throw new LogicException('Search provider identifier mismatch: '.$id);
        }

        return $this->resolved[$id] = $provider;
    }
}
