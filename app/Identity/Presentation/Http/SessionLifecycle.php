<?php

namespace App\Identity\Presentation\Http;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Identity\Infrastructure\Security\AccountSecurity;
use App\Identity\Presentation\Http\Requests\LoginRequest;
use App\Storefront\Infrastructure\RecentlyViewedProducts;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Session start and end shared by the staff and customer sign-in pages: one hardened behaviour for both. */
final class SessionLifecycle
{
    /** @param  Closure(Authenticatable): bool|null  $refuse  Identities this entry point does not serve; they get the same failure as a wrong password. */
    public function attempt(LoginRequest $request, ?Closure $refuse = null): void
    {
        app(AccountSecurity::class)->device($request);
        if (! Auth::attempt([...$request->safe()->only(['email', 'password']), 'is_active' => true], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        if ($refuse !== null && $refuse($request->user())) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        $this->started($request, $request->user());
    }

    /** Signs in an identity that was just created (registration). */
    public function begin(Request $request, Authenticatable $identity): void
    {
        Auth::login($identity);
        $this->started($request, $identity);
    }

    public function end(Request $request): void
    {
        $actor = (string) $request->user()->getAuthIdentifier();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();
        app(AuditLogger::class)->record(new AuditEvent('auth.logout', 'user', $actor));
    }

    private function started(Request $request, Authenticatable $identity): void
    {
        if ($identity instanceof UserRecord) {
            $security = app(AccountSecurity::class);
            if ($request->routeIs('admin.register.store')) {
                $security->record($request, $identity, 'registration');
            }
            $security->record($request, $identity, 'login');
        }
        $request->session()->regenerate();
        app(RecentlyViewedProducts::class)->merge($request);
        $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($identity->getAuthPassword()));
        $request->session()->forget('url.intended');
        app(AuditLogger::class)->record(new AuditEvent('auth.session_created', 'user', (string) $identity->getAuthIdentifier()));
    }
}
