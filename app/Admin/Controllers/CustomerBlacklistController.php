<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Customer\Application\Contract\CustomerBlacklistAdministration;
use App\Customer\Infrastructure\ContactNormalizer;
use App\Customer\Infrastructure\CustomerQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerBlacklistController
{
    public function index(Request $request): Response
    {
        Gate::authorize('customer-blacklist.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'state' => ['nullable', Rule::in(['active', 'released'])], 'kind' => ['nullable', Rule::in(['email', 'phone'])], 'scope' => ['nullable', Rule::in(['full', 'payment_methods'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $query = DB::table('customer_blacklist_entries as e')->leftJoin('customers as c', 'c.id', '=', 'e.customer_id')->leftJoin('users as u', 'u.id', '=', 'e.created_by')
            ->select(['e.id', 'e.kind', 'e.value', 'e.reason', 'e.scope', 'e.created_at', 'e.released_at', 'e.customer_id', 'c.first_name', 'c.last_name', 'u.name as actor'])
            ->when($filters['search'] ?? null, function ($q, string $search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $digits = ContactNormalizer::phone($search);
                $q->where(fn ($q) => $q->where('e.value', 'ilike', $like)->when($digits !== null, fn ($q) => $q->orWhere('e.value', 'like', '%'.$digits.'%'))->orWhere('e.reason', 'ilike', $like));
            })
            ->when($filters['kind'] ?? null, fn ($q, string $kind) => $q->where('e.kind', $kind))
            ->when($filters['scope'] ?? null, fn ($q, string $scope) => $q->where('e.scope', $scope))
            ->when(($filters['state'] ?? null) === 'active', fn ($q) => $q->whereNull('e.released_at'))
            ->when(($filters['state'] ?? null) === 'released', fn ($q) => $q->whereNotNull('e.released_at'))
            ->orderByRaw('e.released_at IS NOT NULL')->orderByDesc('e.created_at')->orderByDesc('e.id');
        $page = $query->paginate(25)->withQueryString();
        $methods = app(CustomerQueries::class)->blacklistMethods($page->getCollection()->pluck('id')->map(strval(...))->all());
        $page->setCollection($page->getCollection()->map(fn ($row): array => [
            'id' => (string) $row->id, 'kind' => $row->kind, 'value' => $row->value, 'reason' => $row->reason, 'created_at' => (string) $row->created_at,
            'scope' => $row->scope, 'payment_methods' => $methods[(string) $row->id] ?? [],
            'released' => $row->released_at !== null, 'actor' => $row->actor,
            'customer' => $row->customer_id === null ? null : ['id' => (string) $row->customer_id, 'name' => trim($row->first_name.' '.$row->last_name)],
        ]));

        return Inertia::render('Admin/CustomerBlacklist/Index', [
            'navigation' => app(AdminNavigation::class)->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('customers.blacklist_title'), 'href' => null]],
            'entries' => $page, 'filters' => (object) $filters,
            'paymentMethods' => Gate::allows('customer-blacklist.manage') ? app(CustomerQueries::class)->paymentMethodOptions() : [],
        ]);
    }

    public function store(Request $request, CustomerBlacklistAdministration $blacklist): RedirectResponse
    {
        Gate::authorize('customer-blacklist.manage');
        $data = $request->validate(['kind' => ['required', Rule::in(['email', 'phone'])], 'value' => ['required', 'string', 'max:254'], 'reason' => ['required', 'string', 'max:1000'],
            'scope' => ['sometimes', Rule::in(['full', 'payment_methods'])], 'payment_method_ids' => ['sometimes', 'array', 'max:50']]);
        $blacklist->add((string) $request->user()->id, $data['kind'], $data['value'], $data['reason'], $data['scope'] ?? 'full', array_map(strval(...), $data['payment_method_ids'] ?? []));

        return redirect('/admin/customer-blacklist')->with('status', __('customers.blacklist_added'));
    }

    public function destroy(Request $request, CustomerBlacklistAdministration $blacklist, string $entry): RedirectResponse
    {
        Gate::authorize('customer-blacklist.manage');
        $blacklist->release((string) $request->user()->id, $entry);

        return back()->with('status', __('customers.blacklist_released'));
    }
}
