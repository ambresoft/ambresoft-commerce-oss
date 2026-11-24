<?php

namespace App\Customer\Presentation\Http\Controllers;

use App\Customer\Infrastructure\CustomerSelfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The customer's own address book: add, edit, remove and choose the default billing and shipping address. */
final class AccountAddressController
{
    public function store(Request $request, CustomerSelfService $self): RedirectResponse
    {
        $self->addAddress($request->user(), (int) $request->input('version'), $request->except(['version']));

        return to_route('account.addresses')->with('status', __('account.address_saved'));
    }

    public function update(Request $request, CustomerSelfService $self, string $address): RedirectResponse
    {
        $self->updateAddress($request->user(), $address, (int) $request->input('version'), $request->except(['version']));

        return to_route('account.addresses')->with('status', __('account.address_saved'));
    }

    public function destroy(Request $request, CustomerSelfService $self, string $address): RedirectResponse
    {
        $self->deleteAddress($request->user(), $address, (int) $request->input('version'));

        return to_route('account.addresses')->with('status', __('account.address_deleted'));
    }

    public function makeDefault(Request $request, CustomerSelfService $self, string $address): RedirectResponse
    {
        $self->makeDefault($request->user(), $address, (int) $request->input('version'), (string) $request->input('type'));

        return to_route('account.addresses')->with('status', __('account.default_saved'));
    }
}
