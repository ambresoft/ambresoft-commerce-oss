<?php

namespace App\Customer\Presentation\Http\Controllers;

use App\Customer\Infrastructure\CustomerAccounts;
use App\Storefront\Presentation\Http\CheckoutController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Customer e-mail verification; a verified e-mail attaches (claims or creates) the customer profile. */
final class CustomerVerificationController
{
    public function notice(Request $request): Response|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail() ? to_route('account') : Inertia::render('Auth/VerifyEmail');
    }

    public function verify(EmailVerificationRequest $request, CustomerAccounts $accounts): RedirectResponse
    {
        $request->fulfill();
        $accounts->activate($request->user());

        return (CheckoutController::returning($request) ? redirect('/checkout') : to_route('account'))->with('status', __('auth.verified'));
    }

    public function send(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', __('auth.verification_sent'));
    }
}
