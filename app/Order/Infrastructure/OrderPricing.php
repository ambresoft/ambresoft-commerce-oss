<?php

namespace App\Order\Infrastructure;

use App\Catalog\Infrastructure\VariationGroupQueries;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\CouponAmounts;
use App\Marketing\Infrastructure\CouponPricing;
use App\Marketing\Infrastructure\PromotionPricing;
use App\Order\Domain\Service\OrderTotals;
use App\Pricing\Infrastructure\ProductSales;
use App\Shared\Domain\Service\Decimal;
use App\Tax\Application\Contract\TaxConfiguration;
use App\Tax\Application\Service\TaxCalculationService;
use App\Tax\Domain\Model\Address;
use App\Tax\Domain\Model\AppliedTax;
use App\Tax\Domain\Model\TaxContext;
use App\Tax\Domain\Model\TaxLine;
use App\Tax\Domain\Model\TaxResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prices order lines and totals through the existing tax service. Catalog prices are read, never written; an
 * order-specific unit price only affects this order. Lines whose product was permanently deleted keep their stored
 * snapshot and amounts and can only be removed.
 */
final class OrderPricing
{
    public function __construct(private TaxCalculationService $taxes, private OrderTotals $totals) {}

    /**
     * @param  list<array{item_id?: ?string, product_id?: ?string, quantity: int, unit_price?: ?string}>  $requested
     * @param  array<string, object>  $existing  Current lines of the order, by item ID.
     * @param  array{billing: ?array<string, mixed>, shipping: ?array<string, mixed>}  $addresses
     * @param  array{amount: string, tax_ids: list<string>, id?: ?string, available?: bool, discountable?: string}|null  $shipping  Server-calculated shipping charge and the taxes that apply to it
     * @param  ?string  $paymentFee  The payment method's fee (e.g. cash on delivery), if any
     * @return array{coupon_discount: string, coupon_shipping_discount: string, promotions: list<array<string, mixed>>, lines: list<array<string, mixed>>, taxes: list<array<string, mixed>>, subtotal: string, tax_total: string, tax_included: string, discount_total: string, shipping_total: string, coupon_id: ?string, coupon_code: ?string, shipping_discount: string, shipping_tax: array{included: string, added: string, taxes: list<array<string, mixed>>}, payment_fee: string, payment_fee_tax: array{included: string, added: string}, total: string, method: string, unresolved: list<string>}
     */
    public function quote(array $requested, array $existing, array $addresses, bool $exempt, string $localeCode, int $scale, ?string $customer = null, bool $lockProducts = false, ?array $shipping = null, ?string $couponCode = null, string $email = '', ?string $orderId = null, ?string $paymentFee = null): array
    {
        if ($requested === []) {
            throw ValidationException::withMessages(['items' => __('orders.items_required')]);
        }
        if ($lockProducts) {
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
        }
        $sales = app(ProductSales::class);
        if ($lockProducts) {
            $sales->lockProducts(array_values(array_filter([...array_map(fn (array $line): string => (string) ($line['product_id'] ?? ''), $requested), ...array_map(fn (object $item): string => (string) ($item->product_id ?? ''), $existing)])));
        }
        $lines = [];
        $seen = [];
        foreach ($requested as $index => $line) {
            $key = 'items.'.$index;
            $quantity = $line['quantity'];
            $override = isset($line['unit_price']) && $line['unit_price'] !== '' ? Decimal::round($line['unit_price'], $scale) : null;
            if (! empty($line['item_id'])) {
                $item = $existing[$line['item_id']] ?? throw ValidationException::withMessages([$key.'.item_id' => __('orders.item_invalid')]);
                if ($override !== null && Decimal::compare($override, Decimal::trim($item->unit_price)) === 0) {
                    $override = null;
                }
                $savedBasis = json_decode((string) ($item->pricing_basis ?? 'null'), true);
                $unit = $override ?? ($savedBasis['unit_price'] ?? Decimal::trim($item->unit_price));
                if ($item->product_id === null && ($quantity !== (int) $item->quantity || Decimal::compare($unit, Decimal::trim($item->unit_price)) !== 0)) {
                    throw ValidationException::withMessages([$key.'.quantity' => __('orders.item_product_deleted')]);
                }
                $saleId = $item->product_id === null || ($override !== null && Decimal::compare($override, Decimal::trim($item->unit_price)) !== 0) ? null : ($savedBasis['sale_id'] ?? $sales->allocation((string) $item->id));
                $lines[] = ['base_unit_price' => $savedBasis['base_unit_price'] ?? $unit, 'sale_id' => $saleId, 'item_id' => (string) $item->id, 'product_id' => $item->product_id === null ? null : (string) $item->product_id, 'name' => $item->name, 'sku' => $item->sku, 'variation' => $item->variation,
                    'quantity' => $quantity, 'catalog_price' => Decimal::trim($item->catalog_price), 'unit_price' => $unit, 'price_overridden' => Decimal::compare($unit, Decimal::trim($item->catalog_price)) !== 0,
                    'stored' => $item->product_id === null ? $item : null];
                $seen[(string) $item->product_id] = true;

                continue;
            }
            $productId = (string) ($line['product_id'] ?? '');
            if (isset($seen[$productId])) {
                throw ValidationException::withMessages([$key.'.product_id' => __('orders.item_duplicate')]);
            }
            $product = $productId === '' ? null : DB::table('products')->where('id', $productId)->whereNull('deleted_at')->first(['id', 'sku', 'price']);
            if ($product === null || $product->price === null) {
                throw ValidationException::withMessages([$key.'.product_id' => __('orders.product_unavailable')]);
            }
            $seen[$productId] = true;
            $catalog = Decimal::round((string) $product->price, $scale);
            $offer = $override === null ? $sales->price($productId, $catalog, $quantity, $customer, $scale) : null;
            $base = $override ?? $catalog;
            $catalog = $offer['price'] ?? $catalog;
            $unit = $override ?? $catalog;
            $lines[] = ['base_unit_price' => $base, 'sale_id' => $offer['id'] ?? null, 'item_id' => null, 'product_id' => $productId, 'name' => $this->productName($productId, $localeCode), 'sku' => $product->sku, 'variation' => $this->variation($productId),
                'quantity' => $quantity, 'catalog_price' => $catalog, 'unit_price' => $unit, 'price_overridden' => Decimal::compare($unit, $catalog) !== 0, 'stored' => null];
        }

        $context = new TaxContext($this->address($addresses['shipping'] ?? $addresses['billing'] ?? null), $this->address($addresses['billing'] ?? null), $exempt);
        $priced = [];
        foreach ($lines as $index => $line) {
            if ($line['stored'] === null) {
                $priced[$index] = new TaxLine($line['product_id'], $line['unit_price'], $line['quantity']);
            }
        }
        $original = $priced === [] ? null : $this->taxes->forLines(array_values($priced), $context);
        $basis = array_reduce($lines, fn (string $sum, array $l): string => Decimal::add($sum, Decimal::multiply($l['unit_price'], (string) $l['quantity'])), '0');
        $includedBefore = array_reduce($original->groups ?? [], fn (string $sum, TaxResult $r): string => Decimal::add($sum, $r->includedTax), '0');
        $coupon = app(CouponPricing::class)->quote($couponCode, $lines, Decimal::subtract($basis, $includedBefore), $customer, $email, $scale, $orderId, $lockProducts);
        if (($coupon['id'] !== null && count($priced) !== count($lines)) || collect($lines)->contains(fn (array $l): bool => $l['stored'] !== null && Decimal::compare((string) ($l['stored']->discount_total ?? '0'), '0') > 0)) {
            throw ValidationException::withMessages(['coupon_code' => __('coupons.deleted_product')]);
        }
        foreach ($lines as &$line) {
            $line['pricing_basis'] = ['unit_price' => $line['unit_price'], 'base_unit_price' => $line['base_unit_price'], 'sale_id' => $line['sale_id']];
        }
        unset($line);
        $marketing = app(PromotionPricing::class)->apply($lines, Decimal::subtract($basis, $includedBefore), $customer, $shipping, $coupon, $scale, $localeCode);
        $lines = $marketing['lines'];
        $hasDiscount = collect($marketing['discounts'])->contains(fn (string $d): bool => Decimal::compare($d, '0') > 0);
        $positions = array_keys($priced);
        if ($hasDiscount) {
            $priced = $positions = [];
            foreach ($lines as $index => $line) {
                if ($line['stored'] !== null) {
                    continue;
                }
                $net = Decimal::subtract(Decimal::multiply($line['unit_price'], (string) $line['quantity']), $marketing['discounts'][$index]);
                foreach (app(CouponAmounts::class)->units($net, $line['quantity'], $scale) as $part) {
                    $positions[] = $index;
                    $priced[] = new TaxLine($line['product_id'], $part['price'], $part['quantity']);
                }
            }
        }
        $priced = array_values($priced);
        $result = ! $hasDiscount ? $original : ($priced === [] ? null : $this->taxes->forLines($priced, $context));
        $zero = Decimal::round('0', $scale);
        $lineTax = array_fill(0, count($lines), ['total' => $zero, 'included' => $zero]);
        // Each tax group's value and taxes, for charges that follow the goods (the payment fee).
        $groupValues = [];
        $groupTaxIds = [];
        if ($result !== null) {
            $members = [];
            foreach ($result->lines as $pricedIndex => $group) {
                $members[$group][] = $pricedIndex;
            }
            foreach ($members as $group => $indexes) {
                /** @var TaxResult $groupResult */
                $groupResult = $result->groups[$group];
                $weights = array_map(fn (int $i): string => Decimal::multiply($priced[$i]->unitPrice, (string) $priced[$i]->quantity), $indexes);
                $groupValues[$group] = array_reduce($weights, fn (string $sum, string $w): string => Decimal::add($sum, $w), '0');
                $groupTaxIds[$group] = array_values(array_unique(array_map(fn (AppliedTax $t): string => $t->taxId, $groupResult->taxes)));
                // A tax computed for several lines together (subtotal method) is attributed to them exactly.
                $included = $this->totals->allocate($groupResult->includedTax, $weights, $scale);
                $added = $this->totals->allocate($groupResult->addedTax, $weights, $scale);
                foreach ($indexes as $n => $i) {
                    $position = $positions[$i];
                    $lines[$position]['pricing_basis']['tax_rates'] = array_map(fn (AppliedTax $t): array => ['type' => $t->rateType, 'value' => $t->rateValue, 'included' => $t->included, 'priority' => $t->priority], array_values(array_filter($groupResult->taxes, fn (AppliedTax $t): bool => $t->status === AppliedTax::APPLIED && $t->rateType !== null && $t->rateValue !== null)));
                    $lineTax[$position] = ['total' => Decimal::add($lineTax[$position]['total'], Decimal::add($included[$n], $added[$n])), 'included' => Decimal::add($lineTax[$position]['included'], $included[$n])];
                }
            }
        }

        $subtotal = $taxTotal = $taxIncluded = $zero;
        foreach ($lines as $index => &$line) {
            $stored = $line['stored'];
            $line['subtotal'] = Decimal::round(Decimal::multiply($line['unit_price'], (string) $line['quantity']), $scale);
            $line['tax_total'] = $stored === null ? $lineTax[$index]['total'] : Decimal::trim($stored->tax_total);
            $line['tax_included'] = $stored === null ? $lineTax[$index]['included'] : Decimal::trim($stored->tax_included);
            $line['discount_total'] = $stored === null ? $marketing['discounts'][$index] : Decimal::trim((string) ($stored->discount_total ?? '0'));
            $line['total'] = Decimal::add(Decimal::subtract($line['subtotal'], $line['discount_total']), Decimal::subtract($line['tax_total'], $line['tax_included']));
            unset($line['stored']);
            $subtotal = Decimal::add($subtotal, $line['subtotal']);
            $taxTotal = Decimal::add($taxTotal, $line['tax_total']);
            $taxIncluded = Decimal::add($taxIncluded, $line['tax_included']);
        }
        unset($line);

        $taxes = [];
        $unresolved = [];
        $registrations = DB::table('taxes')->whereIn('id', array_map(fn (AppliedTax $t): string => $t->taxId, $result->taxes ?? []))->pluck('registration_number', 'id');
        foreach ($result->taxes ?? [] as $tax) {
            if ($tax->status === AppliedTax::UNRESOLVED) {
                $unresolved[] = $tax->name;

                continue;
            }
            $taxes[] = ['tax_id' => $tax->taxId, 'name' => $tax->name, 'priority' => $tax->priority, 'included' => $tax->included, 'rate_type' => $tax->rateType, 'rate_value' => $tax->rateValue, 'amount' => Decimal::round($tax->amount, $scale), 'registration_number' => $registrations[$tax->taxId] ?? null];
        }
        $discount = array_reduce($lines, fn (string $sum, array $l): string => Decimal::add($sum, $l['discount_total']), $zero);
        // A charge's taxes join the order's tax lines (merged per tax); an included tax is already inside the charge, an
        // added one comes on top. Returns the charge's own tax rows.
        $charge = function (TaxResult $charged) use (&$taxes, &$unresolved, &$taxTotal, &$taxIncluded, $scale): array {
            $numbers = DB::table('taxes')->whereIn('id', array_map(fn (AppliedTax $t): string => $t->taxId, $charged->taxes))->pluck('registration_number', 'id');
            $rows = [];
            foreach ($charged->taxes as $tax) {
                if ($tax->status === AppliedTax::UNRESOLVED) {
                    $unresolved[] = $tax->name;

                    continue;
                }
                $row = ['tax_id' => $tax->taxId, 'name' => $tax->name, 'priority' => $tax->priority, 'included' => $tax->included, 'rate_type' => $tax->rateType, 'rate_value' => $tax->rateValue, 'amount' => Decimal::round($tax->amount, $scale), 'registration_number' => $numbers[$tax->taxId] ?? null];
                $rows[] = $row;
                $merged = false;
                foreach ($taxes as &$existingTax) {
                    if ((string) $existingTax['tax_id'] === (string) $tax->taxId) {
                        $existingTax['amount'] = Decimal::add($existingTax['amount'], $row['amount']);
                        $merged = true;
                    }
                }
                unset($existingTax);
                if (! $merged) {
                    $taxes[] = $row;
                }
            }
            usort($taxes, fn (array $a, array $b): int => [$a['priority'], (int) $a['tax_id']] <=> [$b['priority'], (int) $b['tax_id']]);
            $taxTotal = Decimal::add($taxTotal, Decimal::add(Decimal::round($charged->includedTax, $scale), Decimal::round($charged->addedTax, $scale)));
            $taxIncluded = Decimal::add($taxIncluded, Decimal::round($charged->includedTax, $scale));

            return $rows;
        };
        // Shipping is taxed like any amount: the method's taxes, the order's addresses and exemption.
        $shippingTotal = $shipping === null ? $zero : Decimal::round($shipping['amount'], $scale);
        $shippingDiscount = $marketing['shipping_discount'];
        $shippingTotal = Decimal::subtract($shippingTotal, $shippingDiscount);
        $shippingTax = ['included' => $zero, 'added' => $zero, 'taxes' => []];
        if ($shipping !== null && $shipping['tax_ids'] !== [] && Decimal::compare($shippingTotal, '0') > 0) {
            $charged = $this->taxes->forTaxes($shipping['tax_ids'], $shippingTotal, $context);
            $shippingTax['included'] = Decimal::round($charged->includedTax, $scale);
            $shippingTax['added'] = Decimal::round($charged->addedTax, $scale);
            $shippingTax['taxes'] = $charge($charged);
        }
        // A payment method's fee (e.g. cash on delivery) is an incidental expense of the goods: it has no VAT rate of its
        // own but follows the order's products, split across their taxes by value (Greek VAT Code, Law 5144/2024 art. 24
        // par. 4(a); AADE e-commerce guide 2023, Q11-12). A share falling on untaxed goods carries no tax.
        $feeAmount = $paymentFee === null ? $zero : Decimal::round($paymentFee, $scale);
        $feeTax = ['included' => $zero, 'added' => $zero];
        if (Decimal::compare($feeAmount, '0') > 0 && $groupValues !== []) {
            $shares = $this->totals->allocate($feeAmount, array_values($groupValues), $scale);
            foreach (array_keys($groupValues) as $n => $group) {
                if ($groupTaxIds[$group] === [] || Decimal::compare($shares[$n], '0') <= 0) {
                    continue;
                }
                $charged = $this->taxes->forTaxes($groupTaxIds[$group], $shares[$n], $context);
                $feeTax['included'] = Decimal::add($feeTax['included'], Decimal::round($charged->includedTax, $scale));
                $feeTax['added'] = Decimal::add($feeTax['added'], Decimal::round($charged->addedTax, $scale));
                $charge($charged);
            }
        }

        return [
            'coupon_id' => $marketing['coupon_applied'] ? $coupon['id'] : null, 'coupon_code' => $marketing['coupon_applied'] ? $coupon['code'] : null, 'shipping_discount' => $shippingDiscount,
            'coupon_discount' => $marketing['coupon_discount'], 'coupon_shipping_discount' => $marketing['coupon_shipping_discount'], 'promotions' => $marketing['promotions'],
            'lines' => $lines, 'taxes' => $taxes, 'subtotal' => $subtotal, 'tax_total' => $taxTotal, 'tax_included' => $taxIncluded,
            'discount_total' => $discount, 'shipping_total' => $shippingTotal, 'shipping_tax' => $shippingTax, 'payment_fee' => $feeAmount, 'payment_fee_tax' => $feeTax,
            'total' => $this->totals->total($subtotal, $discount, $taxTotal, $taxIncluded, Decimal::add($shippingTotal, $feeAmount)),
            'method' => $result->method ?? app(TaxConfiguration::class)->settings()->method, 'unresolved' => array_values(array_unique($unresolved)),
        ];
    }

    /** @param array<string, mixed>|null $address */
    private function address(?array $address): ?Address
    {
        return $address === null ? null : new Address($address['country_code'] ?? null, $address['region_code'] ?? null);
    }

    private function productName(string $productId, string $locale): string
    {
        $catalog = app(LocaleCatalog::class);
        $names = DB::table('product_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.product_id', $productId)->pluck('t.name', 'l.code');
        $name = trim((string) ($names[$locale] ?? '')) ?: trim((string) ($names[$catalog->defaultCode()] ?? ''));

        return mb_substr($name !== '' ? $name : __('products.untitled', ['id' => $productId]), 0, 200);
    }

    /** "Colour: Red · Size: M" for a member of a variation group, from its defining features. */
    private function variation(string $productId): ?string
    {
        $group = app(VariationGroupQueries::class)->groupsFor([$productId])[$productId] ?? null;
        if ($group === null) {
            return null;
        }
        $detail = app(VariationGroupQueries::class)->detail($group['id']);
        /** @var list<array<string, mixed>> $members */
        $members = $detail['members'];
        $member = collect($members)->firstWhere('id', $productId);
        if ($member === null) {
            return null;
        }
        $parts = [];
        foreach ($detail['features'] as $feature) {
            $value = ((array) $member['values'])[$feature['id']] ?? null;
            if (is_array($value)) {
                $parts[] = $feature['name'].': '.$value['label'];
            }
        }

        return $parts === [] ? null : mb_substr(implode(' · ', $parts), 0, 500);
    }
}
