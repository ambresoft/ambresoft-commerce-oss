<?php

namespace App\Website\Presentation\Http;

use App\Website\Domain\Service\RedirectRules;
use App\Website\Infrastructure\RedirectMap;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website → SEO Rules on storefront requests. Runs before routing, so an old address is redirected whether it would
 * still show a page or return 404. Only GET and HEAD, never the home page or a reserved path (administration, sign-in,
 * cart, checkout, payments, account, storefront endpoints, files), and only to stored destinations, never to anything
 * taken from the request. The visitor's query string is kept; the destination's own parameters win.
 */
final class ApplyRedirects
{
    public function __construct(private RedirectMap $redirects) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }
        $path = RedirectRules::path($request->getPathInfo());
        if ($path === null || RedirectRules::reserved($path) || ($found = $this->redirects->resolve($path)) === null) {
            return $next($request);
        }
        [$destination, $status] = $found;
        // A rule (or chain) that ends where it started does nothing rather than loop.
        if (RedirectRules::internalPath($destination) === $path) {
            return $next($request);
        }

        return new RedirectResponse($this->target($request, RedirectRules::location($destination)), $status, ['Cache-Control' => 'no-cache, private']);
    }

    private function target(Request $request, string $destination): string
    {
        [$rest, $fragment] = array_pad(explode('#', $destination, 2), 2, null);
        [$base, $query] = array_pad(explode('?', $rest, 2), 2, null);
        parse_str((string) $query, $own);
        $merged = http_build_query([...$request->query(), ...$own], '', '&', PHP_QUERY_RFC3986);
        $url = ($destination[0] === '/' ? $request->getSchemeAndHttpHost() : '').$base;

        return $url.($merged !== '' ? '?'.$merged : '').($fragment !== null ? '#'.$fragment : '');
    }
}
