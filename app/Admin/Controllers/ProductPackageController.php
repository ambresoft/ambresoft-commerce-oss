<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Catalog\Infrastructure\CategoryQueries;
use App\Catalog\Infrastructure\ProductCompanions;
use App\Catalog\Infrastructure\ProductPackages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ProductPackageController
{
    public function index(Request $request, ProductPackages $packages): Response
    {
        Gate::authorize('products.view');
        $v = $request->validate(['package' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100'], 'assigned_page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $search = trim((string) ($v['search'] ?? ''));
        $selected = isset($v['package']) ? $packages->editor((string) $v['package'], (int) ($v['assigned_page'] ?? 1)) : null;
        $categoryIds = DB::table('categories')->whereNull('system_key')->orderBy('id')->pluck('id')->map(strval(...))->all();
        $paths = app(CategoryQueries::class)->paths($categoryIds);

        return Inertia::render('Admin/ProductPackages/Index', ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => [['label' => __('packages.title'), 'href' => null]],
            'packages' => $packages->options(), 'selected' => $selected, 'items' => app(ProductCompanions::class)->describe($selected['items'] ?? []),
            'candidates' => $search === '' ? [] : app(ProductCompanions::class)->search('0', $search), 'productSearch' => $search,
            'categories' => array_map(fn ($id): array => ['id' => $id, 'name' => implode(' › ', $paths[$id] ?? [])], $categoryIds)]);
    }

    public function save(Request $request, ProductPackages $packages): RedirectResponse
    {
        $v = $request->validate(['id' => ['nullable', 'integer', 'min:1']]);
        $id = $packages->save((string) $request->user()->id, isset($v['id']) ? (string) $v['id'] : null, $request->all());

        return redirect('/admin/product-packages?package='.$id)->with('status', __('variations.saved'));
    }

    public function delete(Request $request, ProductPackages $packages, string $package): RedirectResponse
    {
        $v = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $packages->delete((string) $request->user()->id, $package, (int) $v['version']);

        return redirect('/admin/product-packages');
    }

    public function assign(Request $request, ProductPackages $packages): RedirectResponse
    {
        $v = $request->validate(['package' => ['nullable', 'integer', 'min:1'], 'products' => ['present', 'array', 'max:100'], 'products.*' => ['integer', 'min:1'], 'category' => ['nullable', 'integer', 'min:1']]);
        $packages->assign((string) $request->user()->id, isset($v['package']) ? (string) $v['package'] : null, array_map(strval(...), $v['products']), isset($v['category']) ? (string) $v['category'] : null);

        return back()->with('status', __('variations.saved'));
    }
}
