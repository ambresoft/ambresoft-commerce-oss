<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Responses send `Referrer-Policy: no-referrer`, so `back()` (a form that failed validation) returns to the page the
 * session remembers. Laravel only remembers full page loads, while staff move between admin pages with Inertia visits,
 * and it also remembers pictures and background fetches. This keeps the remembered page the one on screen: Inertia
 * page visits count, prefetches (link hover) and subresources (images, scripts, JSON fetches) do not.
 */
final class RememberCurrentPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $inertia = $request->headers->has('X-Inertia');
        $destination = $request->headers->get('Sec-Fetch-Dest');
        // Marked as XHR, which the session never remembers as a page.
        if (! $inertia && $destination !== null && $destination !== 'document') {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }
        $response = $next($request);
        if ($inertia && $request->isMethod('GET') && ! $request->prefetch() && $response->isSuccessful() && $request->hasSession()) {
            $request->session()->setPreviousUrl($request->fullUrl());
        }

        return $response;
    }
}
