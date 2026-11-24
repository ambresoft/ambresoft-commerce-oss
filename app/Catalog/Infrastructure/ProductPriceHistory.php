<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Pricing\Application\Service\PriceDisplay;
use App\Shared\Domain\Service\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Append-only record of a product's effective price and list price. A row is written inside the product's own
 * transaction whenever a save leaves a different pair than the latest row; drafts without a price have no history.
 * Primary-currency changes re-denominate the rows instead of recording a change.
 */
final class ProductPriceHistory
{
    /** Bounds the editor payload; older rows stay stored. */
    private const SERIES_LIMIT = 2000;

    public function record(ProductRecord $p, ?string $actor): void
    {
        if ($p->price === null) {
            return;
        }
        $last = DB::table('product_price_history')->where('product_id', $p->id)->orderByDesc('recorded_at')->orderByDesc('id')->first(['price', 'list_price']);
        if ($last !== null && $this->same($last->price, $p->price) && $this->same($last->list_price, $p->list_price)) {
            return;
        }
        DB::table('product_price_history')->insert(['product_id' => $p->id, 'price' => $p->price, 'list_price' => $p->list_price, 'recorded_at' => now(), 'actor_id' => $actor]);
    }

    /** @return list<array{at: string, price: string, list_price: ?string}> Oldest first, at the primary currency's precision. */
    public function series(string $productId): array
    {
        $scale = app(PriceDisplay::class)->primary()->decimals;
        $rows = DB::table('product_price_history')->where('product_id', $productId)->orderByDesc('recorded_at')->orderByDesc('id')->limit(self::SERIES_LIMIT)->get(['price', 'list_price', 'recorded_at']);

        return $rows->reverse()->values()->map(fn (object $row): array => [
            'at' => CarbonImmutable::parse($row->recorded_at)->toIso8601String(),
            'price' => Decimal::atLeastScale((string) $row->price, $scale),
            'list_price' => $row->list_price === null ? null : Decimal::atLeastScale((string) $row->list_price, $scale),
        ])->all();
    }

    private function same(?string $stored, ?string $current): bool
    {
        return $stored === null || $current === null ? $stored === $current : bccomp($stored, $current, 6) === 0;
    }
}
