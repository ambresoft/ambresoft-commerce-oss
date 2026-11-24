<?php

namespace App\Storefront\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\PromotionRules;
use App\Marketing\Infrastructure\CouponPricing;
use App\Marketing\Infrastructure\PromotionQueries;
use App\Order\Infrastructure\OrderMoney;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Support\Facades\DB;

/** Public advertising only: actual cart applicability remains with the existing pricing services. */
final class StorefrontDeals
{
    /** @param list<string> $selected
     * @return list<array{id:string,name:string}> */
    public function options(string $source, string $search, array $selected): array
    {
        if ($source === 'promotions') {
            return app(PromotionQueries::class)->options($search, $selected);
        }
        $q = DB::table('coupons')->whereNull('deleted_at')->select('id', 'code as name');
        $chosen = (clone $q)->whereIn('id', $selected)->get();
        if ($search !== '') {
            $q->where('code', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }

        return $chosen->concat($q->orderBy('id')->limit(30)->get())->unique('id')->map(fn ($p): array => ['id' => (string) $p->id, 'name' => $p->name])->values()->all();
    }

    /** @param array<string,array<string,mixed>> $blocks
     * @param array{locale:string,customer:?string,main?:array<string,mixed>} $context
     * @return array<string,array<string,mixed>> */
    public function resolve(string $type, array $blocks, array $context): array
    {
        $picked = array_map(fn (array $b): array => $b['selections']['items'] ?? [], $blocks);
        $ids = array_values(array_unique(array_merge([], ...array_values($picked))));
        if ($ids === []) {
            return [];
        }
        $locale = $context['locale'];
        $groups = $context['customer'] === null ? [] : DB::table('customer_group_members as m')->join('customer_groups as g', 'g.id', '=', 'm.group_id')->join('customers as c', 'c.id', '=', 'm.customer_id')->where('c.id', $context['customer'])->where('c.status', 'active')->where('g.status', 'active')->pluck('g.id')->map(strval(...))->all();
        $cards = $type === 'coupons' ? $this->coupons($ids, $context, $groups) : $this->offers($ids, $locale, $groups);
        $out = [];
        foreach ($blocks as $id => $b) {
            $items = [];
            foreach ($picked[$id] as $chosen) {
                if (isset($cards[$chosen])) {
                    $items[] = $cards[$chosen];
                }
            }
            if ($items !== []) {
                $out[$id] = ['items' => array_slice($items, 0, (int) $b['settings']['limit'])];
            }
        }

        return app(BlockContent::class)->display($blocks, $out, ['per_row', 'title_icon', 'card_shadow', 'autoscroll', 'drag_scroll', 'infinite_scroll', 'navigation', 'background_color', 'full_width_background', 'title_size_desktop', 'title_size_tablet', 'title_size_mobile', 'title_color'], $locale);
    }

    /** @param list<string> $ids
     * @param array{locale:string,customer:?string,main?:array<string,mixed>} $context
     * @param list<string> $groups
     * @return array<string,array<string,mixed>> */
    private function coupons(array $ids, array $context, array $groups): array
    {
        $locale = $context['locale'];
        $pricing = app(CouponPricing::class);
        $key = $context['customer'] === null ? null : $pricing->customerKey($context['customer'], '');
        $used = DB::table('coupon_redemptions')->whereIn('coupon_id', $ids)->whereNull('released_at')->groupBy('coupon_id')->selectRaw('coupon_id, count(*) as total')->selectRaw('count(*) filter (where customer_key = ?) as customer_total', [$key])->get()->keyBy('coupon_id');
        $linked = app(PromotionQueries::class)->definitions(DB::table('promotions')->whereIn('coupon_id', $ids)->pluck('id')->map(strval(...))->all(), locale: $locale);
        $linked = array_column($linked, null, 'coupon_id');
        $cards = [];
        foreach (DB::table('coupons')->whereIn('id', $ids)->get() as $c) {
            if (! $pricing->available($c) || ($c->login_required && $context['customer'] === null) || ($c->total_limit !== null && ($used[$c->id]->total ?? 0) >= $c->total_limit) || ($key !== null && $c->customer_limit !== null && ($used[$c->id]->customer_total ?? 0) >= $c->customer_limit)) {
                continue;
            }
            $promotion = $linked[$c->id] ?? null;
            if ($promotion !== null && ! $this->available($promotion, $groups)) {
                continue;
            }
            $terms = [];
            if (Decimal::compare($c->minimum, '0') > 0) {
                $terms[] = __('storefront.deal_minimum', ['amount' => $this->money($c->minimum)], $locale);
            }
            if (! $c->all_products) {
                $terms[] = __('storefront.deal_selected_products', [], $locale);
            }
            if ($c->login_required) {
                $terms[] = __('storefront.deal_login', [], $locale);
            }
            if ($c->customer_limit !== null) {
                $terms[] = __('storefront.deal_customer_limit', ['count' => $c->customer_limit], $locale);
            }
            $cards[(string) $c->id] = ['id' => (string) $c->id, 'name' => $promotion['name'] ?? __('storefront.deal_eligible_products', [], $locale), 'deal' => [
                'kind' => 'coupon', 'benefit' => $this->benefit($c->free_shipping && Decimal::compare($c->amount, '0') === 0 ? 'free_shipping' : $c->type, $c->amount, $locale), 'code' => $c->code,
                'description' => $promotion === null ? '' : $this->description($promotion, $locale),
                'expires_on' => $c->ends_on === null ? (isset($promotion['ends_at']) ? substr($promotion['ends_at'], 0, 10) : null) : (isset($promotion['ends_at']) ? min($c->ends_on, substr($promotion['ends_at'], 0, 10)) : $c->ends_on), 'terms' => [...$terms, ...($promotion === null ? [] : $this->terms($promotion, $locale))],
            ]];
        }

        return $cards;
    }

    /** @param list<string> $ids
     * @param list<string> $groups
     * @return array<string,array<string,mixed>> */
    private function offers(array $ids, string $locale, array $groups): array
    {
        $cards = [];
        foreach (app(PromotionQueries::class)->definitions($ids, locale: $locale) as $p) {
            if ($p['mode'] !== 'automatic' || ! $this->available($p, $groups)) {
                continue;
            }
            $cards[$p['id']] = ['id' => $p['id'], 'name' => $p['name'], 'deal' => [
                'kind' => 'offer', 'benefit' => $this->benefit($p['benefit'], $p['amount'], $locale), 'code' => null,
                'description' => $this->description($p, $locale), 'expires_on' => $p['ends_at'] === null ? null : substr($p['ends_at'], 0, 10),
                'terms' => $this->terms($p, $locale),
            ]];
        }

        return $cards;
    }

    /** @param array<string,mixed> $p
     * @param list<string> $groups */
    private function available(array $p, array $groups): bool
    {
        if (! app(PromotionRules::class)->active($p['status'], $p['starts_utc'], $p['ends_utc'], now()->utc()->format('Y-m-d H:i:s'))) {
            return false;
        }
        foreach (['products', 'categories', 'shipping', 'excluded_products', 'excluded_categories'] as $field) {
            if (in_array(null, $p[$field], true)) {
                return false;
            }
        }
        $groupConditions = array_values(array_filter($p['conditions'], fn (array $c): bool => $c['type'] === 'groups'));
        $matches = array_map(fn (array $c): bool => array_intersect($c['groups'], $groups) !== [], $groupConditions);

        // Cart conditions remain open until checkout; group-only OR rules must match this visitor.
        return $p['match'] === 'all' ? ! in_array(false, $matches, true) : count($groupConditions) < count($p['conditions']) || $matches === [] || in_array(true, $matches, true);
    }

    /** @param array<string,mixed> $p */
    private function description(array $p, string $locale): string
    {
        $text = $p['translations'][$locale]['description'] ?? $p['translations'][app(LocaleCatalog::class)->defaultCode()]['description'] ?? '';

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @param array<string,mixed> $p
     * @return list<string> */
    private function terms(array $p, string $locale): array
    {
        $terms = $p['conditions'] === [] ? [] : [__('storefront.deal_match_'.$p['match'], [], $locale)];
        foreach ($p['conditions'] as $c) {
            $terms[] = match ($c['type']) {
                'subtotal' => __('storefront.deal_subtotal', ['min' => $c['minimum'] === null ? '0' : $this->money($c['minimum']), 'max' => $c['maximum'] === null ? __('storefront.deal_no_limit', [], $locale) : $this->money($c['maximum'])], $locale),
                'quantity' => __('storefront.deal_quantity', ['count' => $c['quantity']], $locale),
                default => __('promotions.condition_'.$c['type'], [], $locale),
            };
        }
        if ($p['exclude_sale']) {
            $terms[] = __('storefront.deal_exclude_sale', [], $locale);
        }
        if ($p['scope'] === 'products' && ! $p['all_products']) {
            $terms[] = __('storefront.deal_selected_products', [], $locale);
        }
        if ($p['excluded_products'] !== [] || $p['excluded_categories'] !== []) {
            $terms[] = __('storefront.deal_excluded_products', [], $locale);
        }
        if ($p['benefit'] === 'free_shipping' && ! $p['all_shipping']) {
            $terms[] = __('promotions.condition_shipping', [], $locale);
        }

        return $terms;
    }

    private function benefit(string $type, ?string $amount, string $locale): string
    {
        return match ($type) {
            'percentage' => Decimal::trim($amount ?? '0').'%',
            'fixed' => $this->money($amount ?? '0'),
            default => __('promotions.free_shipping', [], $locale),
        };
    }

    private function money(string $amount): string
    {
        $currency = app(CurrencyRepository::class)->currentPrimary();

        return app(OrderMoney::class)->format($amount, $currency->code, $currency->decimals);
    }
}
