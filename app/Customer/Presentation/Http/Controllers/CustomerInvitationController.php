<?php

namespace App\Customer\Presentation\Http\Controllers;

use App\Customer\Infrastructure\CustomerInvitations;
use App\Identity\Presentation\Http\SessionLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Accepting a staff invitation: set a password, which verifies the e-mail, claims the profile and signs in. */
final class CustomerInvitationController
{
    public function show(CustomerInvitations $invitations, string $user, string $hash): Response|RedirectResponse
    {
        $identity = $invitations->invited($user, $hash);

        return $identity === null
            ? to_route('login')->with('status', __('account.invitation_invalid'))
            : Inertia::render('Customer/AcceptInvitation', ['email' => $identity->email]);
    }

    public function accept(Request $request, CustomerInvitations $invitations, SessionLifecycle $sessions, string $user, string $hash): RedirectResponse
    {
        $identity = $invitations->invited($user, $hash) ?? abort(403);
        $invitations->accept($identity, (string) $request->input('password', ''), (string) $request->input('password_confirmation', ''));
        $sessions->begin($request, $identity->refresh());

        return to_route('account')->with('status', __('account.invitation_accepted'));
    }
}
