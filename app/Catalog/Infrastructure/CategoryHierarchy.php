<?php

namespace App\Catalog\Infrastructure;

use Illuminate\Support\Facades\DB;
use LogicException;

final class CategoryHierarchy
{
    public function lock(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Category tree mutation requires a transaction.');
        }
        DB::select('SELECT pg_advisory_xact_lock(2142513)');
    }

    /** @return list<string> Root through category, including self. */
    public function pathIds(string $categoryId): array
    {
        return array_map(fn ($row): string => (string) $row->id, DB::select(<<<'SQL'
WITH RECURSIVE path AS (
    SELECT id, parent_id, 0 AS depth FROM categories WHERE id = ?
    UNION ALL
    SELECT c.id, c.parent_id, p.depth + 1 FROM categories c JOIN path p ON c.id = p.parent_id
) SELECT id FROM path ORDER BY depth DESC
SQL, [$categoryId]));
    }

    /** @return list<string> Self and every descendant; no fixed depth. */
    public function descendantIds(string $categoryId): array
    {
        return array_map(fn ($row): string => (string) $row->id, DB::select(<<<'SQL'
WITH RECURSIVE subtree AS (
    SELECT id FROM categories WHERE id = ?
    UNION ALL
    SELECT c.id FROM categories c JOIN subtree s ON c.parent_id = s.id
) SELECT id FROM subtree
SQL, [$categoryId]));
    }

    /** Active self and descendants for Marketing targeting.
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function activeDescendants(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_map(fn (object $r): string => (string) $r->id, DB::select("WITH RECURSIVE selected AS (SELECT id FROM categories WHERE status = 'active' AND id IN (".implode(',', array_fill(0, count($ids), '?')).") UNION SELECT c.id FROM categories c JOIN selected s ON c.parent_id = s.id WHERE c.status = 'active') SELECT id FROM selected", $ids));
    }

    /** Batch ancestor identities, stopping at inactive categories.
     * @param  array<int, string>  $ids
     * @return array<int|string, list<string>>
     */
    public function activeAncestorsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        // Positional bindings need a gap-free list (callers pass the result of unique()).
        $ids = array_values($ids);
        $rows = DB::select("WITH RECURSIVE path AS (SELECT id as leaf, id, parent_id FROM categories WHERE status = 'active' AND id IN (".implode(',', array_fill(0, count($ids), '?')).") UNION ALL SELECT p.leaf, c.id, c.parent_id FROM categories c JOIN path p ON c.id = p.parent_id WHERE c.status = 'active') SELECT leaf, id FROM path", $ids);
        $result = [];
        foreach ($rows as $r) {
            $result[$r->leaf][] = (string) $r->id;
        }

        return $result;
    }

    public function isLeaf(string $categoryId): bool
    {
        return DB::table('categories')->where('id', $categoryId)->whereNull('system_key')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('categories as children')->whereColumn('children.parent_id', 'categories.id'))->exists();
    }
}
