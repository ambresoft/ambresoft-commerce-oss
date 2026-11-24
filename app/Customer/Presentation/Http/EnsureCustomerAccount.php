<?php

namespace App\Customer\Presentation\Http;

use App\Customer\Infrastructure\CustomerAccounts;
use App\Identity\Presentation\Http\SessionLifecycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer pages need an active linked profile on every request: a profile disabled by staff ends the session, and a
 * verified identity without one is asked to complete it.
 */
final class EnsureCustomerAccount
{
    public function __construct(private CustomerAccounts $accounts, private SessionLifecycle $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $profile = $this->accounts->profile($user);
        if ($profile === null && $this->accounts->activate($user) !== null) {
            $profile = $this->accounts->profile($user);
        }
        if ($profile === null) {
            return to_route('account.profile');
        }
        if ($profile->status !== 'active') {
            $this->sessions->end($request);

            return to_route('login')->with('status', CustomerAccounts::blockedMessage($profile));
        }

        return $next($request);
    }
}
