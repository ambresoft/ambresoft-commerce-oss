<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Geography\Application\Contract\GeographyAdministration;
use App\Shared\Infrastructure\Geography\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CountryController
{
    private const PER_PAGE = 25;

    public function index(Request $request, Countries $countries): Response
    {
        Gate::authorize('countries.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'], 'shipping' => ['nullable', Rule::in(['yes', 'no'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $ships = DB::table('shipping_countries')->pluck('country_code')->flip();
        $regions = DB::table('regions')->selectRaw('country_code, count(*) as total')->groupBy('country_code')->pluck('total', 'country_code');
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $rows = array_values(array_filter($countries->options(), function (array $country) use ($search, $filters, $ships): bool {
            if ($search !== '' && ! str_contains(mb_strtolower($country['name']), $search) && ! str_contains(mb_strtolower($country['code']), $search)) {
                return false;
            }

            return match ($filters['shipping'] ?? null) {
                'yes' => $ships->has($country['code']),
                'no' => ! $ships->has($country['code']),
                default => true,
            };
        }));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $paginator = new LengthAwarePaginator(array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($rows), self::PER_PAGE, $page, ['path' => $request->url(), 'query' => $request->query()]);
        $paginator->setCollection($paginator->getCollection()->map(fn (array $country): array => [
            ...$country, 'shipping' => $ships->has($country['code']), 'regions' => (int) ($regions[$country['code']] ?? 0),
        ])->values());

        return Inertia::render('Admin/Countries/Index', [
            'navigation' => app(AdminNavigation::class)->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('geography.countries_title'), 'href' => null]],
            'countries' => $paginator, 'filters' => (object) $filters, 'shippingTotal' => $ships->count(),
        ]);
    }

    public function update(Request $request, GeographyAdministration $geography, string $country): RedirectResponse
    {
        Gate::authorize('countries.manage');
        $data = $request->validate(['shipping' => ['required', 'boolean']]);
        $geography->setShipping((string) $request->user()->id, $country, (bool) $data['shipping']);

        return back()->with('status', __($data['shipping'] ? 'geography.country_enabled' : 'geography.country_disabled'));
    }
}
