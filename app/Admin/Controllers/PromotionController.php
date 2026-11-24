<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Infrastructure\CouponQueries;
use App\Marketing\Infrastructure\PromotionQueries;
use App\Marketing\Infrastructure\Promotions;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PromotionController
{
    public function index(Request $request, PromotionQueries $queries): Response
    {
        Gate::authorize('promotions.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:128'], 'status' => ['nullable', Rule::in(['active', 'disabled'])], 'mode' => ['nullable', Rule::in(['automatic', 'coupon'])], 'benefit' => ['nullable', Rule::in(['percentage', 'fixed', 'free_shipping', 'coupon'])], 'sort' => ['nullable', Rule::in(['name', 'priority', 'starts_at', 'ends_at', 'status'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'per_page' => ['nullable', Rule::in([25, 50, 100])], 'page' => ['nullable', 'integer', 'between:1,1000000']]);
        $filters = ['sort' => 'priority', 'direction' => 'asc', 'per_page' => '25', ...$filters];

        return Inertia::render('Admin/Promotions/Index', [...$this->shell($request), 'promotions' => $queries->listing($filters), 'filters' => $filters]);
    }

    public function create(Request $request, PromotionQueries $queries): Response
    {
        Gate::authorize('promotions.manage');

        return $this->form($request, $queries, null);
    }

    public function edit(Request $request, PromotionQueries $queries, string $promotion): Response
    {
        Gate::authorize('promotions.view');

        return $this->form($request, $queries, $promotion);
    }

    private function form(Request $request, PromotionQueries $queries, ?string $id): Response
    {
        $input = $request->validate(['kind' => ['nullable', Rule::in(['products', 'categories', 'groups', 'shipping', 'coupons'])], 'search' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'array', 'max:500'], 'selected.*' => ['integer', 'min:1']]);
        $promotion = $id === null ? null : ($queries->definitions([$id])[0] ?? abort(404));
        $options = [];
        foreach (['products', 'categories', 'groups', 'shipping', 'coupons'] as $kind) {
            $selected = $promotion[$kind] ?? [];
            foreach ($promotion['conditions'] ?? [] as $c) {
                $selected = [...$selected, ...($c[$kind] ?? [])];
            }
            $selected = array_values(array_unique(array_filter([...$selected, ...($promotion['excluded_'.$kind] ?? []), ...($kind === 'coupons' && $promotion !== null ? [$promotion['coupon_id']] : []), ...(($input['kind'] ?? '') === $kind ? ($input['selected'] ?? []) : [])])));
            $search = ($input['kind'] ?? '') === $kind ? ($input['search'] ?? '') : '';
            if (in_array($kind, ['products', 'categories'], true)) {
                $options[$kind] = app(CouponQueries::class)->options($kind === 'products' ? 'product' : 'category', $search, $selected);
            } else {
                $options[$kind] = $this->options($kind, $search, $selected);
            }
        }
        $currency = app(CurrencyRepository::class)->currentPrimary();

        return Inertia::render('Admin/Promotions/Form', [...$this->shell($request), 'promotion' => $promotion, 'options' => $options, 'currency' => ['code' => $currency->code, 'decimals' => $currency->decimals], 'timezone' => app(GeneralSettings::class)->all()['timezone']]);
    }

    /** @param list<string> $selected
     * @return list<array{id: string, name: string}>
     */
    private function options(string $kind, string $search, array $selected): array
    {
        if ($kind === 'coupons') {
            $q = DB::table('coupons')->whereNull('deleted_at')->select('id')->selectRaw("code || ' · ' || name as name");
        } else {
            $table = $kind === 'groups' ? 'customer_groups' : 'shipping_methods';
            $translations = $kind === 'groups' ? 'customer_group_translations' : 'shipping_method_translations';
            $key = $kind === 'groups' ? 'group_id' : 'method_id';
            $locales = DB::table('locales')->pluck('id', 'code');
            $q = DB::table($table.' as e')->leftJoin($translations.' as c', fn ($j) => $j->on('c.'.$key, '=', 'e.id')->where('c.locale_id', $locales[app()->getLocale()] ?? 0))->leftJoin($translations.' as d', fn ($j) => $j->on('d.'.$key, '=', 'e.id')->where('d.locale_id', $locales[app(LocaleCatalog::class)->defaultCode()] ?? 0))->select('e.id')->selectRaw("coalesce(c.name, d.name, '#' || e.id::text) as name");
        }
        $column = $kind === 'coupons' ? 'id' : 'e.id';
        $chosen = (clone $q)->whereIn($column, $selected)->get();
        $query = DB::query()->fromSub($q, 'choices');
        if ($search !== '') {
            $query->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }

        return $chosen->concat($query->orderBy('id')->limit(30)->get())->unique('id')->map(fn (object $r): array => ['id' => (string) $r->id, 'name' => $r->name])->values()->all();
    }

    public function store(Request $request, Promotions $promotions): RedirectResponse
    {
        $id = $promotions->save((string) $request->user()->id, null, $request->all());

        return redirect('/admin/promotions/'.$id.'/edit')->with('status', __('promotions.saved'));
    }

    public function update(Request $request, Promotions $promotions, string $promotion): RedirectResponse
    {
        $promotions->save((string) $request->user()->id, $promotion, $request->all());

        return redirect('/admin/promotions/'.$promotion.'/edit')->with('status', __('promotions.saved'));
    }

    public function status(Request $request, Promotions $promotions, string $promotion): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(['active', 'disabled'])]]);
        $promotions->status((string) $request->user()->id, $promotion, (int) $data['version'], $data['status']);

        return back()->with('status', __('promotions.saved'));
    }

    /** @return array<string, mixed> */
    private function shell(Request $request): array
    {
        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('promotions.title'), 'href' => '/admin/promotions']]];
    }
}
