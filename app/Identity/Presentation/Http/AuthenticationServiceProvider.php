<?php

namespace App\Identity\Presentation\Http;

use App\Customer\Infrastructure\CustomerAccounts;
use App\Identity\Domain\Model\PasswordPolicy;
use App\Identity\Infrastructure\Persistence\UserRecord as User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AuthenticationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(PasswordPolicy::STAFF_MIN)->max(PasswordPolicy::MAX));

        // Canonical URLs prevent an untrusted Host header from entering security emails.
        // Links point at the identity's own pages: customer pages for customer identities without staff access.
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim(config('app.url'), '/').route(self::area($user).'password.reset', [
            'token' => $token, 'email' => $user->email,
        ], false));

        // Verification notifications need absolute, canonical links while signatures
        // are path-based. The signed middleware below uses the same relative mode.
        VerifyEmail::createUrlUsing(fn (User $user): string => rtrim(config('app.url'), '/').
            URL::temporarySignedRoute(self::area($user).'verification.verify', now()->addMinutes(60), [
                'id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification()),
            ], absolute: false));

        foreach (['login' => 5, 'register' => 3, 'password-email' => 3, 'password-reset' => 5] as $name => $attempts) {
            RateLimiter::for($name, function (Request $request) use ($attempts): array {
                $email = $request->input('email');
                $identity = hash('sha256', (is_string($email) ? mb_strtolower(trim($email)) : '').'|'.$request->ip());

                return [
                    Limit::perMinute(20)->by('ip:'.$request->ip()),
                    Limit::perMinute($attempts)->by('identity:'.$identity),
                ];
            });
        }

        RateLimiter::for('verification', fn (Request $request) => Limit::perMinute(6)->by((string) $request->user()?->getAuthIdentifier()));
        RateLimiter::for('staff-passkeys', fn (Request $request) => Limit::perMinute(5)->by($request->route()->getName().'|'.$request->user()->getAuthIdentifier()));
    }

    /** Route-name prefix of the pages an identity uses. */
    private static function area(User $user): string
    {
        return app(CustomerAccounts::class)->usesCustomerArea($user) ? '' : 'admin.';
    }
}
