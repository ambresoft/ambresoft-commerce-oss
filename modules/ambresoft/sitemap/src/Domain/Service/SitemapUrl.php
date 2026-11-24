<?php

namespace Ambresoft\Sitemap\Domain\Service;

final class SitemapUrl
{
    public static function valid(string $url, string $origin): bool
    {
        if (strlen($url) > 2048 || ! mb_check_encoding($url, 'UTF-8') || preg_match('/[\x00-\x20\x7f<>"\\\\]/u', $url) || ! str_starts_with($url, $origin.'/')) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $path = $parts['path'] ?? '/';
        $decoded = rawurldecode($path);
        if (! mb_check_encoding($decoded, 'UTF-8') || preg_match('/[\x00-\x20\x7f]/u', $decoded) || preg_match('/%(?![0-9a-f]{2})|%(2f|5c|00)/i', $path) || str_contains($path, '//') || str_contains($decoded, '\\') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $decoded)) {
            return false;
        }

        return ! preg_match('~^/(admin|api|account|auth|login|register|logout|cart|checkout|search|pay|payments|orders|order-status|newsletter|email|wishlist|wishlists|comparison|storefront|locale|forgot-password|reset-password|call-requests|age-verification|up|build|storage|vendor)(/|$)~i', $decoded);
    }
}
