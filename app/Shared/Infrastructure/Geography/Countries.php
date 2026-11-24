<?php

namespace App\Shared\Infrastructure\Geography;

use Collator;
use Locale;

/**
 * ISO 3166-1 alpha-2 countries from the code allowlist in config/geography.php, named by the platform's ICU data
 * in the requested language. Shared so tax rate areas now and shipping zones later use one catalog.
 */
final class Countries
{
    /** @return list<string> */
    public function codes(): array
    {
        return array_values(config('geography.countries'));
    }

    public function valid(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    public function name(string $code, ?string $locale = null): string
    {
        $name = Locale::getDisplayRegion('-'.$code, $locale ?? app()->getLocale());

        return $name === '' || $name === $code ? $code : $name;
    }

    /** @return list<array{code: string, name: string}> Sorted by localized name. */
    public function options(?string $locale = null): array
    {
        $options = array_map(fn (string $code): array => ['code' => $code, 'name' => $this->name($code, $locale)], $this->codes());
        $collator = new Collator($locale ?? app()->getLocale());
        usort($options, fn (array $a, array $b): int => $collator->compare($a['name'], $b['name']) ?: strcmp($a['code'], $b['code']));

        return $options;
    }
}
