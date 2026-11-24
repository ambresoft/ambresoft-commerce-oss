<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Domain\Model\ReviewStatus;
use App\Catalog\Domain\Service\ReviewRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Public review data for the future storefront: rating summaries and approved reviews only. Summaries are live
 * aggregates over approved rows (partial index `product_reviews_approved`), so approving, rejecting, marking spam,
 * editing a rating or deleting a review is reflected on the next read; there is no stored total to go stale.
 * Callers decide catalog visibility (trash, Disabled, Hidden) before showing a product, as with any product data.
 */
final class ProductRatings
{
    public function __construct(private ReviewRules $rules) {}

    /** @return array{count: int, average: ?string, distribution: array{1: int, 2: int, 3: int, 4: int, 5: int}} */
    public function summary(string $productId): array
    {
        return $this->summaries([$productId])[$productId];
    }

    /**
     * Summaries for a whole product list in one grouped query; products without approved reviews get zero counts
     * and a null average.
     *
     * @param  list<string>  $productIds
     * @return array<string, array{count: int, average: ?string, distribution: array{1: int, 2: int, 3: int, 4: int, 5: int}}>
     */
    public function summaries(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(strval(...), $productIds), fn (string $id): bool => preg_match('/^[1-9][0-9]{0,17}$/D', $id) === 1)));
        $counts = [];
        if ($ids !== []) {
            DB::table('product_reviews')->where('status', ReviewStatus::Approved->value)->whereIn('product_id', $ids)
                ->groupBy('product_id', 'rating')->selectRaw('product_id, rating, count(*) as total')->get()
                ->each(function (object $r) use (&$counts): void {
                    $counts[(string) $r->product_id][(int) $r->rating] = (int) $r->total;
                });
        }
        $summaries = [];
        foreach (array_map(strval(...), $productIds) as $id) {
            $summaries[$id] = $this->rules->summary($counts[$id] ?? []);
        }

        return $summaries;
    }

    /**
     * Approved reviews of one product, newest first, with only public fields: no customer ID, e-mail, full name,
     * moderation status or internal note.
     *
     * @return array{data: list<array{id: string, rating: int, title: ?string, body: string, author: string, verified_purchase: bool, created_at: string}>, current_page: int, last_page: int, per_page: int, total: int}
     */
    public function approved(string $productId, int $page = 1, int $perPage = 10): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        if (preg_match('/^[1-9][0-9]{0,17}$/D', $productId) !== 1) {
            return ['data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0];
        }
        $query = DB::table('product_reviews as r')->join('customers as c', 'c.id', '=', 'r.customer_id')
            ->where('r.product_id', $productId)->where('r.status', ReviewStatus::Approved->value);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('r.created_at')->orderByDesc('r.id')->forPage($page, $perPage)
            ->get(['r.id', 'r.rating', 'r.title', 'r.body', 'r.verified_purchase', 'r.created_at', 'c.first_name', 'c.last_name']);

        return [
            'data' => $rows->map(fn (object $r): array => ['id' => (string) $r->id, 'rating' => (int) $r->rating, 'title' => $r->title, 'body' => $r->body,
                'author' => $this->rules->authorName($r->first_name, $r->last_name), 'verified_purchase' => (bool) $r->verified_purchase,
                'created_at' => CarbonImmutable::parse($r->created_at)->toIso8601String()])->all(),
            'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'per_page' => $perPage, 'total' => $total,
        ];
    }
}
