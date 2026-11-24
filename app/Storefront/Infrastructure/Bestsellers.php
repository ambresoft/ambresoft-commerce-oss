<?php

namespace App\Storefront\Infrastructure;

use App\Reporting\Infrastructure\ReportOrders;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Shared merchandising eligibility; reporting keeps its own unfiltered sales totals. */
final class Bestsellers
{
    public function productUnitTotals(): Builder
    {
        $settings = app(GeneralSettings::class)->all();
        $days = (int) $settings['bestsellers_days'];
        $query = app(ReportOrders::class)->productUnitTotals($days === 0 ? null : now()->subDays($days))
            ->havingRaw('sum(i.quantity) >= ?', [$settings['bestsellers_min_units']])
            ->havingRaw('count(distinct i.order_id) >= ?', [$settings['bestsellers_min_orders']]);
        $excluded = DB::table('bestseller_excluded_categories')->select('category_id');
        $query->whereNotIn('i.product_id', DB::table('products')->select('id')->whereRaw('category_id IN (WITH RECURSIVE excluded AS (SELECT id FROM categories WHERE id IN ('.$excluded->toSql().') UNION SELECT c.id FROM categories c JOIN excluded e ON c.parent_id = e.id) SELECT id FROM excluded)', $excluded->getBindings()));

        return $query;
    }
}
