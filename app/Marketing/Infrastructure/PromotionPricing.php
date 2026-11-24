<?php

namespace App\Marketing\Infrastructure;

use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\ProductCategories;
use App\Marketing\Domain\Service\CouponAmounts;
use App\Marketing\Domain\Service\PromotionRules;
use App\Order\Domain\Service\OrderTotals;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One calculation over original inputs. Quotes never reserve usage. */
final class PromotionPricing
{
    /** @param list<array<string, mixed>> $lines
     * @param  array<string, mixed>  $coupon  Already validated by the existing coupon service.
     * @param  array<string, mixed>|null  $shipping  A real server quote, never a synthetic free method.
     * @return array{lines: list<array<string, mixed>>, discounts: list<string>, shipping_discount: string, coupon_discount: string, coupon_shipping_discount: string, promotions: list<array<string, mixed>>, coupon_applied: bool}
     */
    public function apply(array $lines, string $subtotal, ?string $customer, ?array $shipping, array $coupon, int $scale, string $locale = 'en'): array
    {
        $definitions = app(PromotionQueries::class)->definitions(pricing: true, coupon: $coupon['id'], locale: $locale);
        $rules = app(PromotionRules::class);
        $instant = now()->utc()->format('Y-m-d H:i:s');
        $products = DB::table('products')->whereIn('id', array_column($lines, 'product_id'))->whereNull('deleted_at')->where('status', 'active')->get(['id', 'category_id'])->keyBy('id');
        // Group restrictions require an actual customer. Guests never inherit the default group's restrictions.
        $groups = $customer === null ? [] : DB::table('customer_group_members as m')->join('customer_groups as g', 'g.id', '=', 'm.group_id')->join('customers as c', 'c.id', '=', 'm.customer_id')->where('c.id', $customer)->where('c.status', 'active')->where('g.status', 'active')->pluck('g.id')->map(strval(...))->all();
        $categoryCache = [];
        $categories = function (array $ids) use (&$categoryCache): array {
            $ids = array_values(array_filter($ids));
            sort($ids);
            $key = implode(',', $ids);
            if (! isset($categoryCache[$key])) {
                $categoryCache[$key] = app(CategoryHierarchy::class)->activeDescendants($ids);
            }

            return $categoryCache[$key];
        };
        // A product is in a targeted category by its main or any extra category.
        $productCategories = app(ProductCategories::class)->all($products->keys()->all());
        $targeted = function (array $productIds, array $categoryIds, array $line) use ($products, $categories, $productCategories): bool {
            $product = $products[$line['product_id']] ?? null;

            return $product !== null && (in_array((string) $product->id, $productIds, true) || array_intersect($productCategories[(string) $product->id] ?? [], $categories($categoryIds)) !== []);
        };
        $method = ($shipping['available'] ?? true) ? ($shipping['id'] ?? null) : null;
        if ($method !== null && ! DB::table('shipping_methods')->where('id', $method)->where('is_active', true)->exists()) {
            $method = null;
        }
        $matches = function (array $p) use ($rules, $subtotal, $lines, $groups, $method, $products, $targeted): bool {
            $matches = [];
            foreach ($p['conditions'] as $c) {
                $present = array_values(array_filter(array_map(fn (array $l): ?string => isset($products[$l['product_id']]) ? $l['product_id'] : null, $lines)));
                $matches[] = match ($c['type']) {
                    'subtotal' => $rules->subtotal($subtotal, $c['minimum'], $c['maximum']),
                    'products' => $c['products'] !== [] && ($c['match'] === 'all' ? ! in_array(null, $c['products'], true) && array_diff($c['products'], $present) === [] : array_intersect($c['products'], $present) !== []),
                    'categories' => collect($lines)->contains(fn (array $l): bool => $targeted([], $c['categories'], $l)),
                    'groups' => array_intersect($c['groups'], $groups) !== [],
                    'shipping' => $method !== null && in_array((string) $method, $c['shipping'], true),
                    'quantity' => array_sum(array_map(fn (array $l): int => $c['quantity_scope'] === 'cart' || $targeted($c['quantity_scope'] === 'products' ? $c['products'] : [], $c['quantity_scope'] === 'categories' ? $c['categories'] : [], $l) ? $l['quantity'] : 0, $lines)) >= (int) $c['quantity'],
                    default => false,
                };
            }

            return $rules->matches($p['match'], $matches);
        };
        $linked = null;
        foreach ($definitions as $p) {
            if ($coupon['id'] !== null && $p['coupon_id'] === $coupon['id']) {
                $linked = $p;
                if (! $rules->active($p['status'], $p['starts_utc'], $p['ends_utc'], $instant) || ! $matches($p)) {
                    throw ValidationException::withMessages(['coupon_code' => __('promotions.coupon_ineligible')]);
                }
            }
        }
        $zero = Decimal::round('0', $scale);
        $discounts = array_fill(0, count($lines), $zero);
        $lineGranted = [];
        $applied = [];
        $shippingAmount = $shipping === null ? $zero : Decimal::round($shipping['amount'], $scale);
        $shippingDiscount = $couponDiscount = $couponShipping = $zero;
        $grantCoupon = function () use (&$discounts, &$shippingDiscount, &$couponDiscount, &$couponShipping, &$lines, $coupon, $shippingAmount, $linked, $scale): array {
            $allocations = [];
            $couponParts = $coupon['discounts'];
            if ($linked !== null) {
                $weights = array_map(fn (array $l, int $n): string => $coupon['eligible'][$n] ? Decimal::subtract(Decimal::multiply($l['unit_price'], (string) $l['quantity']), $discounts[$n]) : '0', $lines, array_keys($lines));
                $total = array_reduce($weights, Decimal::add(...), '0');
                if (Decimal::compare($total, '0') > 0) {
                    $couponParts = app(OrderTotals::class)->allocate(app(CouponAmounts::class)->discount($coupon['type'], $coupon['amount'], $total, $scale), $weights, $scale);
                }
            }
            foreach ($lines as $n => $line) {
                $remaining = Decimal::subtract(Decimal::multiply($line['unit_price'], (string) $line['quantity']), $discounts[$n]);
                $allocation = Decimal::compare($couponParts[$n], $remaining) > 0 ? $remaining : $couponParts[$n];
                $discounts[$n] = Decimal::add($discounts[$n], $allocation);
                $couponDiscount = Decimal::add($couponDiscount, $allocation);
                $allocations[$n] = $allocation;
            }
            if ($coupon['free_shipping']) {
                $couponShipping = Decimal::subtract($shippingAmount, $shippingDiscount);
                $shippingDiscount = $shippingAmount;
            }

            return $allocations;
        };
        // Standalone coupons retain their established first, post-product-sale position.
        if ($coupon['id'] !== null && $linked === null) {
            $grantCoupon();
        }
        $stopPriority = null;
        foreach ($definitions as $p) {
            if ($stopPriority !== null && (int) $p['priority'] > $stopPriority) {
                break;
            }
            if (! $rules->active($p['status'], $p['starts_utc'], $p['ends_utc'], $instant) || ($p['mode'] === 'coupon' && $p['id'] !== ($linked['id'] ?? null)) || ! $matches($p)) {
                continue;
            }
            if ($p['mode'] === 'automatic' && collect(['products', 'categories', 'shipping', 'excluded_products', 'excluded_categories'])->contains(fn (string $field): bool => in_array(null, $p[$field], true))) {
                continue;
            }
            $allocations = array_fill(0, count($lines), $zero);
            $shippingBenefit = $zero;
            if ($p['mode'] === 'coupon') {
                $before = $shippingDiscount;
                $allocations = $grantCoupon();
                $shippingBenefit = Decimal::subtract($shippingDiscount, $before);
            } elseif ($p['benefit'] === 'free_shipping') {
                $validMethod = $method === null ? null : DB::table('shipping_methods')->where('id', $method)->where('is_active', true)->where('free_shipping_applies', true)->first();
                if ($validMethod !== null && ($shipping['available'] ?? true) && ($p['all_shipping'] || in_array((string) $method, $p['shipping'], true))) {
                    $discountable = $shipping['discountable'] ?? $shippingAmount;
                    $discountable = Decimal::compare($discountable, $shippingAmount) > 0 ? $shippingAmount : $discountable;
                    $shippingBenefit = Decimal::compare($discountable, $shippingDiscount) > 0 ? Decimal::subtract($discountable, $shippingDiscount) : $zero;
                    $shippingDiscount = Decimal::add($shippingDiscount, $shippingBenefit);
                }
            } else {
                $weights = [];
                foreach ($lines as $n => $line) {
                    $eligible = $line['stored'] === null;
                    if ($p['scope'] === 'products') {
                        $eligible = $eligible && Decimal::compare(Decimal::subtract(Decimal::multiply($line['unit_price'], (string) $line['quantity']), $discounts[$n]), '0') > 0 && ! isset($lineGranted[$n]) && (! $p['exclude_sale'] || $line['sale_id'] === null)
                            && ($p['all_products'] || $targeted($p['products'], $p['categories'], $line))
                            && ! $targeted($p['excluded_products'], $p['excluded_categories'], $line);
                    }
                    $weights[$n] = ! $eligible ? $zero : ($p['scope'] === 'products' ? Decimal::multiply($line['base_unit_price'], (string) $line['quantity']) : Decimal::subtract(Decimal::multiply($line['unit_price'], (string) $line['quantity']), $discounts[$n]));
                }
                $eligibleTotal = array_reduce($weights, Decimal::add(...), $zero);
                $discount = app(CouponAmounts::class)->discount($p['benefit'], $p['amount'], $eligibleTotal, $scale);
                $parts = Decimal::compare($eligibleTotal, '0') <= 0 ? $allocations : app(OrderTotals::class)->allocate($discount, $weights, $scale);
                foreach ($lines as $n => &$line) {
                    if ($p['scope'] === 'products' && Decimal::compare($parts[$n], '0') > 0) {
                        $base = Decimal::multiply($line['base_unit_price'], (string) $line['quantity']);
                        $current = Decimal::multiply($line['unit_price'], (string) $line['quantity']);
                        // Compare the resulting product price with the active sale; never multiply a sale price again.
                        if (Decimal::compare(Decimal::subtract($base, $parts[$n]), $current) >= 0) {
                            continue;
                        }
                        $line['unit_price'] = $line['base_unit_price'];
                        $line['sale_id'] = null;
                        $lineGranted[$n] = true;
                    }
                    $remaining = Decimal::subtract(Decimal::multiply($line['unit_price'], (string) $line['quantity']), $discounts[$n]);
                    $allocation = Decimal::compare($parts[$n], $remaining) > 0 ? $remaining : $parts[$n];
                    $allocations[$n] = $allocation;
                    $discounts[$n] = Decimal::add($discounts[$n], $allocation);
                }
                unset($line);
            }
            $amount = array_reduce($allocations, Decimal::add(...), $zero);
            if (Decimal::compare(Decimal::add($amount, $shippingBenefit), '0') <= 0) {
                continue;
            }
            $applied[] = ['promotion_id' => $p['id'], 'name' => $p['name'], 'coupon_code' => $p['mode'] === 'coupon' ? $coupon['code'] : null, 'benefit' => $p['benefit'], 'discount' => $amount, 'shipping_discount' => $shippingBenefit, 'allocations' => $allocations];
            if ($p['stop_other']) {
                $stopPriority = (int) $p['priority'];
            }
        }

        return ['lines' => $lines, 'discounts' => $discounts, 'shipping_discount' => $shippingDiscount, 'coupon_discount' => $couponDiscount, 'coupon_shipping_discount' => $couponShipping, 'promotions' => $applied, 'coupon_applied' => $linked === null || Decimal::compare(Decimal::add($couponDiscount, $couponShipping), '0') > 0];
    }
}
