<?php

namespace App\Customer\Presentation\Http\Controllers;

use App\Customer\Infrastructure\CustomerAccounts;
use App\Customer\Infrastructure\CustomerSelfService;
use App\Identity\Presentation\Http\SessionLifecycle;
use App\Storefront\Presentation\Http\CheckoutController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The signed-in customer's own profile changes and account deletion (A100); the pages are the Account layout. */
final class AccountController
{
    public function update(Request $request, CustomerSelfService $self): RedirectResponse
    {
        $self->updateProfile($request->user(), (int) $request->input('version'), $request->only(['first_name', 'last_name', 'phone']));

        return to_route('account')->with('status', __('account.saved'));
    }

    /**
     * A soft delete: the profile is disabled and kept with everything it owns. The session ends here; the customer
     * must type the account's e-mail address to confirm.
     */
    public function destroy(Request $request, CustomerSelfService $self, SessionLifecycle $sessions): RedirectResponse
    {
        $self->deleteAccount($request->user(), (int) $request->input('version'), (string) $request->input('confirmation', ''));
        $sessions->end($request);

        return to_route('login')->with('status', __('account.deleted'));
    }

    /** A verified identity without a customer profile (for example a staff member) gives the profile's name once. */
    public function profile(Request $request, CustomerAccounts $accounts): Response|RedirectResponse
    {
        return $accounts->activate($request->user()) !== null ? to_route('account') : Inertia::render('Customer/CompleteProfile');
    }

    public function storeProfile(Request $request, CustomerAccounts $accounts): RedirectResponse
    {
        $accounts->complete($request->user(), (string) $request->input('first_name', ''), (string) $request->input('last_name', ''));

        return CheckoutController::returning($request) ? redirect('/checkout') : to_route('account');
    }
}
