<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Shipping\Infrastructure\ShippingMethods;
use App\Shipping\Infrastructure\ShippingQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ShippingMethodController
{
    public function index(Request $request, ShippingQueries $queries): Response
    {
        Gate::authorize('shipping-methods.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'calculation' => ['nullable', Rule::in(['manual', 'carrier'])], 'status' => ['nullable', Rule::in(['active', 'disabled'])], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        return Inertia::render('Admin/ShippingMethods/Index', [...$this->shell($request), 'methods' => $queries->listing($filters), 'filters' => (object) $filters,
            'carriers' => array_map(fn (array $p): array => ['id' => $p['id'], 'name' => $p['name']], $queries->formOptions()['providers'])]);
    }

    public function create(Request $request, ShippingQueries $queries): Response
    {
        Gate::authorize('shipping-methods.manage');

        return Inertia::render('Admin/ShippingMethods/Form', [...$this->shell($request, __('shipping.create')), 'method' => null, ...$queries->formOptions(), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()]);
    }

    public function store(Request $request, ShippingMethods $methods): RedirectResponse
    {
        $id = $methods->save((string) $request->user()->id, null, null, $request->all());

        return redirect('/admin/shipping-methods/'.$id.'/edit')->with('status', __('shipping.saved'));
    }

    public function edit(Request $request, ShippingQueries $queries, string $method): Response
    {
        Gate::authorize('shipping-methods.manage');
        $editor = $queries->editor($method) ?? abort(404);

        return Inertia::render('Admin/ShippingMethods/Form', [...$this->shell($request, $editor['name']), 'method' => $editor, ...$queries->formOptions(), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()]);
    }

    public function update(Request $request, ShippingMethods $methods, string $method): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $methods->save((string) $request->user()->id, $method, (int) $data['version'], $request->except('version'));

        return redirect('/admin/shipping-methods/'.$method.'/edit')->with('status', __('shipping.saved'));
    }

    public function image(string $method): StreamedResponse
    {
        abort_unless(Gate::any(['shipping-methods.view', 'shipping-methods.manage']), 403);
        $media = DB::table('shipping_methods')->where('id', $method)->value('image_media_id') ?? abort(404);

        return app(PrivateImageStorage::class)->response(MediaFileRecord::findOrFail($media));
    }

    public function active(Request $request, ShippingMethods $methods, string $method): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'active' => ['required', 'boolean']]);
        $methods->setActive((string) $request->user()->id, $method, (int) $data['version'], (bool) $data['active']);

        return back()->with('status', __('shipping.saved'));
    }

    public function order(Request $request, ShippingMethods $methods): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['required', 'integer', 'min:1']]);
        $methods->reorder((string) $request->user()->id, array_map(strval(...), array_values($data['ids'])));

        return back();
    }

    public function destroy(Request $request, ShippingMethods $methods, string $method): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $methods->delete((string) $request->user()->id, $method, (int) $data['version']);

        return redirect('/admin/shipping-methods')->with('status', __('shipping.deleted'));
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('shipping.title'), 'href' => $current === null ? null : '/admin/shipping-methods']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
