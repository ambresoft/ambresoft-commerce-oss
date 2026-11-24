<?php

namespace App\Storefront\Infrastructure;

use App\Cart\Infrastructure\Carts;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\CouponAmounts;
use App\Marketing\Domain\Service\GiftCardRules;
use App\Order\Domain\Service\OrderTotals;
use App\Order\Infrastructure\OrderPricing;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Tax\Application\Contract\CustomerTaxProfiles;
use App\Tax\Application\Service\TaxCalculationService;
use App\Tax\Domain\Model\TaxContext;
use App\Tax\Domain\Model\TaxLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a cart shows: its lines as storefront product cards (the same live prices, sales, customer group prices and tax
 * display as everywhere else) with their quantities, the quantity rules each line's stepper follows, and the totals.
 * A line that cannot be bought as it stands (unavailable, out of stock, a quantity its rules no longer allow) says why
 * and is left out of the totals. Lines of products the visitor can no longer see are not shown. The coupon and gift card
 * the visitor entered are priced here too, or say why they do not apply; neither is redeemed until an order is placed.
 */
final class CartPage
{
    /**
     * @param  array{locale:string,customer:?string}  $context
     * @return array{id:string|null,lines:list<array<string,mixed>>,count:int,subtotal:string,subtotal_without_tax:?string,coupon:array<string,mixed>|null,promotions:list<array{id:string,name:string,description:string,discount:string}>,gift_card:array<string,mixed>|null,total:string,total_without_tax:?string,codes:bool,currency_format:array<string,mixed>}
     */
    public function data(?object $cart, array $context): array
    {
        $items = $cart === null ? collect() : DB::table('cart_items')->where('cart_id', $cart->id)->orderBy('created_at')->orderBy('id')->get(['product_id', 'quantity']);
        $ids = $items->pluck('product_id')->map(strval(...))->all();
        ['cards' => $cards, 'format' => $format] = app(BlockContent::class)->productCards($ids, $context, app(PublicCatalog::class)->products($context['customer']));
        $carts = app(Carts::class);
        $settings = app(GeneralSettings::class)->all();
        $products = DB::table('products')->whereIn('id', $ids)->get(['id', 'quantity', 'min_qty', 'max_qty', 'qty_step', 'out_of_stock_actions', 'available_from'])->keyBy('id');
        $lines = $priced = [];
        $subtotal = $net = '0';
        $count = 0;
        foreach ($items as $item) {
            $card = $cards[(string) $item->product_id] ?? null;
            $p = $products[$item->product_id] ?? null;
            if ($card === null || $p === null) {
                continue;
            }
            $quantity = (int) $item->quantity;
            $count += $quantity;
            // The card already says whether it can be bought (released, its smallest quantity in stock) and hides prices when the store does.
            $buyable = $card['available'] && $card['price'] !== null;
            $problem = $buyable ? $carts->problem($p, $quantity) : __('storefront.cart_unavailable');
            $total = $problem === null ? Decimal::round(Decimal::multiply($card['price'], (string) $quantity), $format['decimals']) : null;
            // The card carries a price without tax only when the store shows it and it differs from the price.
            $totalNet = $total === null || $card['price_without_tax'] === null ? null : Decimal::round(Decimal::multiply($card['price_without_tax'], (string) $quantity), $format['decimals']);
            if ($total !== null) {
                $subtotal = Decimal::add($subtotal, $total);
                $net = Decimal::add($net, $totalNet ?? $total);
                $priced[] = ['product_id' => (string) $item->product_id, 'quantity' => $quantity];
            }
            // The stepper never offers more than stock allows when stock is enforced.
            $enforced = $settings['track_inventory'] && ($p->out_of_stock_actions ?? $settings['out_of_stock_action']) !== 'B';
            $max = $p->max_qty === null ? Carts::MAX_QUANTITY : (int) $p->max_qty;
            $lines[] = [...$card, 'quantity' => $quantity, 'total' => $total, 'total_without_tax' => $totalNet, 'problem' => $problem,
                'rules' => ['min' => (int) ($p->min_qty ?? 1), 'max' => $enforced ? min($max, max(0, (int) $p->quantity)) : $max, 'step' => (int) ($p->qty_step ?? 1)]];
        }

        $scale = $format['decimals'];
        $subtotal = Decimal::round($subtotal, $scale);
        $net = Decimal::round($net, $scale);
        $subtotalNet = $settings['show_prices_without_tax'] && Decimal::compare($net, $subtotal) !== 0 ? $net : null;
        // With codes switched off, those already entered are kept but ignored.
        $enabled = (bool) $settings['cart_codes_enabled'];
        $codes = $cart === null || ! $enabled ? null : DB::table('carts')->where('id', $cart->id)->first(['coupon_id', 'gift_card_id']);
        $code = $codes?->coupon_id === null ? null : (string) DB::table('coupons')->where('id', $codes->coupon_id)->value('code');
        $coupon = $code === null ? null : ['code' => $code, 'discount' => null, 'problem' => __('coupons.no_products')];
        $promotions = [];
        $due = $netDue = Decimal::round('0', $scale);
        if ($priced !== []) {
            $taxContext = ($context['customer'] === null ? null : app(CustomerTaxProfiles::class)->context($context['customer'])) ?? new TaxContext;
            $address = fn ($a): ?array => $a === null ? null : ['country_code' => $a->country, 'region_code' => $a->region];
            // Until checkout collects an address, use the customer's defaults or the store's tax estimate.
            $quote = fn (?string $coupon): array => app(OrderPricing::class)->quote($priced, [], ['billing' => $address($taxContext->billing), 'shipping' => $address($taxContext->shipping)], $taxContext->exempt, $context['locale'], $scale, $context['customer'], couponCode: $coupon);
            try {
                $calculation = $quote($code);
                if ($coupon !== null && $calculation['coupon_code'] !== null) {
                    $coupon = [...$coupon, 'discount' => Decimal::round('0', $scale), 'problem' => null];
                }
            } catch (ValidationException $e) {
                if ($coupon === null) {
                    throw $e;
                }
                $coupon['problem'] = (string) collect($e->errors())->flatten()->first();
                $calculation = $quote(null);
            }
            $automatic = array_values(array_filter($calculation['promotions'], fn (array $p): bool => $p['coupon_code'] === null));
            $descriptions = $this->promotionDescriptions(array_column($automatic, 'promotion_id'), $context['locale']);
            $promotions = array_map(fn (array $p): array => ['id' => $p['promotion_id'], 'name' => $p['name'], 'description' => $descriptions[$p['promotion_id']] ?? '', 'discount' => Decimal::round('0', $scale)], $automatic);
            $before = $this->displayPrices($calculation['lines'], false, $taxContext, $scale);
            $after = $this->displayPrices($calculation['lines'], true, $taxContext, $scale);
            $subtotal = $net = Decimal::round('0', $scale);
            $byProduct = array_column($calculation['lines'], null, 'product_id');
            $positions = array_flip(array_column($calculation['lines'], 'product_id'));
            foreach ($lines as &$line) {
                if ($line['total'] === null) {
                    continue;
                }
                $id = $line['id'];
                $line['total'] = $before[$id]['total'];
                $line['total_without_tax'] = $settings['show_prices_without_tax'] && Decimal::compare($before[$id]['net'], $before[$id]['total']) !== 0 ? $before[$id]['net'] : null;
                $line['price'] = Decimal::round(Decimal::divide($line['total'], (string) $line['quantity'], $scale + 4), $scale);
                $line['price_without_tax'] = $line['total_without_tax'] === null ? null : Decimal::round(Decimal::divide($line['total_without_tax'], (string) $line['quantity'], $scale + 4), $scale);
                if ($line['base_price'] !== null && Decimal::compare($line['base_price'], $line['price']) <= 0) {
                    $line['base_price'] = null;
                }
                $subtotal = Decimal::add($subtotal, $line['total']);
                $net = Decimal::add($net, $before[$id]['net']);
                $due = Decimal::add($due, $after[$id]['total']);
                $netDue = Decimal::add($netDue, $after[$id]['net']);
                $index = $positions[$id];
                $weights = array_map(fn (array $p): string => $p['allocations'][$index], $automatic);
                $couponPart = Decimal::subtract($byProduct[$id]['discount_total'], array_reduce($weights, Decimal::add(...), '0'));
                // Attribute the tax-inclusive savings per line, so coupons and promotions reconcile even with mixed tax rates.
                $saved = Decimal::subtract($before[$id]['total'], $after[$id]['total']);
                $parts = app(OrderTotals::class)->allocate(Decimal::compare($saved, '0') > 0 ? $saved : '0', [$couponPart, ...$weights], $scale);
                if ($coupon !== null && $coupon['problem'] === null) {
                    $coupon['discount'] = Decimal::add($coupon['discount'], $parts[0]);
                }
                foreach ($promotions as $n => &$promotion) {
                    $promotion['discount'] = Decimal::add($promotion['discount'], $parts[$n + 1]);
                }
                unset($promotion);
            }
            unset($line);
            // Grouped tax rounding can move a cent between lines; reconcile all displayed benefits to the actual savings.
            $parts = app(OrderTotals::class)->allocate(Decimal::subtract($subtotal, $due), [$coupon['discount'] ?? '0', ...array_column($promotions, 'discount')], $scale);
            if ($coupon !== null && $coupon['problem'] === null) {
                $coupon['discount'] = $parts[0];
            }
            foreach ($promotions as $n => &$promotion) {
                $promotion['discount'] = $parts[$n + 1];
            }
            unset($promotion);
            $subtotalNet = $settings['show_prices_without_tax'] && Decimal::compare($net, $subtotal) !== 0 ? $net : null;
        }
        $giftCard = $codes?->gift_card_id === null ? null : $this->giftCard(DB::table('gift_cards')->where('id', $codes->gift_card_id)->first(), $due, $format);
        $total = Decimal::round(Decimal::subtract($due, $giftCard['amount'] ?? '0'), $scale);
        // A gift card is payment, not a tax discount; only the optional net amount left to pay is prorated.
        $totalNet = ! $settings['show_prices_without_tax'] || Decimal::compare($netDue, $due) === 0 ? null : (Decimal::compare($due, '0') <= 0 ? Decimal::round('0', $scale) : Decimal::round(Decimal::divide(Decimal::multiply($netDue, $total), $due, $scale + 4), $scale));

        return ['id' => $cart?->uuid, 'lines' => $lines, 'count' => $count, 'subtotal' => $subtotal, 'subtotal_without_tax' => $subtotalNet, 'coupon' => $coupon, 'promotions' => $promotions, 'gift_card' => $giftCard, 'total' => $total, 'total_without_tax' => $totalNet, 'codes' => $enabled, 'currency_format' => $format];
    }

    /**
     * Only merchant-authored descriptions are public; an empty description has no tooltip.
     *
     * @param  list<string>  $ids
     * @return array<string,string>
     */
    private function promotionDescriptions(array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }
        $default = app(LocaleCatalog::class)->defaultCode();
        $rows = DB::table('promotion_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')
            ->whereIn('t.promotion_id', $ids)->whereIn('l.code', [$locale, $default])->get(['t.promotion_id', 'l.code', 't.description'])->groupBy('promotion_id');
        $descriptions = [];
        foreach ($rows as $id => $translations) {
            $description = trim((string) $translations->firstWhere('code', $locale)?->description);
            $descriptions[(string) $id] = $description !== '' ? $description : trim((string) $translations->firstWhere('code', $default)?->description);
        }

        return $descriptions;
    }

    /**
     * Display the approved quote's prices through the existing tax service, before/after its allocated discounts.
     *
     * @param  list<array<string,mixed>>  $lines
     * @return array<string,array{total:string,net:string}>
     */
    private function displayPrices(array $lines, bool $discounted, TaxContext $context, int $scale): array
    {
        $taxLines = $owners = [];
        foreach ($lines as $line) {
            $amount = Decimal::multiply($line['unit_price'], (string) $line['quantity']);
            if ($discounted) {
                $amount = Decimal::subtract($amount, $line['discount_total']);
            }
            foreach (app(CouponAmounts::class)->units($amount, $line['quantity'], $scale) as $part) {
                $owners[] = $line['product_id'];
                $taxLines[] = new TaxLine($line['product_id'], $part['price'], $part['quantity']);
            }
        }
        $taxes = app(TaxCalculationService::class)->forLines($taxLines, $context);
        $members = [];
        foreach ($taxes->lines as $index => $group) {
            $members[$group][] = $index;
        }
        $amounts = [];
        foreach ($members as $group => $indexes) {
            $weights = array_map(fn (int $i): string => Decimal::multiply($taxLines[$i]->unitPrice, (string) $taxLines[$i]->quantity), $indexes);
            $gross = app(OrderTotals::class)->allocate($taxes->groups[$group]->final, $weights, $scale);
            $net = app(OrderTotals::class)->allocate($taxes->groups[$group]->net, $weights, $scale);
            foreach ($indexes as $n => $i) {
                $id = $owners[$i];
                $amounts[$id] = ['total' => Decimal::add($amounts[$id]['total'] ?? '0', $gross[$n]), 'net' => Decimal::add($amounts[$id]['net'] ?? '0', $net[$n])];
            }
        }

        return $amounts;
    }

    /**
     * What the gift card pays towards the amount due (as much as its balance covers), or why it cannot pay.
     *
     * @param  array<string,mixed>  $format
     * @return array{code:string,amount:?string,problem:?string}
     */
    private function giftCard(?object $card, string $due, array $format): array
    {
        $reason = $card === null || $card->deleted_at !== null ? 'not_found' : GiftCardRules::refusal($card->status);
        $problem = match (true) {
            $reason !== null => __('giftcards.'.$reason),
            $card->currency_code !== $format['code'] => __('giftcards.currency_mismatch', ['card' => $card->currency_code, 'order' => $format['code']]),
            default => null,
        };

        return ['code' => $card === null ? '' : GiftCardRules::masked($card->number), 'amount' => $problem === null ? Decimal::round(GiftCardRules::applicable($card->balance, $due), $format['decimals']) : null, 'problem' => $problem];
    }
}
