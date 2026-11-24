<?php

namespace App\Customer\Presentation\Http\Controllers;

use App\Customer\Infrastructure\CustomerAccounts;
use App\Customer\Infrastructure\SocialSignIn;
use App\Customer\Presentation\Http\Requests\CustomerRegisterRequest;
use App\Identity\Domain\Exception\EmailAlreadyRegistered;
use App\Identity\Presentation\Http\SessionLifecycle;
use App\Storefront\Presentation\Http\CheckoutController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/** Customer registration at /register: a sign-in identity plus pending profile names until the e-mail is verified. */
final class CustomerRegistrationController
{
    public function create(Request $request, SocialSignIn $social): Response
    {
        CheckoutController::remember($request);

        return Inertia::render('Customer/Register', ['socialProviders' => $social->buttons()]);
    }

    public function store(CustomerRegisterRequest $request, CustomerAccounts $accounts, SessionLifecycle $sessions): RedirectResponse
    {
        try {
            $id = $accounts->register($request->validated('first_name'), $request->validated('last_name'), $request->validated('email'), $request->validated('password'));
        } catch (EmailAlreadyRegistered) {
            // A concurrent registration won the database uniqueness guard.
            throw ValidationException::withMessages(['email' => __('auth.register_failed')]);
        }
        $identity = Auth::guard('web')->getProvider()->retrieveById($id) ?? throw new LogicException('Registered identity is unavailable to the session provider.');
        $sessions->begin($request, $identity);

        return to_route('verification.notice');
    }
}
