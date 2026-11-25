<?php

namespace App\Identity\Presentation\Http\Controllers;

use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Staff e-mail verification under /admin. */
final class EmailVerificationController
{
    public function notice(Request $request): Response|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail() ? to_route(Gate::allows('access-admin') ? 'admin.dashboard' : 'admin.access.pending') : Inertia::render('Auth/VerifyEmail');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return to_route(Gate::allows('access-admin') ? 'admin.dashboard' : 'admin.access.pending')->with('status', __('auth.verified'));
    }

    public function send(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', __('auth.verification_sent'));
    }
}
