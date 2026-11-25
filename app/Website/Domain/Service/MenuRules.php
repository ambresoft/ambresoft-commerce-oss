<?php

namespace App\Website\Domain\Service;

final class MenuRules
{
    public static function safeUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }
        if (str_starts_with($url, '#')) {
            return strlen($url) > 1;
        }
        // Contact links: an e-mail address or a phone number.
        if (preg_match('/^mailto:[^\s@<>"]+@[^\s@<>"]+\.[A-Za-z]{2,}$/D', $url) === 1 || preg_match('/^tel:\+?[0-9()\-.]{5,30}$/D', $url) === 1) {
            return true;
        }
        $parts = parse_url($url);

        return $parts !== false && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass'])
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
