<?php

namespace App\Customer\Presentation\Http\Controllers;

use App\Customer\Infrastructure\CustomerAccounts;
use App\Customer\Infrastructure\SocialSignIn;
use App\Identity\Presentation\Http\Requests\LoginRequest;
use App\Identity\Presentation\Http\SessionLifecycle;
use App\Storefront\Infrastructure\OrderTracking;
use App\Storefront\Presentation\Http\CheckoutController;
use App\Storefront\Presentation\Http\OrderTrackingController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Customer sign-in at /login. Disabled customer profiles cannot sign in. */
final class CustomerSessionController
{
    public function create(Request $request, SocialSignIn $social): Response
    {
        CheckoutController::remember($request);

        return Inertia::render('Auth/Login', ['socialProviders' => $social->buttons()]);
    }

    public function store(LoginRequest $request, SessionLifecycle $sessions, CustomerAccounts $accounts): RedirectResponse
    {
        // The checkout's sign-in window posts here with ?return=checkout.
        CheckoutController::remember($request);
        $sessions->attempt($request);
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            return to_route('verification.notice');
        }
        $accounts->activate($user);
        $profile = $accounts->profile($user);
        if ($profile !== null && $profile->status !== 'active') {
            $sessions->end($request);
            throw ValidationException::withMessages(['email' => CustomerAccounts::blockedMessage($profile)]);
        }

        if ($profile !== null && CheckoutController::returning($request)) {
            return redirect('/checkout');
        }
        // Only an order number is kept (never an address), so this cannot redirect anywhere else.
        $number = (string) $request->session()->pull(OrderTrackingController::RETURN_NUMBER, '');
        if ($profile !== null && preg_match(OrderTracking::NUMBER, $number) === 1) {
            return redirect('/order-status?number='.rawurlencode($number));
        }

        return to_route($profile === null ? 'account.profile' : 'account');
    }

    public function destroy(Request $request, SessionLifecycle $sessions): RedirectResponse
    {
        $sessions->end($request);

        return to_route('login');
    }
}
