<?php

namespace App\Order\Infrastructure;

use Illuminate\Http\Request;

/**
 * The customer's IP for an order, from the trusted request context only. Request::ip() honours the framework's
 * trusted-proxy configuration (none is trusted by default), so a forwarded-for header from an untrusted client is
 * ignored. A value submitted as a form field is never read.
 */
final class OrderOrigin
{
    public static function fromRequest(Request $request): ?string
    {
        return self::valid((string) $request->ip());
    }

    /** A canonical IPv4 or IPv6 address, or null. */
    public static function valid(string $ip): ?string
    {
        $ip = trim($ip);

        return filter_var($ip, FILTER_VALIDATE_IP) === false ? null : (string) inet_ntop((string) inet_pton($ip));
    }
}
