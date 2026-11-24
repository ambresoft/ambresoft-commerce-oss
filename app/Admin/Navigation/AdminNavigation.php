<?php

namespace App\Admin\Navigation;

use App\Identity\Infrastructure\Persistence\UserRecord as User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use LogicException;

final class AdminNavigation
{
    /** @var array<string, array{id: string, label: string, route: string, ability: string, position: int, group: ?string, activeRoutes: list<string>}> */
    private array $entries = [];

    /** @var array<string, array{label: string, parent: ?string}> */
    private array $groups = [];

    /** @param ?string $parent A top-level group this group is nested in (one level of nesting). */
    public function addGroup(string $id, string $label, ?string $parent = null): void
    {
        if (isset($this->groups[$id]) || ! str_contains($id, '.') || $label === '' || ($parent !== null && (! isset($this->groups[$parent]) || $this->groups[$parent]['parent'] !== null))) {
            throw new LogicException('Admin navigation groups require a unique namespaced ID and label; a parent must be an existing top-level group.');
        }
        $this->groups[$id] = ['label' => $label, 'parent' => $parent];
    }

    /** @param list<string> $activeRoutes Extra route-name patterns this entry owns (e.g. a page reached from it). */
    public function add(string $id, string $label, string $route, string $ability, int $position = 100, ?string $group = null, array $activeRoutes = []): void
    {
        $adminRoute = str_starts_with($route, 'admin.') || preg_match('/^module\.[^.]+\.[^.]+\.admin\./', $route) === 1;
        if (isset($this->entries[$id]) || ! str_contains($id, '.') || ! $adminRoute || $label === '' || $ability === '') {
            throw new LogicException('Admin navigation requires a unique namespaced ID, admin route, label and ability.');
        }

        $this->entries[$id] = compact('id', 'label', 'route', 'ability', 'position', 'group', 'activeRoutes');
    }

    /** @return list<array{id: string, label: string, href: string, active: bool, description?: string, group?: array{id: string, label: string, parent?: array{id: string, label: string}}}> */
    public function forUser(User $user): array
    {
        $entries = $this->entries;
        uasort($entries, fn (array $a, array $b): int => [$a['position'], $a['id']] <=> [$b['position'], $b['id']]);
        $visible = [];

        foreach ($entries as $entry) {
            if ($entry['group'] !== null && ! isset($this->groups[$entry['group']])) {
                throw new LogicException('Admin navigation references a missing group: '.$entry['group']);
            }
            if (! Route::has($entry['route'])) {
                throw new LogicException('Admin navigation references a missing route: '.$entry['route']);
            }

            if (Gate::forUser($user)->allows($entry['ability'])) {
                $item = ['id' => $entry['id'], 'label' => __($entry['label']), 'href' => route($entry['route'], [], false), 'active' => $this->match($entry)];
                $key = 'navigation.descriptions.'.str_replace('core.', '', $entry['id']);
                if (Lang::has($key)) {
                    $item['description'] = __($key);
                }
                if ($entry['group'] !== null) {
                    $group = $this->groups[$entry['group']];
                    $item['group'] = ['id' => $entry['group'], 'label' => __($group['label'])];
                    if ($group['parent'] !== null) {
                        $item['group']['parent'] = ['id' => $group['parent'], 'label' => __($this->groups[$group['parent']]['label'])];
                    }
                }
                $visible[] = $item;
            }
        }

        // Only the most specific claim is active, so a page owned by one entry
        // (e.g. category import under Imports / Exports) does not also light up
        // a broader sibling such as Categories.
        $best = max([0, ...array_column($visible, 'active')]);

        return array_map(fn (array $item): array => [...$item, 'active' => $best > 0 && $item['active'] === $best], $visible);
    }

    /** @param array{route: string, activeRoutes: list<string>} $entry */
    private function match(array $entry): int
    {
        $patterns = [$entry['route'], str_replace('.index', '.*', $entry['route']), ...$entry['activeRoutes']];
        $matched = array_filter($patterns, fn (string $pattern): bool => request()->routeIs($pattern));

        return $matched === [] ? 0 : max(array_map(strlen(...), $matched));
    }
}
