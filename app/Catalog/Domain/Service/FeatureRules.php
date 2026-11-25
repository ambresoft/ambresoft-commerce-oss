<?php

namespace App\Catalog\Domain\Service;

final class FeatureRules
{
    public const TYPES = ['text', 'single', 'number', 'boolean', 'multiple'];

    public const PURPOSES = ['standard', 'landing_pages', 'variations'];

    /** Variation selector presentation; Text buttons is the default. */
    public const VARIATION_STYLES = ['text', 'color', 'image', 'dropdown'];

    // Landing-page entities (brand, author) and variation axes need one value per product: Single choice only.
    public static function supportsPurpose(string $type, string $purpose): bool
    {
        return $purpose === 'standard' || (in_array($purpose, ['landing_pages', 'variations'], true) && $type === 'single');
    }

    /** Canonical #RRGGBB (uppercase); #RGB expands. Anything else, including CSS keywords, is invalid (null). */
    public static function swatchColor(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^#([0-9a-fA-F]{3})$/D', $value, $short) === 1) {
            $value = '#'.implode('', array_map(fn (string $c): string => $c.$c, str_split($short[1])));
        }

        return preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1 ? strtoupper($value) : null;
    }

    public static function validNumber(string $value): bool
    {
        return preg_match('/^-?\d{1,14}(?:\.\d{1,6})?$/D', $value) === 1;
    }

    /** @param array<string, mixed> $value */
    public static function filled(string $type, array $value, string $defaultLocale): bool
    {
        return match ($type) {
            'text' => trim($value['texts'][$defaultLocale] ?? '') !== '',
            'number' => isset($value['number']) && self::validNumber((string) $value['number']),
            'boolean' => isset($value['boolean']) && in_array($value['boolean'], [true, false, '1', '0', 1, 0], true),
            'single', 'multiple' => ($value['options'] ?? []) !== [],
            default => false,
        };
    }
}
