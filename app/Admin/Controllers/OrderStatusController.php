<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Order\Domain\Service\OrderStockRules;
use App\Order\Infrastructure\OrderQueries;
use App\Order\Infrastructure\OrderStatuses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class OrderStatusController
{
    public function index(Request $request, OrderQueries $queries): Response
    {
        Gate::authorize('order-statuses.manage');
        $usage = $queries->usage();
        $versions = DB::table('order_statuses')->pluck('lock_version', 'id');

        return Inertia::render('Admin/OrderStatuses/Index', [...$this->shell($request), 'statuses' => array_map(fn (array $s): array => [...$s, 'version' => (int) $versions[$s['id']], ...$usage[$s['id']]], $queries->statuses())]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('order-statuses.manage');

        return Inertia::render('Admin/OrderStatuses/Form', [...$this->shell($request, __('orders.status_create')), 'status' => null, 'stockActions' => OrderStockRules::ACTIONS, 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()]);
    }

    public function store(Request $request, OrderStatuses $statuses): RedirectResponse
    {
        $statuses->save((string) $request->user()->id, null, null, $request->all());

        return redirect('/admin/order-statuses')->with('status', __('orders.status_saved'));
    }

    public function edit(Request $request, OrderQueries $queries, string $status): Response
    {
        Gate::authorize('order-statuses.manage');
        $row = DB::table('order_statuses')->where('id', $status)->first() ?? abort(404);
        $translations = DB::table('order_status_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.status_id', $status)->get(['l.code', 't.name', 't.description'])
            ->mapWithKeys(fn (object $t): array => [$t->code => ['name' => $t->name, 'description' => $t->description]]);
        $name = collect($queries->statuses())->firstWhere('id', $status)['name'] ?? $row->code;

        return Inertia::render('Admin/OrderStatuses/Form', [
            ...$this->shell($request, $name), 'stockActions' => OrderStockRules::ACTIONS, 'defaultLocale' => app(LocaleCatalog::class)->defaultCode(),
            'status' => ['id' => (string) $row->id, 'version' => (int) $row->lock_version, 'code' => $row->code, 'color' => $row->color, 'stock_action' => $row->stock_action, 'is_active' => (bool) $row->is_active,
                'is_initial' => (bool) $row->is_initial, 'is_final' => (bool) $row->is_final, 'sends_invoice' => (bool) $row->sends_invoice, 'customer_can_cancel' => (bool) $row->customer_can_cancel, 'is_system' => (bool) $row->is_system, 'translations' => (object) $translations->all(), ...$queries->usage()[(string) $row->id]],
        ]);
    }

    public function update(Request $request, OrderStatuses $statuses, string $status): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $statuses->save((string) $request->user()->id, $status, (int) $data['version'], $request->all());

        return redirect('/admin/order-statuses')->with('status', __('orders.status_saved'));
    }

    public function active(Request $request, OrderStatuses $statuses, string $status): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'active' => ['required', 'boolean']]);
        $statuses->setActive((string) $request->user()->id, $status, (int) $data['version'], (bool) $data['active']);

        return redirect('/admin/order-statuses')->with('status', __('orders.status_saved'));
    }

    public function order(Request $request, OrderStatuses $statuses): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:9999'], 'ids.*' => ['required', 'string', 'regex:/^[1-9][0-9]*$/D']]);
        $statuses->reorder((string) $request->user()->id, array_values($data['ids']));

        return redirect('/admin/order-statuses');
    }

    public function move(Request $request, OrderStatuses $statuses, string $status): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        $statuses->move((string) $request->user()->id, $status, $data['direction']);

        return redirect('/admin/order-statuses');
    }

    public function destroy(Request $request, OrderStatuses $statuses, string $status): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $statuses->delete((string) $request->user()->id, $status, (int) $data['version']);

        return redirect('/admin/order-statuses')->with('status', __('orders.status_deleted'));
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('orders.statuses_title'), 'href' => $current === null ? null : '/admin/order-statuses']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
