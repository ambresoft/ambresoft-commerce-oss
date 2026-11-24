<?php

namespace App\Admin\DataTransfer;

use App\Identity\Infrastructure\Persistence\UserRecord as User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use LogicException;

/**
 * CSV imports and exports listed on the Imports / Exports screen. Each
 * contribution keeps its own route and capability; the hub only links to the
 * ones the current staff user may run.
 */
final class DataTransferCatalog
{
    /** @var array<string, array{id: string, kind: 'import'|'export', title: string, description: string, route: string, ability: string, parameters: array<string, string>}> */
    private array $entries = [];

    /**
     * @param  'import'|'export'  $kind
     * @param  array<string, string>  $parameters  Route parameters, e.g. the entity of the generic export screen.
     */
    public function add(string $id, string $kind, string $title, string $description, string $route, string $ability, array $parameters = []): void
    {
        if (isset($this->entries[$id]) || ! str_contains($id, '.') || $title === '' || $ability === '') {
            throw new LogicException('Data transfers require a unique namespaced ID, title and ability.');
        }
        $this->entries[$id] = compact('id', 'kind', 'title', 'description', 'route', 'ability', 'parameters');
    }

    /** @return list<array{id: string, kind: 'import'|'export', title: string, description: string, href: string}> */
    public function forUser(User $user): array
    {
        $visible = [];
        foreach ($this->entries as $entry) {
            if (! Route::has($entry['route'])) {
                throw new LogicException('Data transfer references a missing route: '.$entry['route']);
            }
            if (Gate::forUser($user)->allows($entry['ability'])) {
                $visible[] = ['id' => $entry['id'], 'kind' => $entry['kind'], 'title' => __($entry['title']), 'description' => __($entry['description']), 'href' => route($entry['route'], $entry['parameters'], false)];
            }
        }

        return $visible;
    }
}
