<?php

namespace App\Catalog\Infrastructure;

use Illuminate\Support\Facades\DB;

/**
 * Automatic similar products, computed live in one query so they follow every catalog change without stored lists.
 *
 * Only products of the same category are similar (a product sits in one leaf category, so no neighbouring category
 * is ever mixed in). Within it, only products the visitor may see in listings qualify, never the product itself, and
 * they are ranked by:
 *  - features: each shared option of a select/multi-select feature 3, each equal yes/no value or a number within
 *    10% of the product's own value 1 (active, applicable features only; free texts are not compared);
 *  - the same product type 1.
 * Ties prefer the closest price. A product without a category, or alone in its category, gets none.
 */
final class SimilarProducts
{
    public const LIMIT = 12;

    /** @return list<string> Product ids, most relevant first. */
    public function ids(string $product, ?string $customer = null, int $limit = self::LIMIT): array
    {
        $p = DB::table('products')->where('id', $product)->whereNull('deleted_at')->first(['id', 'category_id', 'type', 'price', 'similar_products']);
        if ($p === null || ! $p->similar_products || $p->category_id === null) {
            return [];
        }
        $listed = app(PublicCatalog::class)->listed($customer, 'carousels')->select('products.id');
        $sql = <<<'SQL'
WITH mine AS (
    SELECT a.id, a.feature_id, a.number_value, a.boolean_value FROM product_feature_assignments a
    JOIN features f ON f.id = a.feature_id AND f.status = 'active'
    WHERE a.product_id = ? AND a.is_applicable
), options AS (
    SELECT a.product_id, 3 * count(*) AS score FROM mine
    JOIN product_feature_choices own ON own.assignment_id = mine.id
    JOIN product_feature_choices other ON other.option_id = own.option_id AND other.assignment_id <> own.assignment_id
    JOIN product_feature_assignments a ON a.id = other.assignment_id AND a.is_applicable
    GROUP BY a.product_id
), vals AS (
    SELECT a.product_id, count(*) AS score FROM mine
    JOIN product_feature_assignments a ON a.feature_id = mine.feature_id AND a.is_applicable AND a.id <> mine.id
    WHERE (mine.boolean_value IS NOT NULL AND a.boolean_value = mine.boolean_value)
       OR (mine.number_value IS NOT NULL AND a.number_value IS NOT NULL AND abs(a.number_value - mine.number_value) <= abs(mine.number_value) * 0.1)
    GROUP BY a.product_id
), scored AS (
    SELECT p.id, p.price,
        coalesce(options.score, 0) + coalesce(vals.score, 0) + (CASE WHEN p.type = ? THEN 1 ELSE 0 END) AS relevance
    FROM products p
    LEFT JOIN options ON options.product_id = p.id
    LEFT JOIN vals ON vals.product_id = p.id
    WHERE p.id <> ? AND p.category_id = ?
)
SELECT scored.id FROM scored
WHERE scored.id IN (%s)
ORDER BY scored.relevance DESC, abs(scored.price - ?) ASC NULLS LAST, scored.id DESC
LIMIT ?
SQL;
        $bindings = [$p->id, $p->type, $p->id, $p->category_id, ...$listed->getBindings(), $p->price ?? 0, max(0, $limit)];

        return array_map(fn (object $r): string => (string) $r->id, DB::select(sprintf($sql, $listed->toSql()), $bindings));
    }
}
