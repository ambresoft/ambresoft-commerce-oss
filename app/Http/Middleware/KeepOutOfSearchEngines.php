<?php

namespace App\Http\Middleware;

use App\Storefront\Infrastructure\SearchEngineVisibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The administration area and the account/sign-in pages are private: they must never be crawled, indexed,
 * cached or followed. Three layers, because robots.txt alone only asks politely and cannot unindex a URL:
 *
 *  1. every response carries `X-Robots-Tag` (also covers JSON, downloads and redirects);
 *  2. the HTML shell repeats it as a robots meta tag (see {@see SearchEngineVisibility::applies()});
 *  3. requests under /admin from self-declared crawlers are refused before authentication runs.
 *
 * Crawler detection by user agent is only a courtesy filter; real access control is authentication and
 * authorization. Closed stores and the merchant's development-indexing setting also protect storefront responses.
 */
final class KeepOutOfSearchEngines
{
    public const DIRECTIVES = 'noindex, nofollow, noarchive, nosnippet, noimageindex';

    private const CRAWLERS = '/(bot|crawl|spider|slurp|scrap|mediapartners|facebookexternalhit|bingpreview|ahrefs|semrush|mj12|dotbot|petalbot|bytespider|gptbot|claude-web|ccbot|perplexity|yandex|baidu|duckduck|applebot|exabot|sogou|archive\.org|wget|python-requests|libwww|go-http-client|httpclient|scrapy|curl\/)/i';

    public function handle(Request $request, Closure $next): Response
    {
        if (SearchEngineVisibility::applies($request) && $request->is('admin', 'admin/*') && $this->crawler($request)) {
            return response('Forbidden', 403, ['X-Robots-Tag' => self::DIRECTIVES, 'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
        }
        $response = $next($request);
        if (SearchEngineVisibility::applies($request) && ($request->is('admin', 'admin/*') || ! $response->headers->has('X-Robots-Tag'))) {
            $response->headers->set('X-Robots-Tag', self::DIRECTIVES);
        }

        if (SearchEngineVisibility::storefrontIndexingDisabled($request)) {
            $response->headers->set('X-Robots-Tag', self::DIRECTIVES);
        }

        return $response;
    }

    private function crawler(Request $request): bool
    {
        $agent = (string) $request->userAgent();

        // A missing user agent is not a browser either.
        return $agent === '' || preg_match(self::CRAWLERS, $agent) === 1;
    }
}
