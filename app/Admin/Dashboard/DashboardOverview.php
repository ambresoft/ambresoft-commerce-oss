<?php

namespace App\Admin\Dashboard;

use App\Identity\Infrastructure\Persistence\UserRecord as User;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Pricing\Application\Service\PriceDisplay;
use App\Settings\Infrastructure\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Read model for the admin dashboard. Every section is returned only when the staff member holds
 * the capability that owns the data, so the page never reveals what its viewer cannot open.
 *
 * Sales are the orders currently holding stock (a status with the "decrease" stock action, so
 * cancelled and failed orders drop out), counted in the primary currency. Period boundaries and
 * buckets follow the store timezone.
 */
final class DashboardOverview
{
    /** Range => [bucket unit, bucket count]. Each range is compared with the same-length period before it. */
    private const RANGES = ['day' => ['hour', 24], 'week' => ['day', 7], 'month' => ['day', 30]];

    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        $can = fn (string $ability): bool => Gate::forUser($user)->allows($ability);
        $timezone = (string) app(GeneralSettings::class)->all()['timezone'];
        $now = CarbonImmutable::now($timezone);
        $primary = app(PriceDisplay::class)->primary();

        $orders = $can('orders.view');
        $products = $can('products.view');
        $customers = $can('customers.view');

        return [
            'currency' => ['code' => $primary->code, 'decimals' => $primary->decimals],
            'orders' => $orders ? $this->orders($now, $timezone, $primary->code) : null,
            'catalog' => $products ? $this->catalog() : null,
            'customers' => $customers ? $this->customers($now) : null,
            'totals' => [
                'categories' => $can('categories.view') ? DB::table('categories')->whereNull('system_key')->count() : null,
                'brands' => $can('brands.view') ? DB::table('brands')->count() : null,
                'pages' => $can('pages.view') ? DB::table('pages')->count() : null,
            ],
            'activity' => $this->activity($orders, $products, $customers),
        ];
    }

    /** @return array<string, mixed> */
    private function orders(CarbonImmutable $now, string $timezone, string $currency): array
    {
        $ranges = [];
        foreach (self::RANGES as $range => [$unit, $count]) {
            $latest = $unit === 'hour' ? $now->startOfHour() : $now->startOfDay();
            $step = fn (CarbonImmutable $at, int $n): CarbonImmutable => $unit === 'hour' ? $at->subHours($n) : $at->subDays($n);
            $starts = [];
            for ($i = $count - 1; $i >= 0; $i--) {
                $starts[] = $step($latest, $i);
            }
            $boundary = $starts[0];
            $previousStart = $step($boundary, $count);

            $sums = DB::table('orders')->where('stock_held', true)->where('currency_code', $currency)->where('created_at', '>=', $previousStart)
                ->groupByRaw('1')->selectRaw('date_trunc(?, created_at AT TIME ZONE ?) AS bucket, sum(total) AS total', [$unit, $timezone])
                ->pluck('total', 'bucket');
            $series = fn (CarbonImmutable $first): array => array_map(
                fn (int $i): float => (float) ($sums[($unit === 'hour' ? $first->addHours($i) : $first->addDays($i))->format('Y-m-d H:i:s')] ?? 0),
                range(0, $count - 1),
            );
            $counts = DB::table('orders')->where('created_at', '>=', $previousStart)
                ->selectRaw('count(*) FILTER (WHERE created_at >= ?) AS current, count(*) FILTER (WHERE created_at < ?) AS previous', [$boundary, $boundary])->first();
            $current = $series($boundary);
            $previous = $series($previousStart);
            $ranges[$range] = [
                'starts' => array_map(fn (CarbonImmutable $at): string => $at->toIso8601String(), $starts),
                'current' => $current,
                'previous' => $previous,
                'count' => (int) $counts->current,
                'previous_count' => (int) $counts->previous,
            ];
        }

        $locale = app(LocaleCatalog::class);
        $current = $locale->active()->firstWhere('code', app()->getLocale())->id ?? 0;
        $default = $locale->active()->firstWhere('code', $locale->defaultCode())->id ?? 0;
        $recent = DB::table('orders as o')->join('order_statuses as s', 's.id', '=', 'o.status_id')
            ->leftJoin('order_status_translations as c', fn ($j) => $j->on('c.status_id', '=', 's.id')->where('c.locale_id', $current))
            ->leftJoin('order_status_translations as d', fn ($j) => $j->on('d.status_id', '=', 's.id')->where('d.locale_id', $default))
            ->orderByDesc('o.created_at')->orderByDesc('o.id')->limit(7)
            ->get(['o.id', 'o.order_number', 'o.first_name', 'o.last_name', 'o.total', 'o.currency_code', 'o.currency_decimals', 'o.created_at', 's.color', DB::raw('coalesce(nullif(c.name, \'\'), nullif(d.name, \'\'), s.code) AS status')])
            ->map(fn (object $o): array => [
                'id' => (string) $o->id, 'number' => $o->order_number, 'customer' => trim($o->first_name.' '.$o->last_name),
                'status' => $o->status, 'color' => $o->color, 'total' => $o->total, 'currency' => $o->currency_code, 'decimals' => (int) $o->currency_decimals,
                'created_at' => CarbonImmutable::parse($o->created_at)->toIso8601String(),
            ])->all();

        return [
            'ranges' => $ranges,
            'open' => DB::table('orders as o')->join('order_statuses as s', 's.id', '=', 'o.status_id')->where('s.is_initial', true)->count(),
            'recent' => $recent,
        ];
    }

    /** @return array{active: int, out_of_stock: int} */
    private function catalog(): array
    {
        $active = DB::table('products')->whereNull('deleted_at')->where('status', 'active');

        return [
            'active' => (clone $active)->count(),
            'out_of_stock' => (clone $active)->where('type', 'physical')->where('quantity', '<=', 0)->count(),
        ];
    }

    /** @return array{total: int, new: array<string, int>} */
    private function customers(CarbonImmutable $now): array
    {
        $new = [];
        foreach (['day' => $now->subDay(), 'week' => $now->subDays(7), 'month' => $now->subDays(30)] as $range => $since) {
            $new[$range] = DB::table('customers')->where('created_at', '>=', $since)->count();
        }

        return ['total' => DB::table('customers')->count(), 'new' => $new];
    }

    /** @return list<array{kind: string, subject: string, at: string}> */
    private function activity(bool $orders, bool $products, bool $customers): array
    {
        $entries = [];
        if ($orders) {
            foreach (DB::table('order_status_history as h')->join('orders as o', 'o.id', '=', 'h.order_id')->orderByDesc('h.created_at')->orderByDesc('h.id')->limit(6)->get(['o.order_number', 'h.from_status_id', 'h.created_at']) as $row) {
                $entries[] = ['kind' => $row->from_status_id === null ? 'order' : 'status', 'subject' => $row->order_number, 'at' => $row->created_at];
            }
        }
        if ($customers) {
            foreach (DB::table('customers')->orderByDesc('created_at')->orderByDesc('id')->limit(6)->get(['first_name', 'last_name', 'created_at']) as $row) {
                $entries[] = ['kind' => 'customer', 'subject' => trim($row->first_name.' '.$row->last_name), 'at' => $row->created_at];
            }
        }
        if ($products) {
            $locale = app(LocaleCatalog::class);
            $current = $locale->active()->firstWhere('code', app()->getLocale())->id ?? 0;
            $default = $locale->active()->firstWhere('code', $locale->defaultCode())->id ?? 0;
            $rows = DB::table('products as p')->whereNull('p.deleted_at')
                ->leftJoin('product_translations as c', fn ($j) => $j->on('c.product_id', '=', 'p.id')->where('c.locale_id', $current))
                ->leftJoin('product_translations as d', fn ($j) => $j->on('d.product_id', '=', 'p.id')->where('d.locale_id', $default))
                ->orderByDesc('p.updated_at')->orderByDesc('p.id')->limit(6)
                ->get(['p.updated_at', DB::raw("coalesce(nullif(c.name, ''), nullif(d.name, ''), p.sku, '#' || p.id) AS name")]);
            foreach ($rows as $row) {
                $entries[] = ['kind' => 'product', 'subject' => $row->name, 'at' => $row->updated_at];
            }
        }
        $entries = array_filter($entries, fn (array $e): bool => $e['at'] !== null);
        usort($entries, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_map(fn (array $e): array => [...$e, 'at' => CarbonImmutable::parse($e['at'])->toIso8601String()], array_slice($entries, 0, 8));
    }
}
