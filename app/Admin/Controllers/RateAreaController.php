<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Infrastructure\Geography\Countries;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Infrastructure\RateAreaInput;
use App\Tax\Infrastructure\TaxQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class RateAreaController
{
    public function index(Request $request, TaxQueries $queries): Response
    {
        Gate::authorize('rate-areas.view');

        return Inertia::render('Admin/RateAreas/Index', [...$this->shell($request), 'areas' => $queries->areaListing()]);
    }

    public function create(Request $request, Countries $countries): Response
    {
        Gate::authorize('rate-areas.manage');

        return Inertia::render('Admin/RateAreas/Form', [...$this->shell($request, __('taxes.area_create')), 'area' => null, 'countries' => $countries->options(), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()]);
    }

    public function store(Request $request, RateAreaInput $input, RateAreaAdministration $areas): RedirectResponse
    {
        Gate::authorize('rate-areas.manage');
        $id = $areas->create((string) $request->user()->id, $input->data($request->all()));

        return redirect('/admin/rate-areas/'.$id.'/edit')->with('status', __('taxes.area_saved'));
    }

    public function edit(Request $request, TaxQueries $queries, Countries $countries, string $area): Response
    {
        Gate::authorize('rate-areas.view');
        $editor = $queries->areaEditor($area) ?? abort(404);

        return Inertia::render('Admin/RateAreas/Form', [...$this->shell($request, __('taxes.area_edit')), 'area' => $editor, 'countries' => $countries->options(), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()]);
    }

    public function update(Request $request, RateAreaInput $input, RateAreaAdministration $areas, string $area): RedirectResponse
    {
        Gate::authorize('rate-areas.manage');
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $areas->update((string) $request->user()->id, $area, (int) $version, $input->data($request->except('version')));

        return redirect('/admin/rate-areas/'.$area.'/edit')->with('status', __('taxes.area_saved'));
    }

    public function destroy(Request $request, RateAreaAdministration $areas, string $area): RedirectResponse
    {
        Gate::authorize('rate-areas.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $areas->delete((string) $request->user()->id, $area, (int) $data['version']);

        return redirect('/admin/rate-areas')->with('status', __('taxes.area_deleted'));
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('taxes.areas_title'), 'href' => $current === null ? null : '/admin/rate-areas']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
