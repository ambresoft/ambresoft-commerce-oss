<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Domain\Service\ProductRules;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Pricing\Infrastructure\ProductSales;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Tax\Application\Contract\CustomerTaxProfiles;
use App\Tax\Application\Service\TaxCalculationService;
use App\Tax\Domain\Model\TaxContext;
use App\Tax\Domain\Model\TaxLine;
use Illuminate\Support\Facades\DB;

/** Listing representatives never change the sellable identities used by product pages and carts. */
final class VariationListings
{
    /** SQL identity used only after member-level filtering; a group is counted once per value.
     * @param array<string,string|null> $representatives */
    public function identitySql(array $representatives): string
    {
        $cases = [];
        foreach ($representatives as $member => $chosen) {
            $cases[] = 'WHEN '.(int) $member.' THEN '.($chosen === null ? 'NULL' : (string) (int) $chosen);
        }

        return $cases === [] ? 'products.id' : '(CASE products.id '.implode(' ', $cases).' ELSE products.id END)';
    }

    /** Optional card labels from visible members; never copy their values onto the sellable product.
     * @param list<string> $groups
     * @param list<string> $selected
     * @return array<string,list<array{name:string,values:list<string>,swatches?:list<array{label:string,color:?string,selected:bool,url?:string}>}>> */
    public function cardFeatures(array $groups, string $locale, ?string $customer, array $selected = []): array
    {
        if ($groups === []) {
            return [];
        }
        $rows = DB::table('variation_group_features as gf')->join('variation_groups as vg', 'vg.id', '=', 'gf.group_id')
            ->join('features as f', 'f.id', '=', 'gf.feature_id')
            ->join('variation_group_members as m', 'm.group_id', '=', 'gf.group_id')
            ->join('product_feature_assignments as a', fn ($j) => $j->on('a.product_id', '=', 'm.product_id')->on('a.feature_id', '=', 'gf.feature_id'))
            ->join('product_feature_choices as c', 'c.assignment_id', '=', 'a.id')
            ->join('feature_options as o', 'o.id', '=', 'c.option_id')
            ->whereIn('gf.group_id', $groups)->where('gf.show_on_card', true)->where('f.status', 'active')->where('o.status', 'active')->where('a.is_applicable', true)
            ->whereIn('m.product_id', app(PublicCatalog::class)->listed($customer, null)->select('products.id'))
            ->orderBy('gf.position')->orderBy('o.position')->orderBy('o.id')->get(['gf.group_id', 'gf.feature_id', 'o.id', 'm.product_id', 'f.variation_style', 'vg.color_swatches', 'vg.clickable_colors', 'o.swatch_color']);
        $content = app(BlockContent::class);
        $features = $content->translations('feature_translations', 'feature_id', $rows->pluck('feature_id')->unique()->map(strval(...))->values()->all());
        $options = $content->translations('feature_option_translations', 'option_id', $rows->pluck('id')->unique()->map(strval(...))->values()->all());
        $labels = DB::table('variation_card_feature_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->whereIn('t.group_id', $groups)->get(['t.group_id', 't.feature_id', 'l.code', 't.name'])->groupBy(fn ($t) => $t->group_id.':'.$t->feature_id);
        $linkIds = $rows->filter(fn ($row) => $row->clickable_colors && $row->color_swatches && $row->variation_style === 'color')->pluck('product_id')->unique()->map(strval(...))->values()->all();
        $linked = $linkIds === [] ? ['cards' => [], 'pricing' => []] : $content->productCards($linkIds, ['locale' => $locale, 'customer' => $customer], app(PublicCatalog::class)->listed($customer, null));
        $targets = [];
        $amounts = [];
        foreach ($rows as $row) {
            $card = $linked['cards'][$row->product_id] ?? null;
            if (! $row->clickable_colors || ! $card || ! $card['available'] || ! $card['url']) {
                continue;
            }
            $key = $row->group_id.':'.$row->feature_id.':'.$row->id;
            $price = $card['price'] ?? $linked['pricing'][$row->product_id]['regular'];
            if (! isset($targets[$key]) || Decimal::compare($price, $amounts[$key]) < 0 || (Decimal::compare($price, $amounts[$key]) === 0 && (int) $row->product_id < $targets[$key]['id'])) {
                $targets[$key] = ['id' => (int) $row->product_id, 'url' => $card['url']];
                $amounts[$key] = $price;
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $name = $content->text($features[$row->feature_id] ?? [], $locale, 'name');
            $custom = ($labels[$row->group_id.':'.$row->feature_id] ?? collect())->mapWithKeys(fn ($t) => [$t->code => ['name' => $t->name]])->all();
            $name = $content->text($custom, $locale, 'name') ?: $name;
            $value = $content->text($options[$row->id] ?? [], $locale, 'label');
            if ($name !== '' && $value !== '') {
                $out[$row->group_id][$row->feature_id]['name'] = $name;
                $out[$row->group_id][$row->feature_id]['values'][$row->id] = $value;
                if ($row->variation_style === 'color' && $row->color_swatches) {
                    $out[$row->group_id][$row->feature_id]['swatches'][$row->id] = [
                        'label' => $value, 'color' => $row->swatch_color,
                        ...isset($targets[$row->group_id.':'.$row->feature_id.':'.$row->id]) ? ['url' => $targets[$row->group_id.':'.$row->feature_id.':'.$row->id]['url']] : [],
                        'selected' => in_array((string) $row->product_id, $selected, true) || ($out[$row->group_id][$row->feature_id]['swatches'][$row->id]['selected'] ?? false),
                    ];
                }
            }
        }

        return array_map(fn ($features) => array_values(array_map(fn ($f) => ['name' => $f['name'], 'values' => array_values($f['values']), ...isset($f['swatches']) ? ['swatches' => array_values($f['swatches'])] : []], $features)), $out);
    }

    /** @param list<string> $groups
     * @return array<string, string|null> Member ID => cheapest purchasable member, or null when none can be bought. */
    public function representatives(?string $customer, string $surface = 'lists', array $groups = []): array
    {
        $members = DB::table('variation_group_members as m')->join('variation_groups as g', 'g.id', '=', 'm.group_id')
            ->when($groups !== [], fn ($q) => $q->whereIn('g.id', $groups), fn ($q) => $q->where('g.'.match ($surface) {
                'carousels' => 'single_carousel', 'search' => 'single_search', default => 'single_listing'
            }, true))->orderBy('m.product_id')->pluck('m.group_id', 'm.product_id');
        if ($members->isEmpty()) {
            return [];
        }
        $settings = app(GeneralSettings::class)->all();
        $rules = app(ProductRules::class);
        $best = [];
        $amounts = [];
        $taxContext = $customer === null ? null : app(CustomerTaxProfiles::class)->context($customer, 'unit_price');
        $decimals = app(CurrencyRepository::class)->currentPrimary()->decimals;
        $query = app(PublicCatalog::class)->products($customer)->whereIn('products.id', $members->keys()->all())->whereNotNull('products.price')->orderBy('products.id');
        foreach ($query->lazy(200)->chunk(200) as $batch) {
            $requests = [];
            foreach ($batch as $p) {
                $requests[(string) $p->id] = ['base' => $p->price, 'quantity' => (int) ($p->min_qty ?? $p->qty_step ?? 1)];
            }
            $prices = app(ProductSales::class)->prices($requests, $customer, $decimals);
            $lines = [];
            foreach ($batch as $p) {
                $lines[] = new TaxLine((string) $p->id, $prices[$p->id]['price'] ?? $p->price);
            }
            $taxes = app(TaxCalculationService::class)->forLines($lines, $taxContext ?? new TaxContext(method: 'unit_price'));
            $index = -1;
            foreach ($batch as $p) {
                $index++;
                $quantity = (int) ($p->min_qty ?? $p->qty_step ?? 1);
                if ($p->price === null || ($p->available_from !== null && now()->timezone($settings['timezone'])->toDateString() < $p->available_from)
                    || ! $rules->canPurchase($quantity, (int) $p->quantity, $rules->effectiveOutOfStockAction($p->out_of_stock_actions, $settings['out_of_stock_action']), $p->min_qty, $p->max_qty, $p->qty_step, $settings['track_inventory'])) {
                    continue;
                }
                $price = $taxes->groups[$taxes->lines[$index]]->display(true);
                $group = $members[$p->id];
                if (! isset($best[$group]) || Decimal::compare($price, $amounts[$group]) < 0) {
                    $best[$group] = (string) $p->id;
                    $amounts[$group] = $price;
                }
            }
        }
        $out = [];
        foreach ($members as $id => $group) {
            $out[(string) $id] = $best[$group] ?? null;
        }

        return $out;
    }
}
