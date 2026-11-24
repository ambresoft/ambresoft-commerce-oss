<?php

namespace App\Marketing\Domain\Service;

/**
 * The `{{variable}}` placeholders merchants use in newsletter and autoresponder texts (like the order e-mail templates). Values are
 * supplied already HTML-safe by the caller; unknown or unavailable placeholders are removed, never left visible.
 */
final class NewsletterPlaceholders
{
    public const NAMES = ['unsubscribe_link', 'activation_link', 'subscriber_email', 'company_name', 'company_address', 'company_phone'];

    private const PATTERN = '/\{\{\s*([A-Za-z0-9_.]{1,60})\s*\}\}/';

    /** @param array<string, string> $values Variable name => HTML-safe value */
    public static function render(string $html, array $values): string
    {
        return preg_replace_callback(self::PATTERN, fn (array $m): string => $values[$m[1]] ?? '', $html) ?? $html;
    }

    /** @return list<string> Variables in the text that are not allowed, once each. */
    public static function unknown(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $found);

        return array_values(array_unique(array_diff($found[1], self::NAMES)));
    }

    /** @return list<string> One subject per line, trimmed, without empty lines. */
    public static function subjects(string $main, string $random): array
    {
        $lines = array_values(array_filter(array_map(fn (string $l): string => trim($l), preg_split('/\R/u', $random) ?: [])));

        return $lines === [] ? [$main] : [$main, ...$lines];
    }

    /** Plain-text alternative of an HTML body: paragraphs and breaks become lines, links keep their URL. */
    public static function text(string $html): string
    {
        $html = preg_replace('/<a\s[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $html) ?? $html;
        $html = preg_replace('/<(br|\/p|\/div|\/tr|\/h[1-6]|\/li)[^>]*>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $text) ?? $text) ?? $text);
    }
}
