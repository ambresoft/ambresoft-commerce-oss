<?php

namespace App\Storefront\Presentation\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;

/**
 * A visitor confirms their age at an age-restricted category. The highest age confirmed is remembered in an encrypted
 * cookie for a year, so categories with that limit or a lower one open directly afterwards.
 */
final class AgeVerificationController
{
    public const COOKIE = 'age_verified';

    public static function confirmed(Request $request): int
    {
        $age = $request->cookie(self::COOKIE);

        return is_string($age) && ctype_digit($age) ? (int) $age : 0;
    }

    public function __invoke(Request $request): Response
    {
        $age = (int) $request->validate(['age' => ['required', 'integer', 'between:1,150']])['age'];
        Cookie::queue(Cookie::make(self::COOKIE, (string) max($age, self::confirmed($request)), 365 * 24 * 60, httpOnly: true, sameSite: 'lax'));

        return response()->noContent();
    }
}
