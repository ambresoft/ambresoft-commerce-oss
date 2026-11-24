<?php

namespace App\Identity\Presentation\Http\Controllers;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Presentation\Http\Requests\EmailRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

final class PasswordResetLinkController
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function store(EmailRequest $request): RedirectResponse
    {
        $status = Password::sendResetLink($request->safe()->only(['email']));
        app(AuditLogger::class)->record(new AuditEvent('auth.password_reset_requested', 'user', status: $status === Password::ResetLinkSent ? 'success' : 'failure', metadata: ['attempted_email' => $request->validated('email')]));

        return back()->with('status', __('auth.reset_sent'));
    }
}
