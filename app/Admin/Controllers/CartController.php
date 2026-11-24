<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Cart\Infrastructure\CartQueries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Catalog → Carts: every cart of customers and guests, read-only. */
final class CartController
{
    public function index(Request $request, CartQueries $queries): Response
    {
        Gate::authorize('carts.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:200'], 'type' => ['nullable', Rule::in(['customer', 'guest'])], 'contents' => ['nullable', Rule::in(['with', 'empty'])],
            'sort' => ['nullable', Rule::in(CartQueries::SORTS)], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'per_page' => ['nullable', Rule::in(['25', '50', '100'])], 'page' => ['nullable', 'integer', 'min:1']]);
        // An empty PHP array serializes as a JS array, whose inherited .sort would mask the default.
        $filters = ['sort' => 'updated_at', 'direction' => 'desc', 'per_page' => '25', ...array_map(strval(...), array_filter($filters, fn ($value): bool => $value !== null))];

        return Inertia::render('Admin/Carts/Index', [...$this->shell($request), 'carts' => $queries->listing($filters, Gate::allows('customers.view')), 'filters' => $filters]);
    }

    public function show(Request $request, CartQueries $queries, string $cart): Response
    {
        Gate::authorize('carts.view');
        $detail = $queries->detail($cart, Gate::allows('customers.view')) ?? abort(404);

        return Inertia::render('Admin/Carts/Show', [...$this->shell($request, __('carts.cart_number', ['id' => $cart])), 'cart' => $detail]);
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('carts.title'), 'href' => $current === null ? null : '/admin/carts']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
