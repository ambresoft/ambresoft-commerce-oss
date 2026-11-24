<?php

namespace App\Customer\Presentation\Http\Controllers;

use App\Customer\Infrastructure\CustomerSelfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class AccountPasswordController
{
    public function update(Request $request, CustomerSelfService $self): RedirectResponse
    {
        $user = $request->user();
        $self->changePassword($user, (string) $request->input('current_password', ''), (string) $request->input('password', ''), (string) $request->input('password_confirmation', ''));
        // Keep this session; every other session of the identity ends on its next request (auth.session).
        $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword()));
        $request->session()->regenerate();

        return to_route('account.password')->with('status', __('account.password_saved'));
    }
}
