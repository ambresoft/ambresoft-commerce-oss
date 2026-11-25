<?php

namespace App\Identity\Presentation\Http\Controllers;

use App\Customer\Infrastructure\CustomerAccounts;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Identity\Presentation\Http\Requests\LoginRequest;
use App\Identity\Presentation\Http\SessionLifecycle;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Staff sign-in at /admin/login. */
final class SessionController
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request, SessionLifecycle $sessions, CustomerAccounts $customers): RedirectResponse
    {
        // A public customer registration never creates an administration sign-in identity.
        $sessions->attempt($request, fn (Authenticatable $user): bool => ! $user instanceof UserRecord || ! $user->roles()->exists() || $customers->usesCustomerArea($user));
        if (! $request->user()->hasVerifiedEmail()) {
            return to_route('admin.verification.notice');
        }

        return to_route(Gate::allows('access-admin') ? 'admin.dashboard' : 'admin.access.pending');
    }

    public function destroy(Request $request, SessionLifecycle $sessions): RedirectResponse
    {
        $sessions->end($request);

        return to_route('admin.login');
    }
}
