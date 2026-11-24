<?php

namespace App\Identity\Presentation\Http\Controllers;

use App\Authorization\Infrastructure\AccessChecker;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Verified identity without administration entry: no staff grant yet, or a granted identity whose password is below the staff minimum. */
final class AccessPendingController
{
    public function __invoke(Request $request, AccessChecker $access): Response
    {
        $user = $request->user();

        return Inertia::render('Auth/AccessPending', ['passwordTooShort' => $user->staff_password === false && $access->allows($user, 'admin.access')]);
    }
}
