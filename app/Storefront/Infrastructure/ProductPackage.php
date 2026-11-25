<?php

namespace App\Storefront\Infrastructure;

use App\Cart\Infrastructure\Carts;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\ProductPackages;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductPackage
{
    /** @return list<string> */
    private function ids(string $product): array
    {
        $packages = app(ProductPackages::class);
        $id = $packages->assigned($product);

        return $id === null ? [] : array_values(array_unique([$product, ...$packages->items($id)]));
    }

    /** @param array{locale:string,customer:?string} $context
     * @return array<string,mixed>|null */
    public function show(string $product, array $context): ?array
    {
        $ids = $this->ids($product);
        if ($ids === []) {
            return null;
        }
        ['cards' => $cards, 'format' => $format] = app(BlockContent::class)->productCards($ids, $context, app(PublicCatalog::class)->products($context['customer']));
        $quantities = DB::table('products')->whereIn('id', $ids)->get(['id', 'min_qty', 'qty_step'])->mapWithKeys(fn ($p): array => [(string) $p->id => (int) ($p->min_qty ?? $p->qty_step ?? 1)]);
        $available = count($cards) === count($ids);
        $total = '0';
        $items = [];
        foreach ($ids as $id) {
            $card = $cards[$id] ?? null;
            if ($card === null) {
                continue;
            }
            $available = $available && $card['available'] && $card['price'] !== null;
            $total = Decimal::add($total, Decimal::multiply($card['price'] ?? '0', (string) $quantities[$id]));
            $items[] = [...$card, 'quantity' => $quantities[$id]];
        }

        return ['name' => collect(app(ProductPackages::class)->options(app(ProductPackages::class)->assigned($product)))->firstWhere('id', app(ProductPackages::class)->assigned($product))['name'] ?? __('packages.title'), 'items' => $items, 'available' => $available, 'total' => $available ? Decimal::round($total, $format['decimals']) : null, 'currency_format' => $format];
    }

    public function add(object $cart, string $product, ?string $customer): void
    {
        DB::transaction(function () use ($cart, $product, $customer): void {
            app(CategoryHierarchy::class)->lock();
            $ids = $this->ids($product);
            if ($ids === []) {
                throw ValidationException::withMessages(['product' => __('storefront.cart_unavailable')]);
            }
            foreach ($ids as $id) {
                $p = app(Carts::class)->row($id, $customer);
                if ($p === null) {
                    throw ValidationException::withMessages(['product' => __('storefront.cart_unavailable')]);
                }
                app(Carts::class)->add($cart, $id, (int) ($p->min_qty ?? $p->qty_step ?? 1), $customer);
            }
        });
    }
}
