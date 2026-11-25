<?php

namespace App\Catalog\Infrastructure;

use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Infrastructure\VariationListings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Shared publication scope: active complete sellable identities and active accessible category ancestry. */
final class PublicCatalog
{
    /** @return list<int> */
    public function groups(?string $customer): array
    {
        return $customer === null ? [] : DB::table('customer_group_members as m')->join('customer_groups as g', 'g.id', '=', 'm.group_id')->where('m.customer_id', $customer)->where('g.status', 'active')->pluck('g.id')->map(intval(...))->all();
    }

    public function products(?string $customer = null): Builder
    {
        $queries = app(ProductQueries::class);
        $q = DB::table('products')->where('products.status', 'active')->whereNull('products.deleted_at')->whereRaw($queries->completenessSql(), [$queries->defaultId()]);
        $this->restrict($q, 'product_customer_groups', 'product_id', 'products.id', $this->groups($customer));
        $q->whereIn('products.category_id', $this->categories($customer)->select('categories.id'));

        return $q;
    }

    /** Products that may appear in listings and blocks. Optionally excludes products without stock;
     * product pages keep using products(), so an out-of-stock page stays public. */
    public function listed(?string $customer = null, ?string $surface = 'lists'): Builder
    {
        $q = $this->products($customer);
        $settings = app(GeneralSettings::class)->all();
        if ($settings['hide_out_of_stock_listings'] && $settings['track_inventory']) {
            $q->where('products.quantity', '>', 0);
        }

        $representatives = $surface === null ? [] : app(VariationListings::class)->representatives($customer, $surface);
        $excluded = [];
        foreach ($representatives as $member => $chosen) {
            if ((string) $member !== $chosen) {
                $excluded[] = $member;
            }
        }
        if ($excluded !== []) {
            $q->whereNotIn('products.id', $excluded);
        }

        return $q;
    }

    public function categories(?string $customer = null): Builder
    {
        $groups = $this->groups($customer);
        $allowed = $groups === [] ? 'FALSE' : 'cg.group_id IN ('.implode(',', $groups).')';

        return DB::table('categories')->whereNull('categories.system_key')->whereRaw("NOT EXISTS (WITH RECURSIVE path AS (SELECT id, parent_id, status FROM categories c WHERE c.id = categories.id UNION ALL SELECT c.id, c.parent_id, c.status FROM categories c JOIN path ON c.id = path.parent_id) SELECT 1 FROM path WHERE status <> 'active' OR (EXISTS (SELECT 1 FROM category_customer_groups cg WHERE cg.category_id = path.id) AND NOT EXISTS (SELECT 1 FROM category_customer_groups cg WHERE cg.category_id = path.id AND $allowed)))");
    }

    /** @param list<int> $groups */
    public function restrict(Builder $q, string $table, string $owner, string $column, array $groups): void
    {
        $q->where(fn ($q) => $q->whereNotExists(fn ($s) => $s->selectRaw('1')->from($table.' as access')->whereColumn('access.'.$owner, $column))->orWhereExists(fn ($s) => $s->selectRaw('1')->from($table.' as access')->whereColumn('access.'.$owner, $column)->whereIn('access.group_id', $groups)));
    }
}
