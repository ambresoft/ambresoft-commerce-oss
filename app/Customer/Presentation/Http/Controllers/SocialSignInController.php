<?php

namespace App\Customer\Presentation\Http\Controllers;

use App\Customer\Infrastructure\CustomerAccounts;
use App\Customer\Infrastructure\SocialSignIn;
use App\Customer\Infrastructure\SocialSignInFailed;
use App\Identity\Presentation\Http\SessionLifecycle;
use App\Storefront\Presentation\Http\CheckoutController;
use App\Website\Infrastructure\SocialLoginProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Customer sign-in and registration with a configured provider (the same account either way). */
final class SocialSignInController
{
    public function redirect(Request $request, SocialSignIn $social, SocialLoginProviders $providers, string $provider): RedirectResponse
    {
        abort_unless($providers->has($provider), 404);
        try {
            return redirect()->away($social->authorizationUrl($request, $provider));
        } catch (SocialSignInFailed) {
            return $this->failed();
        }
    }

    public function callback(Request $request, SocialSignIn $social, SocialLoginProviders $providers, SessionLifecycle $sessions, CustomerAccounts $accounts, string $provider): RedirectResponse
    {
        abort_unless($providers->has($provider), 404);
        try {
            $user = $social->identity($request, $provider);
        } catch (SocialSignInFailed) {
            return $this->failed();
        }
        $sessions->begin($request, $user);
        $accounts->activate($user);
        $profile = $accounts->profile($user);
        if ($profile !== null && $profile->status !== 'active') {
            $sessions->end($request);

            return redirect()->route('login')->withErrors(['email' => CustomerAccounts::blockedMessage($profile)]);
        }

        if ($profile !== null && CheckoutController::returning($request)) {
            return redirect('/checkout');
        }

        return to_route($profile === null ? 'account.profile' : 'account');
    }

    private function failed(): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => __('auth.social_failed')]);
    }
}
