<?php

namespace App\Identity\Presentation\Http;

use Illuminate\Http\Request;

/**
 * Which sign-in entry a request belongs to. Staff use the `/admin/...` pages and `admin.*` route names; customers use
 * the public pages. Both sign in the same `users` identity (A21) on the same session guard.
 */
final class AuthArea
{
    /** Link keys shared with the auth pages, resolved to the current area's routes. */
    public const LINKS = ['login', 'login.store', 'register', 'register.store', 'logout', 'password.request', 'password.email', 'password.update', 'verification.send'];

    public static function admin(Request $request): bool
    {
        return $request->is('admin', 'admin/*');
    }

    public static function route(Request $request, string $name): string
    {
        return self::admin($request) ? 'admin.'.$name : $name;
    }
}
