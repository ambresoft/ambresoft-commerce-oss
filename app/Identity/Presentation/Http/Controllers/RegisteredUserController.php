<?php

namespace App\Identity\Presentation\Http\Controllers;

use App\Identity\Infrastructure\Auth\StaffInvitations;
use App\Identity\Infrastructure\Auth\StaffPasskeys;
use App\Identity\Presentation\Http\SessionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Staff registration always requires a private invitation. */
final class RegisteredUserController
{
    public function create(Request $request, StaffInvitations $invitations): Response
    {
        $user = $invitations->invited($request);

        return Inertia::render('Auth/Register', ['invitation' => [
            'name' => $user->name, 'email' => $user->email, 'submitUrl' => $request->getRequestUri(),
        ]]);
    }

    public function store(Request $request, StaffInvitations $invitations, StaffPasskeys $passkeys, SessionLifecycle $sessions): RedirectResponse|JsonResponse
    {
        $invited = $invitations->invited($request);
        if ($request->input('operation') === 'passkey_options') {
            return response()->json($passkeys->creationOptions($request, $invited, 'invitation'));
        }
        if ($request->input('operation') === 'passkey_finish') {
            $user = $invitations->accept($request, null, fn ($user) => $passkeys->enroll($request, $user, 'invitation'));
            $sessions->begin($request, $user);

            return response()->json(['redirect' => route(Gate::allows('access-admin') ? 'admin.dashboard' : 'admin.access.pending', [], false)]);
        }
        $data = $request->validate(['password' => ['required', 'string', 'confirmed', Password::defaults()]]);
        $user = $invitations->accept($request, $data['password']);
        $sessions->begin($request, $user);

        return to_route(Gate::allows('access-admin') ? 'admin.dashboard' : 'admin.access.pending');
    }
}
