<?php

namespace App\Storefront\Domain\Service;

/** Page-path patterns, one per line: "/checkout" matches that page, "/checkout/*" every page below it. */
final class PathRules
{
    public const LINE = '/^\/[a-zA-Z0-9_\-\/]*\*?$/D';

    /** @return list<string> */
    public function lines(string $patterns): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/\R/', $patterns) ?: [])));
    }

    public function valid(string $patterns): bool
    {
        foreach ($this->lines($patterns) as $line) {
            if (preg_match(self::LINE, $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    public function matches(string $path, string $patterns): bool
    {
        $path = $this->normalize($path);
        foreach ($this->lines($patterns) as $line) {
            if (str_ends_with($line, '*')) {
                $prefix = $this->normalize(substr($line, 0, -1));
                if ($path === $prefix || str_starts_with($path, $prefix === '/' ? '/' : $prefix.'/')) {
                    return true;
                }
            } elseif ($path === $this->normalize($line)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $path): string
    {
        return '/'.trim($path, '/');
    }
}
