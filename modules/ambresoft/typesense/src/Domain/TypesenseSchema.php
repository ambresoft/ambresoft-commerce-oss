<?php

namespace Ambresoft\Typesense\Domain;

/**
 * The product collection layout. Texts get one field per active language (Typesense tokenizes some languages, such as
 * Greek, with their own locale: accents and final sigma are normalized). Amounts and feature numbers are integers in
 * ten-thousandths, because Typesense floats are 32-bit and would blur filter boundaries and statistics.
 *
 * Only what search needs is searchable (names, codes, brand, category path, descriptions with option labels) or
 * facetable (the filter groups); the rest is filter or sort data. Feature fields are named by the feature's numeric ID
 * (`opt_12`, `num_7`), never by merchant text, so names cannot collide or break the schema.
 */
final class TypesenseSchema
{
    /** Bump when the layout changes: indexes of an older layout then report that a rebuild is needed. */
    public const VERSION = 2;

    public const SCALE = '10000';

    /** Languages Typesense tokenizes with a locale of their own; others use the default (Latin script) tokenizer. */
    private const LOCALES = ['el', 'ru', 'uk', 'be', 'sr', 'ja', 'zh', 'ko', 'th'];

    /**
     * @param  list<string>  $languages
     * @return list<array<string, mixed>>
     */
    public static function fields(array $languages): array
    {
        $fields = [['name' => 'product_id', 'type' => 'int64'], ['name' => 'codes', 'type' => 'string[]', 'optional' => true]];
        foreach ($languages as $code) {
            foreach (['name' => 'string', 'brand' => 'string', 'category' => 'string[]', 'text' => 'string'] as $prefix => $type) {
                $fields[] = ['name' => $prefix.'_'.$code, 'type' => $type, 'optional' => true, 'locale' => self::locale($code)];
            }
        }

        return [...$fields,
            ['name' => 'listing_key', 'type' => 'string', 'facet' => true],
            ['name' => 'category_id', 'type' => 'int64', 'facet' => true],
            ['name' => 'brand_id', 'type' => 'int64', 'facet' => true, 'optional' => true],
            ['name' => 'price', 'type' => 'int64', 'facet' => true],
            ['name' => 'in_stock', 'type' => 'bool', 'facet' => true],
            ['name' => 'status_id', 'type' => 'int64', 'facet' => true, 'optional' => true],
            ['name' => 'rating', 'type' => 'int32', 'facet' => true, 'optional' => true],
            ['name' => 'rating_tenths', 'type' => 'int32'],
            ['name' => 'public', 'type' => 'bool'],
            ['name' => 'groups', 'type' => 'int64[]', 'optional' => true],
            ['name' => 'flags', 'type' => 'int64[]', 'facet' => true, 'optional' => true],
            ['name' => 'opt_.*', 'type' => 'int64[]', 'facet' => true, 'optional' => true],
            ['name' => 'num_.*', 'type' => 'int64', 'facet' => true, 'optional' => true],
            ['name' => 'created_at', 'type' => 'int64'],
        ];
    }

    /** Whether the text contains letters of the script the language's own tokenizer is for. */
    public static function writtenIn(string $language, string $text): bool
    {
        $script = match ($language) {
            'el' => '\\p{Greek}', 'ru', 'uk', 'be', 'sr' => '\\p{Cyrillic}', 'ja' => '\\p{Hiragana}\\p{Katakana}\\p{Han}', 'zh' => '\\p{Han}', 'ko' => '\\p{Hangul}', 'th' => '\\p{Thai}', default => null,
        };

        return $script !== null && preg_match('/['.$script.']/u', $text) === 1;
    }

    public static function locale(string $language): string
    {
        return in_array($language, self::LOCALES, true) ? $language : '';
    }

    /** @param list<string> $languages */
    public static function fingerprint(array $languages): string
    {
        return substr(hash('sha256', json_encode([self::VERSION, self::fields($languages)], JSON_THROW_ON_ERROR)), 0, 8);
    }

    /** @param list<string> $languages */
    public static function collection(string $alias, array $languages, string $suffix): string
    {
        return $alias.'_'.self::fingerprint($languages).'_'.$suffix;
    }

    /** @param list<string> $languages */
    public static function current(string $alias, string $collection, array $languages): bool
    {
        return str_starts_with($collection, $alias.'_'.self::fingerprint($languages).'_');
    }

    /**
     * A decimal as ten-thousandths. Filters round their bounds outwards so a value equal to a bound always matches.
     *
     * @param  'round'|'floor'|'ceil'  $mode
     */
    public static function scaled(string $decimal, string $mode = 'round'): int
    {
        $value = bcmul($decimal, self::SCALE, 6);
        $whole = bcadd($value, '0', 0);
        $fraction = bccomp($value, $whole, 6) !== 0;
        $negative = bccomp($value, '0', 6) < 0;

        return match (true) {
            ! $fraction => (int) $whole,
            $mode === 'floor' => (int) $whole - ($negative ? 1 : 0),
            $mode === 'ceil' => (int) $whole + ($negative ? 0 : 1),
            default => (int) bcadd($value, $negative ? '-0.5' : '0.5', 0),
        };
    }

    public static function decimal(int|float|string $scaled): string
    {
        $value = bcdiv(is_float($scaled) ? number_format($scaled, 0, '.', '') : (string) $scaled, self::SCALE, 4);

        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}
