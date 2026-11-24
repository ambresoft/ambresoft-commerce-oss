<?php

namespace App\Reporting\Infrastructure;

use App\Reporting\Application\Command\ReportFilters;
use App\Shared\Domain\Service\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Page data of each report: figures from the report classes with amounts formatted in the filtered currency, record
 * links only where the viewer may open the record, and the filter options of the screen. Nothing here recalculates a
 * figure; it only presents the report classes' results.
 */
final class ReportViews
{
    /** Filters each report honours (the screen shows only these). */
    public const FILTERS = [
        'overview' => ['period', 'compare', 'currency'],
        'sales' => ['period', 'compare', 'currency', 'status', 'payment', 'shipping', 'product', 'category', 'customer'],
        'orders' => ['period', 'date', 'currency', 'status', 'payment', 'shipping', 'product', 'category', 'customer'],
        'products' => ['period', 'compare', 'currency', 'status', 'payment', 'shipping', 'product', 'category'],
        'categories' => ['period', 'currency', 'status', 'payment', 'shipping'],
        'brands' => ['period', 'compare', 'currency', 'status', 'payment', 'shipping', 'category'],
        'customers' => ['period', 'currency', 'status', 'payment', 'shipping'],
        'inventory' => [],
        'promotions' => ['period', 'currency'],
    ];

    public function __construct(
        private ReportOrders $orders, private SalesReport $sales, private OrdersReport $ordersReport, private ProductReport $products, private CategoryReport $categories,
        private CustomerReport $customers, private InventoryReport $inventory, private PromotionReport $promotions, private ReportMoney $money, private ReportNames $names, private ReportCategories $tree,
        private BrandReport $brands, private InsightsReport $insights,
    ) {}

    /** @return array<string, mixed> Period, currency and choices shared by every report screen. */
    public function common(string $report, ReportFilters $f): array
    {
        $period = $f->period;
        $previous = $period->previous();
        $currencies = DB::table('orders')->distinct()->orderBy('currency_code')->pluck('currency_code')->all();
        if (! in_array($f->currency, $currencies, true)) {
            $currencies[] = $f->currency;
        }
        $others = $this->orders->paid($f, $period, anyCurrency: true)->where('o.currency_code', '<>', $f->currency)->groupBy('o.currency_code')->orderBy('o.currency_code')->selectRaw('o.currency_code AS currency, count(*) AS orders')->get()
            ->map(fn (object $r): array => ['currency' => $r->currency, 'orders' => (int) $r->orders])->all();
        $day = fn (CarbonImmutable|\DateTimeImmutable $d): string => $d->format('Y-m-d');

        return [
            'report' => $report,
            'applicable' => self::FILTERS[$report],
            'period' => ['preset' => $period->preset, 'from' => $day($period->start), 'to' => $day($period->end->modify('-1 day')), 'days' => $period->days(), 'granularity' => $period->granularity(), 'timezone' => $period->start->getTimezone()->getName()],
            'previous' => ['from' => $day($previous->start), 'to' => $day($previous->end->modify('-1 day'))],
            'filters' => ['compare' => $f->compare, 'currency' => $f->currency, 'date' => $f->dateBasis, 'status_id' => $f->statusId, 'payment_method_id' => $f->paymentMethodId, 'shipping_method_id' => $f->shippingMethodId, 'product_id' => $f->productId, 'category_id' => $f->categoryId, 'customer_id' => $f->customerId],
            'currency' => ['code' => $f->currency, 'decimals' => $this->money->decimals($f->currency)],
            'currencies' => array_values($currencies),
            'other_currencies' => $others,
            'options' => [
                'statuses' => array_values(array_map(fn (array $s): array => ['value' => $s['id'], 'label' => $s['name'], 'void' => $s['void']], $this->names->statuses())),
                'payment_methods' => $this->names->paymentMethods(), 'shipping_methods' => $this->names->shippingMethods(),
                'categories' => collect($this->tree->all())->filter(fn (array $c): bool => ! $c['system'])->keys()->map(fn (int $id): array => ['value' => (string) $id, 'label' => $this->tree->path($id)])->sortBy('label')->values()->all(),
            ],
            'links' => ['can_customers' => Gate::allows('reports.customers') && Gate::allows('customers.view'), 'can_export' => Gate::allows('reports.export'), 'can_orders' => Gate::allows('orders.view'), 'can_products' => Gate::allows('products.view'), 'can_categories' => Gate::allows('categories.view'), 'can_brands' => Gate::allows('brands.view')],
        ];
    }

    /**
     * @param  array<string, ?string>  $s  A summary from SalesReport
     * @return array<string, ?string>
     */
    private function formatSummary(array $s, string $currency): array
    {
        $out = $s;
        foreach (['gross', 'discounts', 'net', 'shipping', 'shipping_discount', 'payment_fees', 'tax_added', 'tax_included', 'total', 'aov'] as $key) {
            $out[$key.'_formatted'] = $this->money->format($s[$key], $currency);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function sales(ReportFilters $f, string $sort, string $direction, int $perPage): array
    {
        $period = $f->period;
        $summary = $this->formatSummary($this->sales->summary($f, $period), $f->currency);
        $previous = $f->compare ? $this->sales->summary($f, $period->previous()) : null;
        $detail = $this->sales->detail($f, $period, $sort, $direction)->paginate($perPage)->withQueryString();
        $rows = [];
        foreach ($detail->items() as $r) {
            $rows[] = $f->lineScoped()
                ? ['id' => (string) $r->id, 'order_id' => (string) $r->order_id, 'order_number' => $r->order_number, 'paid_at' => CarbonImmutable::parse($r->paid_at)->toIso8601String(), 'name' => $r->name, 'sku' => $r->sku, 'product_id' => $r->product_id === null ? null : (string) $r->product_id, 'quantity' => (int) $r->quantity, 'gross' => $this->money->format(Decimal::trim($r->subtotal), $f->currency), 'discount' => $this->money->format(Decimal::trim($r->discount_total), $f->currency), 'net' => $this->money->format(Decimal::subtract($r->subtotal, $r->discount_total), $f->currency)]
                : ['id' => (string) $r->id, 'order_number' => $r->order_number, 'paid_at' => CarbonImmutable::parse($r->paid_at)->toIso8601String(), 'payment' => $r->payment_method_name, 'shipping_method' => $r->shipping_method_name, 'coupon' => $r->coupon_code,
                    'gross' => $this->money->format(Decimal::trim($r->subtotal), $f->currency), 'discount' => $this->money->format(Decimal::trim($r->discount_total), $f->currency), 'net' => $this->money->format(Decimal::subtract($r->subtotal, $r->discount_total), $f->currency),
                    'shipping' => $this->money->format(Decimal::trim($r->shipping_total), $f->currency), 'payment_fee' => $this->money->format(Decimal::trim($r->payment_fee), $f->currency), 'tax' => $this->money->format(Decimal::subtract($r->tax_total, $r->tax_included), $f->currency), 'total' => $this->money->format(Decimal::trim($r->total), $f->currency)];
        }
        $detail->setCollection(collect($rows));
        $fmt = fn (array $r, array $keys): array => [...$r, ...array_combine(array_map(fn (string $k): string => $k.'_formatted', $keys), array_map(fn (string $k): ?string => $this->money->format($r[$k], $f->currency), $keys))];

        return [
            ...$this->common('sales', $f),
            'summary' => $summary, 'previous_summary' => $previous === null ? null : $this->formatSummary($previous, $f->currency), 'previous_has_data' => $previous !== null && (int) $previous['orders'] > 0,
            'line_scoped' => $f->lineScoped(),
            'context' => $f->lineScoped() ? null : $this->formatContext($this->sales->context($f, $period), $f->currency),
            'series' => $this->sales->series($f, $period), 'previous_series' => $f->compare ? $this->sales->series($f->forPeriod($period->previous()), $period->previous()) : null,
            'by_payment' => array_map(fn (array $r): array => $fmt($r, ['net', 'total', 'unpaid_value']), $f->lineScoped() ? [] : $this->sales->byPayment($f, $period)),
            'by_shipping' => array_map(fn (array $r): array => $fmt($r, ['shipping', 'shipping_discount', 'total']), $f->lineScoped() ? [] : $this->sales->byShipping($f, $period)),
            'by_coupon' => array_map(fn (array $r): array => $fmt($r, ['discounts', 'shipping_discount', 'net', 'total']), $f->lineScoped() ? [] : $this->sales->byCoupon($f, $period)),
            'detail' => $detail->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function formatContext(array $c, string $currency): array
    {
        return [...$c, 'unpaid_value_formatted' => $this->money->format($c['unpaid_value'], $currency), 'void_value_formatted' => $this->money->format($c['void_value'], $currency), 'collected_on_void_formatted' => $this->money->format($c['collected_on_void'], $currency)];
    }

    /** @return array<string, mixed> */
    public function orders(ReportFilters $f, string $sort, string $direction, int $perPage): array
    {
        $period = $f->period;
        $statuses = array_map(fn (array $s): array => [...$s, 'value_formatted' => $this->money->format($s['value'], $f->currency), 'collected_formatted' => $this->money->format($s['collected'], $f->currency), 'outstanding_formatted' => $this->money->format($s['outstanding'], $f->currency)], $this->ordersReport->statuses($f, $period));
        $page = $this->ordersReport->listing($f, $period, $sort, $direction)->paginate($perPage)->withQueryString();
        $names = $this->names->statuses();
        $canCustomers = Gate::allows('customers.view');
        $rows = [];
        foreach ($page->items() as $r) {
            $captured = (string) $r->captured;
            $state = Decimal::compare($captured, '0') <= 0 ? 'unpaid' : (Decimal::compare($captured, $r->total) >= 0 ? 'paid' : 'partially_paid');
            $rows[] = ['id' => (string) $r->id, 'number' => $r->order_number, 'created_at' => CarbonImmutable::parse($r->created_at)->toIso8601String(), 'paid_at' => isset($r->paid_at) ? CarbonImmutable::parse($r->paid_at)->toIso8601String() : null,
                'customer' => $canCustomers ? trim($r->first_name.' '.$r->last_name) : null, 'status' => $names[(string) $r->status_id] ?? null, 'payment_state' => $state, 'payment' => $r->payment_method_name, 'shipping_method' => $r->shipping_method_name,
                'total' => $this->money->format(Decimal::trim($r->total), $f->currency), 'collected' => $this->money->format(Decimal::trim($captured), $f->currency)];
        }
        $page->setCollection(collect($rows));

        return [...$this->common('orders', $f), 'statuses' => $statuses, 'payment_states' => $this->ordersReport->paymentStates($f, $period), 'orders' => $page->toArray()];
    }

    /** @return array<string, mixed> */
    public function products(ReportFilters $f, string $view, string $search, string $sort, string $direction, int $perPage): array
    {
        $period = $f->period;
        $base = $this->common('products', $f);
        if ($view === 'declining') {
            $page = $this->products->declining($f, $period)->paginate($perPage)->withQueryString();
            $rows = $this->decorateProducts($page->items(), fn (object $r): array => ['units' => (int) $r->units, 'previous_units' => (int) $r->previous_units, 'change' => (int) $r->units - (int) $r->previous_units]);
        } elseif ($view === 'never_sold' || $view === 'no_sales') {
            $page = $this->products->unsold($f, $view === 'never_sold' ? null : $period)->paginate($perPage)->withQueryString();
            $rows = $this->decorateProducts($page->items(), fn (object $r): array => ['type' => $r->type, 'created_at' => CarbonImmutable::parse($r->created_at)->toIso8601String()]);
        } else {
            $page = $this->products->ranking($f, $period, $search, $sort, $direction)->paginate($perPage)->withQueryString();
            $rows = $this->decorateProducts($page->items(), fn (object $r): array => ['units' => (int) $r->units, 'orders' => (int) $r->orders, 'gross' => $this->money->format(Decimal::trim($r->gross), $f->currency), 'discounts' => $this->money->format(Decimal::trim($r->discounts), $f->currency), 'net' => $this->money->format(Decimal::trim($r->net), $f->currency), 'net_raw' => Decimal::trim($r->net), 'snapshot_name' => $r->name, 'snapshot_sku' => $r->sku]);
        }
        $page->setCollection(collect($rows));

        return [...$base, 'view' => $view, 'search' => $search, 'products' => $page->toArray(), 'summary' => $this->formatSummary($this->sales->summary($f, $period), $f->currency)];
    }

    /**
     * Adds the product's link, trash state and (for products that still exist) its current name and SKU.
     *
     * @param  iterable<object>  $items
     * @param  callable(object): array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    private function decorateProducts(iterable $items, callable $extra): array
    {
        $items = collect($items);
        $ids = $items->pluck('product_id')->filter()->values()->all();
        $current = DB::table('products')->whereIn('id', $ids)->get(['id', 'sku', 'status', 'deleted_at'])->keyBy('id');
        $names = $this->names->products($ids);

        return $items->map(function (object $r) use ($current, $names, $extra): array {
            $product = $r->product_id === null ? null : ($current[$r->product_id] ?? null);

            return [
                'product_id' => $r->product_id === null ? null : (string) $r->product_id, 'name' => $r->name ?? ($names[(string) $r->product_id] ?? ''), 'sku' => $r->sku ?? null, 'current_name' => $product === null ? null : ($names[(string) $r->product_id] ?: null),
                'exists' => $product !== null && $product->deleted_at === null, 'trashed' => $product !== null && $product->deleted_at !== null, 'status' => $product->status ?? null, ...$extra($r),
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    public function categories(ReportFilters $f, string $mode, string $search, string $sort, string $direction, int $perPage, int $page): array
    {
        $result = $this->categories->compute($f, $f->period, $mode);
        $needle = mb_strtolower($search);
        $rows = array_values(array_filter($result['rows'], fn (array $r): bool => $needle === '' || str_contains(mb_strtolower($r['path']), $needle)));
        $key = ['net' => 'net', 'gross' => 'gross', 'units' => 'units', 'orders' => 'orders', 'name' => 'path'][$sort] ?? 'net';
        usort($rows, function (array $a, array $b) use ($key, $direction): int {
            $result = in_array($key, ['net', 'gross'], true) ? Decimal::compare($a[$key], $b[$key]) : ($key === 'path' ? strcasecmp($a[$key], $b[$key]) : $a[$key] <=> $b[$key]);

            return ($direction === 'asc' ? $result : -$result) ?: strcmp($a['id'], $b['id']);
        });
        $total = count($rows);
        $fmt = fn (array $r): array => [...$r, 'gross_formatted' => $this->money->format($r['gross'], $f->currency), 'discounts_formatted' => $this->money->format($r['discounts'], $f->currency), 'net_formatted' => $this->money->format($r['net'], $f->currency)];
        $slice = array_map($fmt, array_slice($rows, ($page - 1) * $perPage, $perPage));
        $paginator = (new LengthAwarePaginator($slice, $total, $perPage, $page, ['path' => request()->url()]))->withQueryString();

        return [...$this->common('categories', $f), 'mode' => $mode, 'search' => $search, 'categories' => $paginator->toArray(), 'total' => $fmt($result['total']), 'unclassified' => $fmt($result['unclassified'])];
    }

    /** @return array<string, mixed> */
    public function brands(ReportFilters $f, string $search, string $sort, string $direction, int $perPage, int $page): array
    {
        $result = $this->brands->compute($f, $f->period, $f->compare);
        $needle = mb_strtolower($search);
        $rows = array_values(array_filter($result['rows'], fn (array $r): bool => $needle === '' || str_contains(mb_strtolower($r['name']), $needle)));
        $key = in_array($sort, BrandReport::SORTS, true) ? $sort : 'net';
        usort($rows, function (array $a, array $b) use ($key, $direction): int {
            $result = match ($key) {
                'net', 'gross', 'share' => Decimal::compare($a[$key], $b[$key]),
                // Brands without a previous period to compare with sort last either way.
                'change' => $a['change'] === null || $b['change'] === null ? ($b['change'] === null) <=> ($a['change'] === null) : Decimal::compare($a['change'], $b['change']),
                'name' => strcasecmp($a['name'], $b['name']),
                default => $a[$key] <=> $b[$key],
            };

            return ($direction === 'asc' ? $result : -$result) ?: strcmp($a['id'], $b['id']);
        });
        $fmt = fn (array $r): array => [...$r, 'gross_formatted' => $this->money->format($r['gross'], $f->currency), 'discounts_formatted' => $this->money->format($r['discounts'], $f->currency), 'net_formatted' => $this->money->format($r['net'], $f->currency),
            ...(array_key_exists('previous_net', $r) ? ['previous_net_formatted' => $this->money->format($r['previous_net'], $f->currency)] : [])];
        $paginator = (new LengthAwarePaginator(array_map($fmt, array_slice($rows, ($page - 1) * $perPage, $perPage)), count($rows), $perPage, $page, ['path' => request()->url()]))->withQueryString();

        return [...$this->common('brands', $f), 'search' => $search, 'brands' => $paginator->toArray(), 'total' => $fmt($result['total']), 'unbranded' => $fmt($result['unbranded']), 'brand_count' => count($result['rows'])];
    }

    /** @return array<string, mixed> */
    public function customers(ReportFilters $f, string $view, string $sort, string $direction, int $perPage): array
    {
        $period = $f->period;
        $base = [...$this->common('customers', $f), 'view' => $view, 'summary' => $this->customers->summary($f, $period)];
        if ($view === 'no_orders') {
            $page = $this->customers->withoutOrders()->paginate($perPage)->withQueryString();
            $rows = [];
            foreach ($page->items() as $r) {
                $rows[] = ['customer_id' => (string) $r->customer_id, 'name' => trim($r->first_name.' '.$r->last_name), 'email' => $r->email, 'created_at' => CarbonImmutable::parse($r->created_at)->toIso8601String(), 'status' => $r->status];
            }
        } else {
            $page = $this->customers->ranking($f, $period, $sort, $direction)->paginate($perPage)->withQueryString();
            $rows = [];
            foreach ($page->items() as $r) {
                $orders = (int) $r->orders;

                $rows[] = ['customer_id' => $r->customer_id === null ? null : (string) $r->customer_id, 'guest' => $r->customer_id === null, 'name' => trim($r->first_name.' '.$r->last_name), 'email' => $r->email, 'orders' => $orders, 'net' => $this->money->format(Decimal::trim($r->net), $f->currency), 'total' => $this->money->format(Decimal::trim($r->total), $f->currency),
                    'aov' => $this->money->format(Decimal::trim(Decimal::divide($r->total, (string) $orders, 6)), $f->currency), 'last_order_at' => CarbonImmutable::parse($r->last_order_at)->toIso8601String()];
            }
        }
        $page->setCollection(collect($rows));

        return [...$base, 'customers' => $page->toArray()];
    }

    /** @return array<string, mixed> */
    public function inventory(string $view, ?int $threshold, ?string $status, ?int $categoryId, string $search, int $perPage): array
    {
        $summary = $this->inventory->summary();
        $page = $this->inventory->listing($view, $threshold, $status, $categoryId, $search)->paginate($perPage)->withQueryString();
        $names = $this->names->products($page->getCollection()->pluck('id')->all());
        $rows = [];
        foreach ($page->items() as $r) {
            $rows[] = ['id' => (string) $r->id, 'name' => $names[(string) $r->id] ?: '#'.$r->id, 'sku' => $r->sku, 'status' => $r->status, 'quantity' => (int) $r->quantity, 'category' => $r->category_id === null ? null : $this->tree->path((int) $r->category_id)];
        }
        $page->setCollection(collect($rows));

        return [
            'report' => 'inventory', 'applicable' => [], 'summary' => $summary, 'view' => $view, 'threshold' => $threshold ?? $summary['threshold'], 'status' => $status, 'category_id' => $categoryId, 'search' => $search, 'products' => $page->toArray(),
            'options' => ['categories' => collect($this->tree->all())->filter(fn (array $c): bool => ! $c['system'])->keys()->map(fn (int $id): array => ['value' => (string) $id, 'label' => $this->tree->path($id)])->sortBy('label')->values()->all()],
            'links' => ['can_export' => Gate::allows('reports.export'), 'can_products' => Gate::allows('products.view')],
        ];
    }

    /** @return array<string, mixed> */
    public function promotions(ReportFilters $f, int $perPage): array
    {
        $page = $this->promotions->usage($f, $f->period)->paginate($perPage)->withQueryString();
        $rows = [];
        foreach ($page->items() as $r) {
            $rows[] = ['coupon_id' => (string) $r->coupon_id, 'code' => $r->code, 'redemptions' => (int) $r->redemptions, 'discount' => $this->money->format(Decimal::trim($r->discount), $f->currency), 'shipping_discount' => $this->money->format(Decimal::trim($r->shipping_discount), $f->currency),
                'sales_orders' => (int) $r->sales_orders, 'net' => $this->money->format(Decimal::trim($r->net), $f->currency), 'total' => $this->money->format(Decimal::trim($r->total), $f->currency)];
        }
        $page->setCollection(collect($rows));

        return [...$this->common('promotions', $f), 'promotions' => $page->toArray(), 'released' => $this->promotions->released($f, $f->period)];
    }

    /** @return array<string, mixed> */
    public function overview(ReportFilters $f): array
    {
        $period = $f->period;
        $summary = $this->formatSummary($this->sales->summary($f, $period), $f->currency);
        $previous = $f->compare ? $this->sales->summary($f, $period->previous()) : null;
        $top = $this->products->ranking($f, $period, '', 'net', 'desc')->limit(5)->get();
        $topRows = $this->decorateProducts($top, fn (object $r): array => ['units' => (int) $r->units, 'net' => $this->money->format(Decimal::trim($r->net), $f->currency), 'net_raw' => Decimal::trim($r->net)]);
        $categories = $this->categories->compute($f, $period, 'direct')['rows'];
        usort($categories, fn (array $a, array $b): int => Decimal::compare($b['net'], $a['net']) ?: strcmp($a['id'], $b['id']));
        $recent = DB::table('orders as o')->join('order_statuses as s', 's.id', '=', 'o.status_id')->orderByDesc('o.created_at')->orderByDesc('o.id')->limit(7)->get(['o.id', 'o.order_number', 'o.first_name', 'o.last_name', 'o.total', 'o.currency_code', 'o.currency_decimals', 'o.created_at', 'o.status_id']);
        $statuses = $this->names->statuses();
        $stock = $this->inventory->summary();
        $lowIds = $this->inventory->listing('low')->limit(5)->get();
        $lowNames = $this->names->products($lowIds->pluck('id')->all());
        $canCustomers = Gate::allows('reports.customers') && Gate::allows('customers.view');
        $brands = $this->brands->compute($f, $period)['rows'];
        usort($brands, fn (array $a, array $b): int => Decimal::compare($b['net'], $a['net']) ?: strcmp($a['id'], $b['id']));
        $timing = $this->insights->timing($f, $period);
        $returning = $this->insights->returning($f, $period);

        return [
            ...$this->common('overview', $f),
            'summary' => $summary, 'previous_summary' => $previous === null ? null : $this->formatSummary($previous, $f->currency), 'previous_has_data' => $previous !== null && (int) $previous['orders'] > 0,
            'context' => $this->formatContext($this->sales->context($f, $period), $f->currency),
            'series' => $this->sales->series($f, $period), 'previous_series' => $f->compare ? $this->sales->series($f->forPeriod($period->previous()), $period->previous()) : null,
            'top_products' => $topRows,
            'top_categories' => array_map(fn (array $c): array => [...$c, 'net_formatted' => $this->money->format($c['net'], $f->currency)], array_slice($categories, 0, 5)),
            'top_brands' => array_map(fn (array $b): array => [...$b, 'net_formatted' => $this->money->format($b['net'], $f->currency)], array_slice($brands, 0, 5)),
            'timing' => array_map(fn (array $rows): array => array_map(fn (array $r): array => [...$r, 'net_formatted' => $this->money->format($r['net'], $f->currency)], $rows), $timing),
            'returning' => [...$returning, 'returning_total_formatted' => $this->money->format($returning['returning_total'], $f->currency)],
            'recent_orders' => $recent->map(fn (object $o): array => ['id' => (string) $o->id, 'number' => $o->order_number, 'customer' => trim($o->first_name.' '.$o->last_name), 'status' => $statuses[(string) $o->status_id] ?? null, 'total' => $this->money->format(Decimal::trim($o->total), $o->currency_code), 'created_at' => CarbonImmutable::parse($o->created_at)->toIso8601String()])->all(),
            'stock' => ['out_of_stock' => $stock['out_of_stock'], 'low_stock' => $stock['low_stock'], 'low' => $lowIds->map(fn (object $r): array => ['id' => (string) $r->id, 'name' => $lowNames[(string) $r->id] ?: '#'.$r->id, 'sku' => $r->sku, 'quantity' => (int) $r->quantity])->all()],
            'new_customers' => $canCustomers ? DB::table('customers')->where('created_at', '>=', $period->startUtc())->where('created_at', '<', $period->endUtc())->count() : null,
        ];
    }
}
