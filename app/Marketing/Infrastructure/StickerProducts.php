<?php

namespace App\Marketing\Infrastructure;

use App\Catalog\Domain\Service\AvailabilityRules;
use App\Catalog\Domain\Service\ProductRules;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Pricing\Infrastructure\ProductSales;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Batch sticker facts for guest context. No storefront routes or presentation are introduced. */
final class StickerProducts
{
    /** Resolve a page of product identities in one batch, for the future React/Inertia catalog integration.
     * @param  list<string>  $ids
     * @return array<int|string, list<array<string, mixed>>>
     */
    public function forProducts(array $ids, string $locale, string $context = 'card', ?string $customer = null): array
    {
        if ($ids === [] || ! in_array($context, ['card', 'detail'], true)) {
            return [];
        }
        $products = app(PublicCatalog::class)->products($customer)->whereIn('products.id', $ids)->select('products.*')->get();

        return app(StickerDisplay::class)->forProducts($products, $this->facts($products), $locale, $context);
    }

    /** The given products that carry each sticker (guest prices, no display limits), for sticker shopping filters.
     * @param  list<int|string>  $ids
     * @param  list<int|string>  $stickerIds
     * @return array<string, list<int>>
     */
    public function holders(array $ids, array $stickerIds, ?string $customer = null): array
    {
        if ($ids === [] || $stickerIds === []) {
            return [];
        }
        $products = app(PublicCatalog::class)->products($customer)->whereIn('products.id', $ids)->select('products.*')->get();

        return app(StickerDisplay::class)->holders($products, $this->facts($products), $stickerIds);
    }

    /** @param Collection<int, \stdClass> $products
     * @return array<int|string, array<string, mixed>>
     */
    private function facts(Collection $products): array
    {
        $settings = app(GeneralSettings::class)->all();
        $rules = app(ProductRules::class);
        $scale = app(CurrencyRepository::class)->currentPrimary()->decimals;
        $requests = [];
        foreach ($products as $p) {
            $requests[$p->id] = ['base' => $p->price, 'quantity' => $this->purchaseQuantity($p)];
        }
        $sales = app(ProductSales::class)->prices($requests, null, $scale);
        $result = [];
        $instant = now();
        foreach ($products as $p) {
            $price = $sales[$p->id]['price'] ?? $p->price;
            $discount = Decimal::subtract($p->price, $price);
            $available = $p->available_from === null || $instant->timezone($settings['timezone'])->toDateString() >= $p->available_from;
            $result[$p->id] = ['price' => $price, 'discount_amount' => $discount, 'discount_percent' => Decimal::compare($p->price, '0') > 0 ? bcdiv(bcmul($discount, '100', 12), $p->price, 6) : '0',
                'quantity' => $settings['track_inventory'] ? (string) $p->quantity : null,
                'in_stock' => AvailabilityRules::bucket((int) $p->quantity, (bool) $settings['track_inventory']) === 'in_stock',
                'purchasable' => $available && $rules->canPurchase($this->purchaseQuantity($p), (int) $p->quantity, $rules->effectiveOutOfStockAction($p->out_of_stock_actions, $settings['out_of_stock_action']), $p->min_qty, $p->max_qty, $p->qty_step, (bool) $settings['track_inventory']),
                'age_seconds' => $instant->getTimestamp() - CarbonImmutable::parse($p->created_at)->getTimestamp(), 'free_shipping' => (bool) $p->free_shipping];
        }

        return $result;
    }

    private function purchaseQuantity(object $p): int
    {
        return (int) ($p->min_qty ?? $p->qty_step ?? 1);
    }
}
