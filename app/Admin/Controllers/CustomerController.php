<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Customer\Application\Contract\CustomerAdministration;
use App\Customer\Application\Contract\CustomerBlacklistAdministration;
use App\Customer\Infrastructure\CustomerInput;
use App\Customer\Infrastructure\CustomerInvitations;
use App\Customer\Infrastructure\CustomerQueries;
use App\Geography\Application\Contract\ShippingGeography;
use App\Identity\Infrastructure\Security\AccountSecurity;
use App\Shared\Infrastructure\Geography\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerController
{
    public function index(Request $request, CustomerQueries $queries): Response
    {
        Gate::authorize('customers.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(['active', 'disabled'])],
            'group' => ['nullable', 'integer', 'min:1', 'exists:customer_groups,id'], 'blacklisted' => ['nullable', Rule::in(['yes', 'no'])], 'account' => ['nullable', Rule::in(['yes', 'no'])],
            'sort' => ['nullable', Rule::in(['created_at', 'name', 'email'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        return Inertia::render('Admin/Customers/Index', [
            ...$this->shell($request), 'customers' => $queries->listing($filters), 'filters' => (object) $filters,
            'groups' => array_map(fn (array $g): array => ['id' => $g['id'], 'name' => $g['name']], $queries->groupListing()),
        ]);
    }

    public function create(Request $request, CustomerQueries $queries, Countries $countries): Response
    {
        Gate::authorize('customers.create');

        return Inertia::render('Admin/Customers/Form', [...$this->shell($request, __('customers.create')), 'customer' => null, 'groups' => $queries->groupOptions(), 'countries' => $countries->options(), 'regions' => app(ShippingGeography::class)->regionOptions()]);
    }

    public function store(Request $request, CustomerInput $input, CustomerAdministration $customers): RedirectResponse
    {
        Gate::authorize('customers.create');
        $id = $customers->create((string) $request->user()->id, $input->data($request->all(), true));

        return redirect('/admin/customers/'.$id)->with('status', __('customers.saved'));
    }

    public function show(Request $request, CustomerQueries $queries, Countries $countries, string $customer): Response
    {
        Gate::authorize('customers.view');
        $detail = $queries->detail($customer) ?? abort(404);

        return Inertia::render('Admin/Customers/Show', [...$this->shell($request, trim($detail['first_name'].' '.$detail['last_name'])), 'customer' => $detail, 'security' => Gate::allows('account-security.view') && ($identity = DB::table('customers')->where('id', $customer)->value('user_id')) !== null ? app(AccountSecurity::class)->summary((string) $identity) : null, 'paymentMethods' => fn () => Gate::allows('customer-blacklist.manage') ? $queries->paymentMethodOptions() : [], 'countries' => $countries->options(), 'regions' => app(ShippingGeography::class)->regionOptions()]);
    }

    public function edit(Request $request, CustomerQueries $queries, Countries $countries, string $customer): Response
    {
        Gate::authorize('customers.update');
        $detail = $queries->detail($customer) ?? abort(404);

        return Inertia::render('Admin/Customers/Form', [...$this->shell($request, __('customers.edit')), 'customer' => $detail, 'groups' => $queries->groupOptions($detail['group_ids']), 'countries' => $countries->options(), 'regions' => app(ShippingGeography::class)->regionOptions()]);
    }

    public function update(Request $request, CustomerInput $input, CustomerAdministration $customers, string $customer): RedirectResponse
    {
        Gate::authorize('customers.update');
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $customers->update((string) $request->user()->id, $customer, (int) $version, $input->data($request->except('version'), false));

        return redirect('/admin/customers/'.$customer)->with('status', __('customers.saved'));
    }

    public function status(Request $request, CustomerAdministration $customers, string $customer): RedirectResponse
    {
        Gate::authorize('customers.status');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(['active', 'disabled'])]]);
        $customers->changeStatus((string) $request->user()->id, $customer, (int) $data['version'], $data['status']);

        return redirect('/admin/customers/'.$customer)->with('status', __($data['status'] === 'disabled' ? 'customers.disabled_done' : 'customers.restored_done'));
    }

    public function blacklist(Request $request, CustomerBlacklistAdministration $blacklist, string $customer): RedirectResponse
    {
        Gate::authorize('customer-blacklist.manage');
        $data = $request->validate(['kinds' => ['required', 'array', 'min:1', 'max:2'], 'kinds.*' => ['in:email,phone', 'distinct'], 'reason' => ['required', 'string', 'max:1000'],
            'scope' => ['sometimes', Rule::in(['full', 'payment_methods'])], 'payment_method_ids' => ['sometimes', 'array', 'max:50']]);
        $blacklist->addForCustomer((string) $request->user()->id, $customer, $data['kinds'], $data['reason'], $data['scope'] ?? 'full', array_map(strval(...), $data['payment_method_ids'] ?? []));

        return redirect('/admin/customers/'.$customer)->with('status', __('customers.blacklist_added'));
    }

    public function storeAddress(Request $request, CustomerInput $input, CustomerAdministration $customers, string $customer): RedirectResponse
    {
        Gate::authorize('customers.update');
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $customers->addAddress((string) $request->user()->id, $customer, (int) $version, $input->address($request->except('version')));

        return redirect('/admin/customers/'.$customer)->with('status', __('customers.address_saved'));
    }

    public function updateAddress(Request $request, CustomerInput $input, CustomerAdministration $customers, string $customer, string $address): RedirectResponse
    {
        Gate::authorize('customers.update');
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $saved = $input->savedRegion(DB::table('customer_addresses')->where('id', $address)->where('customer_id', $customer)->first(['country_code', 'region_code']));
        $customers->updateAddress((string) $request->user()->id, $customer, $address, (int) $version, $input->address($request->except('version'), $saved));

        return redirect('/admin/customers/'.$customer)->with('status', __('customers.address_saved'));
    }

    public function destroyAddress(Request $request, CustomerAdministration $customers, string $customer, string $address): RedirectResponse
    {
        Gate::authorize('customers.update');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $customers->deleteAddress((string) $request->user()->id, $customer, $address, (int) $data['version']);

        return redirect('/admin/customers/'.$customer)->with('status', __('customers.address_deleted'));
    }

    public function invite(Request $request, CustomerInvitations $invitations, string $customer): RedirectResponse
    {
        Gate::authorize('customers.update');
        $invitations->send((string) $request->user()->id, $customer);

        return back()->with('status', __('customers.invite_sent'));
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('customers.title'), 'href' => $current === null ? null : '/admin/customers']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
