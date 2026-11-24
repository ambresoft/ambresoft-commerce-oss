<?php

namespace App\Customer\Infrastructure;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Website\Infrastructure\SocialLoginProviders;
use App\Website\Infrastructure\SocialLoginQueries;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Customer sign-in with a configured provider (Website → Social login), Google only. The authorization-code flow
 * uses a one-time `state` and PKCE bound to the session. Only a provider-verified e-mail is accepted, only customer
 * identities can sign in this way (staff and administration never can), and a customer who does not exist yet is
 * created already verified.
 */
final class SocialSignIn
{
    private const AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const SESSION = 'social_sign_in';

    public function __construct(private SocialLoginQueries $queries, private SocialLoginProviders $catalog, private CustomerAccounts $accounts) {}

    /** @return list<array{provider: string, name: string, url: string}> */
    public function buttons(): array
    {
        return array_map(fn (array $button): array => [...$button, 'url' => route('social.redirect', ['provider' => $button['provider']], false)], $this->queries->buttons());
    }

    public function authorizationUrl(Request $request, string $provider): string
    {
        $credentials = $this->queries->credentials($provider) ?? throw new SocialSignInFailed;
        $state = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put(self::SESSION, ['provider' => $provider, 'state' => $state, 'verifier' => $verifier]);

        return self::AUTHORIZE.'?'.http_build_query([
            'client_id' => $credentials['client_id'], 'redirect_uri' => $this->catalog->callbackUrl($provider), 'response_type' => 'code',
            'scope' => 'openid email profile', 'state' => $state, 'prompt' => 'select_account',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Completes the callback and returns the customer identity to sign in. */
    public function identity(Request $request, string $provider): UserRecord
    {
        $flow = $request->session()->pull(self::SESSION);
        $code = $request->query('code');
        $state = $request->query('state');
        if (! is_array($flow) || ($flow['provider'] ?? null) !== $provider || ! is_string($state) || ! is_string($code) || $code === '' || ! hash_equals($flow['state'], $state)) {
            throw new SocialSignInFailed;
        }
        $credentials = $this->queries->credentials($provider) ?? throw new SocialSignInFailed;

        try {
            $token = Http::asForm()->timeout(10)->post(self::TOKEN, [
                'code' => $code, 'client_id' => $credentials['client_id'], 'client_secret' => $credentials['client_secret'],
                'redirect_uri' => $this->catalog->callbackUrl($provider), 'grant_type' => 'authorization_code', 'code_verifier' => $flow['verifier'],
            ]);
            $access = $token->successful() ? $token->json('access_token') : null;
            $profile = is_string($access) ? Http::withToken($access)->timeout(10)->get(self::USERINFO) : null;
        } catch (ConnectionException) {
            throw new SocialSignInFailed;
        }
        if ($profile === null || ! $profile->successful() || $profile->json('email_verified') !== true || ! is_string($profile->json('email')) || filter_var($profile->json('email'), FILTER_VALIDATE_EMAIL) === false) {
            throw new SocialSignInFailed;
        }
        $email = mb_strtolower(trim($profile->json('email')));

        $user = UserRecord::query()->where('email', $email)->first();
        if ($user === null) {
            [$first, $last] = $this->names($profile->json(), $email);

            return $this->accounts->registerVerified($first, $last, $email);
        }
        if (! $user->is_active || ! $this->accounts->usesCustomerArea($user)) {
            throw new SocialSignInFailed;
        }
        $this->accounts->claimUnverified($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{string, string}
     */
    private function names(array $profile, string $email): array
    {
        $clean = fn (mixed $value): string => is_string($value) ? mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $value) ?? ''), 0, 60) : '';
        $first = $clean($profile['given_name'] ?? null);
        $last = $clean($profile['family_name'] ?? null);

        return [$first !== '' ? $first : $clean(Str::before($email, '@')), $last !== '' ? $last : '-'];
    }
}
