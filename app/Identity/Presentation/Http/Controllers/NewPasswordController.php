<?php

namespace App\Identity\Presentation\Http\Controllers;

use App\Identity\Application\Command\ResetPasswordHandler;
use App\Identity\Presentation\Http\AuthArea;
use App\Identity\Presentation\Http\Requests\ResetPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class NewPasswordController
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => is_string($request->query('email')) ? $request->query('email') : '']);
    }

    public function store(ResetPasswordRequest $request, ResetPasswordHandler $reset): RedirectResponse
    {
        if (! $reset->handle($request->toData())) {
            throw ValidationException::withMessages(['email' => __('auth.reset_invalid')]);
        }

        return to_route(AuthArea::route($request, 'login'))->with('status', __('auth.reset_done'));
    }
}
