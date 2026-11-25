<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Marketing\Infrastructure\CouponQueries;
use App\Marketing\Infrastructure\Coupons;
use App\Pricing\Domain\Repository\CurrencyRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CouponController
{
    public function index(Request $request, CouponQueries $queries): Response
    {
        Gate::authorize('coupons.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:128'], 'status' => ['nullable', Rule::in(['active', 'disabled'])], 'sort' => ['nullable', Rule::in(['name', 'code', 'starts_on', 'ends_on', 'status'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'per_page' => ['nullable', Rule::in([25, 50, 100])], 'page' => ['nullable', 'integer', 'between:1,1000000']]);
        $filters = ['sort' => 'name', 'direction' => 'asc', 'per_page' => '25', ...$filters];

        return Inertia::render('Admin/Coupons/Index', [...$this->shell($request), 'coupons' => $queries->listing($filters), 'filters' => $filters]);
    }

    public function create(Request $request, CouponQueries $queries): Response
    {
        Gate::authorize('coupons.manage');

        return $this->form($request, $queries, null);
    }

    public function edit(Request $request, CouponQueries $queries, string $coupon): Response
    {
        Gate::authorize('coupons.view');

        return $this->form($request, $queries, $coupon);
    }

    private function form(Request $request, CouponQueries $queries, ?string $id): Response
    {
        $data = $request->validate(['product_search' => ['nullable', 'string', 'max:100'], 'category_search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'between:1,1000000']]);
        $coupon = $id === null ? null : ($queries->editor($id) ?? abort(404));
        $currency = app(CurrencyRepository::class)->currentPrimary();

        return Inertia::render('Admin/Coupons/Form', [...$this->shell($request, $coupon['name'] ?? __('coupons.create')), 'coupon' => $coupon,
            'currency' => ['code' => $currency->code, 'decimals' => $currency->decimals],
            'productOptions' => $queries->options('product', $data['product_search'] ?? '', $coupon['product_ids'] ?? []),
            'categoryOptions' => $queries->options('category', $data['category_search'] ?? '', $coupon['category_ids'] ?? []),
            'history' => $id === null ? null : $queries->history($id),
        ]);
    }

    public function store(Request $request, Coupons $coupons): RedirectResponse
    {
        $id = $coupons->save((string) $request->user()->id, null, $request->all());

        return redirect('/admin/coupons/'.$id.'/edit')->with('status', __('coupons.saved'));
    }

    public function update(Request $request, Coupons $coupons, string $coupon): RedirectResponse
    {
        $coupons->save((string) $request->user()->id, $coupon, $request->all());

        return redirect('/admin/coupons/'.$coupon.'/edit')->with('status', __('coupons.saved'));
    }

    public function destroy(Request $request, Coupons $coupons, string $coupon): RedirectResponse
    {
        Gate::authorize('coupons.delete');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $coupons->delete((string) $request->user()->id, [['id' => $coupon, 'version' => (int) $data['version']]]);

        return redirect('/admin/coupons')->with('status', __('coupons.deleted'));
    }

    public function bulkDestroy(Request $request, Coupons $coupons): RedirectResponse
    {
        Gate::authorize('coupons.delete');
        $data = $request->validate(['rows' => ['required', 'array', 'min:1', 'max:100'], 'rows.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'rows.*.version' => ['required', 'integer', 'min:1']]);
        $coupons->delete((string) $request->user()->id, array_map(fn (array $r): array => ['id' => (string) $r['id'], 'version' => (int) $r['version']], $data['rows']));

        return redirect('/admin/coupons')->with('status', __('coupons.deleted'));
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('coupons.title'), 'href' => $current === null ? null : '/admin/coupons']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
