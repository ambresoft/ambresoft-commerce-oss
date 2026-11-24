<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Geography\Application\Contract\GeographyAdministration;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Infrastructure\Geography\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class RegionController
{
    public function index(Request $request, Countries $countries): Response
    {
        Gate::authorize('regions.view');
        $shipping = DB::table('shipping_countries')->pluck('country_code')->all();
        $options = array_values(array_filter($countries->options(), fn (array $c): bool => in_array($c['code'], $shipping, true)));
        $filters = $request->validate([
            'country' => ['nullable', Rule::in($shipping)], 'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['active', 'disabled'])], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $country = $filters['country'] ?? ($options[0]['code'] ?? null);
        $locales = app(LocaleCatalog::class)->active();
        $default = $locales->firstWhere('is_default', true)?->id;
        $current = $locales->where('code', app()->getLocale())->pluck('id')->first() ?? $default;
        $query = DB::table('regions as r')->where('r.country_code', $country ?? '')
            ->leftJoin('region_translations as c', fn ($j) => $j->on('c.region_id', '=', 'r.id')->where('c.locale_id', $current))
            ->leftJoin('region_translations as d', fn ($j) => $j->on('d.region_id', '=', 'r.id')->where('d.locale_id', $default))
            ->selectRaw('r.id, r.code, r.status, r.lock_version, coalesce(c.name, d.name, r.code) as name')
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('r.status', $status))
            ->when($filters['search'] ?? null, function ($q, string $search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $q->where(fn ($q) => $q->where('r.code', 'ilike', $like)->orWhereExists(fn ($e) => $e->selectRaw('1')->from('region_translations as s')->whereColumn('s.region_id', 'r.id')->where('s.name', 'ilike', $like)));
            })
            ->orderByRaw('lower(coalesce(c.name, d.name, r.code))')->orderBy('r.code');
        $page = $query->paginate(25)->withQueryString();
        $ids = $page->getCollection()->pluck('id')->all();
        $names = [];
        foreach (DB::table('region_translations')->whereIn('region_translations.region_id', $ids)->join('locales', 'locales.id', '=', 'region_translations.locale_id')->get(['region_translations.region_id', 'locales.code', 'region_translations.name']) as $row) {
            $names[$row->region_id][$row->code] = $row->name;
        }
        $page->setCollection($page->getCollection()->map(fn ($row): array => [
            'id' => (string) $row->id, 'code' => $row->code, 'name' => $row->name, 'status' => $row->status, 'version' => (int) $row->lock_version,
            'names' => (object) ($names[$row->id] ?? []),
        ]));

        return Inertia::render('Admin/Regions/Index', [
            'navigation' => app(AdminNavigation::class)->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('geography.regions_title'), 'href' => null]],
            'regions' => $page, 'filters' => (object) [...$filters, 'country' => $country],
            'countries' => $options, 'defaultLocale' => app(LocaleCatalog::class)->defaultCode(),
        ]);
    }

    public function store(Request $request, GeographyAdministration $geography): RedirectResponse
    {
        Gate::authorize('regions.manage');
        $data = $request->validate([
            'country_code' => ['required', 'string', 'size:2'], 'code' => ['required', 'string', 'max:10'],
            'names' => ['required', 'array'], 'names.*' => ['nullable', 'string', 'max:120'], 'status' => ['required', Rule::in(['active', 'disabled'])],
        ]);
        $geography->createRegion((string) $request->user()->id, strtoupper($data['country_code']), $data['code'], $data['names'], $data['status']);

        return back()->with('status', __('geography.region_saved'));
    }

    public function update(Request $request, GeographyAdministration $geography, string $region): RedirectResponse
    {
        Gate::authorize('regions.manage');
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'], 'code' => ['required', 'string', 'max:10'],
            'names' => ['required', 'array'], 'names.*' => ['nullable', 'string', 'max:120'], 'status' => ['required', Rule::in(['active', 'disabled'])],
        ]);
        $geography->updateRegion((string) $request->user()->id, $region, (int) $data['version'], $data['code'], $data['names'], $data['status']);

        return back()->with('status', __('geography.region_saved'));
    }

    public function destroy(Request $request, GeographyAdministration $geography, string $region): RedirectResponse
    {
        Gate::authorize('regions.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $geography->deleteRegion((string) $request->user()->id, $region, (int) $data['version']);

        return back()->with('status', __('geography.region_deleted'));
    }

    public function status(Request $request, GeographyAdministration $geography): RedirectResponse
    {
        Gate::authorize('regions.manage');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer'], 'status' => ['required', Rule::in(['active', 'disabled'])]]);
        $geography->setRegionStatus((string) $request->user()->id, array_map('strval', $data['ids']), $data['status']);

        return back()->with('status', __('geography.regions_status_saved'));
    }
}
