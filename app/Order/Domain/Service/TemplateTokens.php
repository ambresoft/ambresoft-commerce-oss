<?php

namespace App\Order\Domain\Service;

/**
 * Allow-listed `{{variable}}` placeholders. Templates are data: nothing in them is evaluated. Values are inserted as
 * given, so callers pass text already escaped for the target (HTML body or plain-text subject).
 */
final class TemplateTokens
{
    private const PATTERN = '/\{\{\s*([A-Za-z0-9_.]{1,60})\s*\}\}/';

    /**
     * @param  list<string>  $allowed
     * @return list<string> Placeholder names that are not in the allow-list, once each.
     */
    public function unknown(string $template, array $allowed): array
    {
        preg_match_all(self::PATTERN, $template, $matches);

        return array_values(array_unique(array_diff($matches[1], $allowed)));
    }

    /** @param array<string, string> $values Unknown placeholders render as nothing. */
    public function render(string $template, array $values): string
    {
        return (string) preg_replace_callback(self::PATTERN, fn (array $match): string => $values[$match[1]] ?? '', $template);
    }
}
