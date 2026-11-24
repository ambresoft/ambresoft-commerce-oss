<?php

namespace App\Search\Infrastructure;

use App\Catalog\Infrastructure\PublicCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Who may find what, decided from the database on every search, so a change applies to the very next query, results,
 * counts and facets alike, without waiting for the index:
 *
 * - categories: visible to the visitor (active ancestry, customer groups), leaf categories (where products are
 *   published) and not excluded from search — a category marked "Exclude from search and highlights" excludes its
 *   whole subtree, like a disabled or restricted ancestor hides its descendants;
 * - hidden products: queued visibility-critical changes not yet confirmed in the index (fail closed).
 */
final class SearchVisibility
{
    /** Hidden products passed to the engine at most; beyond it counts are withheld (results stay checked). */
    public const HIDDEN_LIMIT = 1000;

    /** @var array<string, list<string>> */
    private array $categories = [];

    /** @return list<string> */
    public function categories(?string $customer): array
    {
        return $this->categories[$customer ?? ''] ??= app(PublicCatalog::class)->categories($customer)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('categories as child')->whereColumn('child.parent_id', 'categories.id'))
            ->whereRaw('NOT EXISTS (WITH RECURSIVE path AS (SELECT c.id, c.parent_id, c.exclude_from_discovery FROM categories c WHERE c.id = categories.id
                UNION ALL SELECT c.id, c.parent_id, c.exclude_from_discovery FROM categories c JOIN path ON c.id = path.parent_id) SELECT 1 FROM path WHERE path.exclude_from_discovery)')
            ->orderBy('categories.id')->pluck('categories.id')->map(strval(...))->all();
    }

    /**
     * Products with an unconfirmed visibility-critical change, and whether there were more than can be passed on.
     *
     * @return array{0: list<string>, 1: bool}
     */
    public function hidden(): array
    {
        $ids = DB::table('search_outbox')->where('critical', true)->orderBy('product_id')->limit(self::HIDDEN_LIMIT + 1)->pluck('product_id')->map(strval(...))->all();

        return [array_slice($ids, 0, self::HIDDEN_LIMIT), count($ids) > self::HIDDEN_LIMIT];
    }
}
