<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Admin\Requests\ReportRequest;
use App\Reporting\Infrastructure\BrandReport;
use App\Reporting\Infrastructure\CategoryReport;
use App\Reporting\Infrastructure\CustomerReport;
use App\Reporting\Infrastructure\InventoryReport;
use App\Reporting\Infrastructure\ProductReport;
use App\Reporting\Infrastructure\ReportExport;
use App\Reporting\Infrastructure\ReportViews;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analytics & Reports: one screen per report over the shared filters. Authorization lives in ReportRequest (reports.view,
 * plus orders.view / customers.view where the report names people, reports.export for CSV); the controller only chooses
 * the view and the report class does the arithmetic.
 */
final class ReportController
{
    public function __construct(private ReportViews $views, private AdminNavigation $navigation) {}

    public function overview(ReportRequest $request): Response
    {
        return $this->page($request, 'Overview', $this->views->overview($request->filters()), 'overview');
    }

    public function sales(ReportRequest $request): Response
    {
        return $this->page($request, 'Sales', $this->views->sales($request->filters(), $request->choice('sort', ['paid_at', 'total', 'number', 'net', 'quantity'], 'paid_at'), $request->direction(), $request->perPage()), 'sales');
    }

    public function orders(ReportRequest $request): Response
    {
        return $this->page($request, 'Orders', $this->views->orders($request->filters(), $request->choice('sort', ['date', 'total', 'number'], 'date'), $request->direction(), $request->perPage()), 'orders');
    }

    public function products(ReportRequest $request): Response
    {
        return $this->page($request, 'Products', $this->views->products($request->filters(), $request->choice('view', ['ranking', 'declining', 'no_sales', 'never_sold'], 'ranking'), $request->text('search'), $request->choice('sort', ProductReport::SORTS, 'net'), $request->direction(), $request->perPage()), 'products');
    }

    public function categories(ReportRequest $request): Response
    {
        return $this->page($request, 'Categories', $this->views->categories($request->filters(), $request->choice('mode', ['direct', 'tree'], 'direct'), $request->text('search'), $request->choice('sort', CategoryReport::SORTS, 'net'), $request->direction(), $request->perPage(), $request->pageNumber()), 'categories');
    }

    public function brands(ReportRequest $request): Response
    {
        return $this->page($request, 'Brands', $this->views->brands($request->filters(), $request->text('search'), $request->choice('sort', BrandReport::SORTS, 'net'), $request->direction(), $request->perPage(), $request->pageNumber()), 'brands');
    }

    public function customers(ReportRequest $request): Response
    {
        return $this->page($request, 'Customers', $this->views->customers($request->filters(), $request->choice('view', ['ranking', 'no_orders'], 'ranking'), $request->choice('sort', CustomerReport::SORTS, 'spend'), $request->direction(), $request->perPage()), 'customers');
    }

    public function inventory(ReportRequest $request): Response
    {
        return $this->page($request, 'Inventory', $this->views->inventory($request->choice('view', InventoryReport::VIEWS, 'out_of_stock'), $request->validated('threshold') === null ? null : (int) $request->validated('threshold'), $request->validated('product_status'), $request->validated('category_id') === null ? null : (int) $request->validated('category_id'), $request->text('search'), $request->perPage()), 'inventory');
    }

    public function promotions(ReportRequest $request): Response
    {
        return $this->page($request, 'Promotions', $this->views->promotions($request->filters(), $request->perPage()), 'promotions');
    }

    /** CSV of the report table behind the current filters, streamed from the same query the screen paginates. */
    public function export(ReportRequest $request, ReportExport $export): StreamedResponse
    {
        $report = (string) $request->route()?->defaults['report'];
        $sorts = ['sales' => ['paid_at', 'total', 'number', 'net', 'quantity'], 'orders' => ['date', 'total', 'number'], 'products' => ProductReport::SORTS, 'customers' => CustomerReport::SORTS, 'brands' => BrandReport::SORTS];
        $csv = $export->build($report, $request->filters(), [
            'sort' => $request->choice('sort', $sorts[$report] ?? ['net'], ['sales' => 'paid_at', 'orders' => 'date', 'customers' => 'spend'][$report] ?? 'net'), 'direction' => $request->direction(), 'search' => $request->text('search'),
            'view' => $request->choice('view', InventoryReport::VIEWS, 'out_of_stock'), 'mode' => $request->choice('mode', ['direct', 'tree'], 'direct'),
            'threshold' => $request->validated('threshold') === null ? null : (int) $request->validated('threshold'), 'status' => $request->validated('product_status'), 'category_id' => $request->validated('category_id') === null ? null : (int) $request->validated('category_id'),
        ]);

        return response()->streamDownload(function () use ($csv): void {
            $out = fopen('php://output', 'wb');
            // Byte order mark so spreadsheet applications read Greek and other non-ASCII text as UTF-8.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $csv['header']);
            foreach ($csv['rows'] as $row) {
                // Spreadsheet formulas in product names, codes or e-mails must not run when the file is opened.
                fputcsv($out, array_map(fn (string $v): string => $v !== '' && str_contains('=+-@', $v[0]) && ! is_numeric($v) ? "'".$v : $v, $row));
            }
            fclose($out);
        }, $csv['filename'], ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @param array<string, mixed> $props */
    private function page(ReportRequest $request, string $component, array $props, string $report): Response
    {
        return Inertia::render('Admin/Reports/'.$component, [
            'navigation' => $this->navigation->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('reports.title'), 'href' => $report === 'overview' ? null : '/admin/reports'], ...($report === 'overview' ? [] : [['label' => __('reports.'.$report.'_title'), 'href' => null]])],
            ...$props,
        ]);
    }
}
