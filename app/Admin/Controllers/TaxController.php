<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Pricing\Application\Service\PriceDisplay;
use App\Tax\Application\Contract\TaxAdministration;
use App\Tax\Infrastructure\TaxInput;
use App\Tax\Infrastructure\TaxQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class TaxController
{
    public function index(Request $request, TaxQueries $queries): Response
    {
        Gate::authorize('taxes.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'disabled'])],
            'sort' => ['nullable', Rule::in(['name', 'priority', 'status'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $can = fn (string $ability): bool => Gate::forUser($request->user())->allows($ability);

        return Inertia::render('Admin/Taxes/Index', [
            ...$this->shell($request),
            'taxes' => $queries->listing($filters), 'filters' => (object) $filters,
            // Offered to the bulk assignment panel only to staff who may run it.
            'assignable' => $can('taxes.assign') && $can('products.update') ? array_values(array_filter($queries->productOptions([]), fn (array $tax): bool => $tax['active'])) : [],
            'productCount' => $can('taxes.assign') ? DB::table('products')->count() : 0,
        ]);
    }

    public function create(Request $request, TaxQueries $queries): Response
    {
        Gate::authorize('taxes.create');

        return Inertia::render('Admin/Taxes/Form', [...$this->shell($request, __('taxes.create')), 'tax' => null, ...$this->formData($queries)]);
    }

    public function store(Request $request, TaxInput $input, TaxAdministration $taxes): RedirectResponse
    {
        Gate::authorize('taxes.create');
        $id = $taxes->create((string) $request->user()->id, $input->data($request->all()));

        return redirect('/admin/taxes/'.$id.'/edit')->with('status', __('taxes.saved'));
    }

    public function edit(Request $request, TaxQueries $queries, string $tax): Response
    {
        Gate::authorize('taxes.view');
        $editor = $queries->editor($tax) ?? abort(404);

        return Inertia::render('Admin/Taxes/Form', [...$this->shell($request, __('taxes.edit')), 'tax' => $editor, ...$this->formData($queries)]);
    }

    public function update(Request $request, TaxInput $input, TaxAdministration $taxes, string $tax): RedirectResponse
    {
        Gate::authorize('taxes.update');
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $taxes->update((string) $request->user()->id, $tax, (int) $version, $input->data($request->except('version')));

        return redirect('/admin/taxes/'.$tax.'/edit')->with('status', __('taxes.saved'));
    }

    public function destroy(Request $request, TaxAdministration $taxes, string $tax): RedirectResponse
    {
        Gate::authorize('taxes.delete');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $taxes->delete((string) $request->user()->id, $tax, (int) $data['version']);

        return redirect('/admin/taxes')->with('status', __('taxes.deleted'));
    }

    public function assignAll(Request $request, TaxAdministration $taxes): RedirectResponse
    {
        Gate::authorize('taxes.assign');
        Gate::authorize('products.update');
        $data = $request->validate([
            'tax_ids' => ['required', 'array', 'min:1', 'max:100'], 'tax_ids.*' => ['integer', 'min:1', 'distinct'],
            // A broad operation: the request must carry an explicit confirmation.
            'confirmed' => ['accepted'],
        ], ['confirmed.accepted' => __('taxes.assign_confirm_required'), 'tax_ids.required' => __('taxes.assign_none')]);
        $result = $taxes->assignToAllProducts((string) $request->user()->id, array_map(strval(...), $data['tax_ids']));

        return redirect('/admin/taxes')->with('status', __('taxes.assigned_all', ['assignments' => $result['assignments'], 'products' => $result['products']]));
    }

    /** @return array<string, mixed> */
    private function formData(TaxQueries $queries): array
    {
        $primary = app(PriceDisplay::class)->primary();

        return ['areas' => $queries->areaOptions(), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode(), 'currency' => ['code' => $primary->code, 'symbol' => $primary->symbol, 'decimals' => $primary->decimals]];
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('taxes.title'), 'href' => $current === null ? null : '/admin/taxes']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
