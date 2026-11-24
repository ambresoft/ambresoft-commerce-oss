<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PrivateBrowserResponse
{
    public const HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Referrer-Policy' => 'no-referrer',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = self::HEADERS;
        // Versioned public files (the store logo) opt in to browser caching with "immutable"; everything else stays uncacheable.
        if (str_contains((string) $response->headers->get('Cache-Control'), 'immutable')) {
            unset($headers['Cache-Control']);
        }
        // Only inert, MIME-validated public PDFs may be framed by this store's document modal.
        if ($request->routeIs('storefront.product-document', 'storefront.brand-document')
            && $response->isSuccessful() && $response->headers->get('Content-Type') === 'application/pdf') {
            $headers['X-Frame-Options'] = 'SAMEORIGIN';
        }
        $response->headers->add($headers);

        return $response;
    }
}
