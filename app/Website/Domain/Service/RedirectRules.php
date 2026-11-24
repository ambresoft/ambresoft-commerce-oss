<?php

namespace App\Website\Domain\Service;

/**
 * Framework-free rules for SEO redirects: how paths are normalized (so /Old-Page/, /old-page and /old%2Dpage are one
 * rule), which sources may be redirected at all, and which destinations are acceptable.
 */
final class RedirectRules
{
    public const CODES = [301, 302];

    public const MAX_LENGTH = 2048;

    /** Hops followed when looking for loops or the final destination of a chain. */
    public const MAX_HOPS = 10;

    /**
     * First path segments a redirect may never claim: administration, sign-in, cart, checkout and payments, account
     * pages, storefront endpoints, health checks and files the web server or browsers ask for by name.
     */
    public const RESERVED = ['admin', 'api', 'account', 'auth', 'login', 'logout', 'register', 'forgot-password', 'reset-password', 'email',
        'cart', 'checkout', 'pay', 'payments', 'orders', 'order-status', 'newsletter', 'locale', 'storefront', 'products', 'search', 'wishlist', 'wishlists',
        'comparison', 'call-requests', 'age-verification', 'up', 'build', 'storage', 'vendor', '.well-known', 'robots.txt', 'favicon.ico',
        'sitemap.xml', 'site.webmanifest', 'sanctum', 'livewire', 'horizon', 'telescope'];

    /**
     * A request or source path in its matching form: decoded, lowercase, single slashes, no trailing slash, no query.
     * Null when it is not a usable path (dot segments, backslashes, control characters, encoded slashes, too long).
     */
    public static function path(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || $value[0] !== '/' || str_starts_with($value, '//') || strlen($value) > self::MAX_LENGTH) {
            return null;
        }
        // Encoded separators would let two different requests share one rule.
        if (preg_match('/%(2f|5c|00)/i', $value) === 1) {
            return null;
        }
        $decoded = rawurldecode($value);
        if (! mb_check_encoding($decoded, 'UTF-8') || preg_match('/[\x00-\x20\x7f\\\\?#]/u', $decoded) === 1) {
            return null;
        }
        // One spelling per letter: "ά" typed as one character or as "α" plus an accent is the same address.
        $decoded = class_exists(\Normalizer::class) ? (string) \Normalizer::normalize($decoded, \Normalizer::FORM_C) : $decoded;
        $path = '/'.implode('/', array_filter(explode('/', mb_strtolower($decoded)), fn (string $segment): bool => $segment !== ''));
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    /** A path no redirect may claim: the home page or a reserved first segment. */
    public static function reserved(string $path): bool
    {
        return $path === '/' || preg_match('~^/sitemap-[0-9a-f-]{36}-[0-9]+\.xml$~D', $path) === 1 || in_array(explode('/', $path)[1] ?? '', self::RESERVED, true);
    }

    /**
     * The stored form of a destination: an internal path (with optional query and fragment, its path normalized like
     * a source but keeping its case) or an absolute http(s) URL to another site. Null when unsafe or malformed.
     *
     * @param  string  $ownHost  This store's host: absolute URLs to it are stored as internal paths.
     */
    public static function destination(string $value, string $ownHost): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1) {
            return null;
        }
        if ($value[0] === '/') {
            return str_starts_with($value, '//') ? null : self::internal($value);
        }
        $parts = parse_url($value);
        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || ! self::host($parts['host'])) {
            return null;
        }
        if (strcasecmp($parts['host'], $ownHost) === 0) {
            return self::internal(($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : ''));
        }

        return $value;
    }

    /**
     * A destination as an HTTP Location value: characters outside ASCII are percent-encoded and an international
     * host name is written in its ASCII (punycode) form.
     */
    public static function location(string $destination): string
    {
        $encode = fn (string $value): string => (string) preg_replace_callback('/[^\x21-\x7e]+/', fn (array $m): string => rawurlencode($m[0]), $value);
        if ($destination === '' || $destination[0] === '/') {
            return $encode($destination);
        }
        $host = (string) parse_url($destination, PHP_URL_HOST);
        $ascii = function_exists('idn_to_ascii') && preg_match('/[^\x21-\x7e]/', $host) === 1 ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : $host;
        $at = strpos($destination, $host);

        return $encode($at === false || $ascii === false ? $destination : substr_replace($destination, $ascii, $at, strlen($host)));
    }

    /** The matching path of an internal destination, or null for an external one. */
    public static function internalPath(string $destination): ?string
    {
        if ($destination === '' || $destination[0] !== '/') {
            return null;
        }

        return self::path(explode('#', explode('?', $destination, 2)[0], 2)[0]);
    }

    private static function internal(string $value): ?string
    {
        [$rest, $fragment] = array_pad(explode('#', $value, 2), 2, null);
        [$path, $query] = array_pad(explode('?', $rest, 2), 2, null);
        if (self::path($path) === null) {
            return null;
        }
        $clean = '/'.implode('/', array_filter(explode('/', $path), fn (string $segment): bool => $segment !== ''));

        return $clean.($query !== null && $query !== '' ? '?'.$query : '').($fragment !== null && $fragment !== '' ? '#'.$fragment : '');
    }

    /** A public host name with at least one dot (IDN allowed), not an address literal or a bare name. */
    private static function host(string $host): bool
    {
        return mb_strlen($host) <= 253 && ! filter_var($host, FILTER_VALIDATE_IP) && ! str_starts_with($host, '[')
            && preg_match('/^(?=.{1,253}$)([\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?\.)+[\p{L}]{2,63}$/u', $host) === 1;
    }
}
