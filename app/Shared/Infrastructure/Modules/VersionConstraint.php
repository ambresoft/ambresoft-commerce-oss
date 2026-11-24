<?php

namespace App\Shared\Infrastructure\Modules;

/**
 * Checks a version against a Composer-style constraint. This is a matcher for one installed version, not a dependency
 * solver: `^1.2`, `~1.2`, `1.2.*`, `>=1.0 <2.0` (space or comma for AND), `||` for OR, `*` and exact versions.
 */
final class VersionConstraint
{
    public const VERSION = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/D';

    private const TERM = '/^(\^|~|>=|<=|>|<|==|=|!=)?\s*v?((?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*|\*)){0,2}(?:-[0-9A-Za-z.-]+)?)$/D';

    public static function validVersion(string $version): bool
    {
        return strlen($version) <= 50 && preg_match(self::VERSION, $version) === 1;
    }

    public static function valid(string $constraint): bool
    {
        try {
            self::satisfies('0.0.0', $constraint);

            return true;
        } catch (AddonException) {
            return false;
        }
    }

    /** @throws AddonException for an unparsable constraint */
    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || strlen($constraint) > 200) {
            throw new AddonException('addons.error_constraint', ['constraint' => $constraint]);
        }
        $matched = false;
        foreach (explode('||', $constraint) as $alternative) {
            $terms = preg_split('/[\s,]+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($terms === []) {
                throw new AddonException('addons.error_constraint', ['constraint' => $constraint]);
            }
            $all = true;
            foreach (self::joinOperators($terms) as $term) {
                $all = self::term($version, $term, $constraint) && $all;
            }
            $matched = $matched || $all;
        }

        return $matched;
    }

    /**
     * Rejoins an operator written apart from its version (">= 1.0").
     *
     * @param  list<string>  $terms
     * @return list<string>
     */
    private static function joinOperators(array $terms): array
    {
        $joined = [];
        foreach ($terms as $term) {
            $last = count($joined) - 1;
            if ($last >= 0 && preg_match('/^(\^|~|>=|<=|>|<|==|=|!=)$/D', $joined[$last]) === 1) {
                $joined[$last] .= $term;
            } else {
                $joined[] = $term;
            }
        }

        return $joined;
    }

    private static function term(string $version, string $term, string $constraint): bool
    {
        if ($term === '*') {
            return true;
        }
        if (preg_match(self::TERM, $term, $m) !== 1) {
            throw new AddonException('addons.error_constraint', ['constraint' => $constraint]);
        }
        $operator = $m[1] === '' ? '=' : $m[1];
        [$numbers, $suffix] = array_pad(explode('-', $m[2], 2), 2, null);
        $parts = explode('.', $numbers);
        $wildcard = array_search('*', $parts, true);
        if ($wildcard !== false) {
            if ($operator !== '=' || $suffix !== null || $wildcard !== count($parts) - 1) {
                throw new AddonException('addons.error_constraint', ['constraint' => $constraint]);
            }
            $fixed = array_map(intval(...), array_slice($parts, 0, $wildcard));

            return self::between($version, self::pad($fixed), self::bump($fixed, $wildcard - 1));
        }
        $given = array_map(intval(...), $parts);
        $lower = self::pad($given).($suffix === null ? '' : '-'.$suffix);

        return match ($operator) {
            '^' => self::between($version, $lower, self::bump($given, self::caretIndex($given))),
            '~' => self::between($version, $lower, self::bump($given, count($given) === 1 ? 0 : count($given) - 2)),
            '=', '==' => version_compare($version, $lower, '=='),
            '!=' => version_compare($version, $lower, '!='),
            default => version_compare($version, $lower, $operator),
        };
    }

    /** @param list<int> $given */
    private static function caretIndex(array $given): int
    {
        foreach ($given as $index => $part) {
            if ($part !== 0 || $index === count($given) - 1) {
                return $index;
            }
        }

        return 0;
    }

    /** @param list<int> $parts */
    private static function pad(array $parts): string
    {
        return implode('.', array_pad($parts, 3, 0));
    }

    /**
     * The exclusive upper bound: increments the part at $index and drops the rest. A negative index means no bound.
     *
     * @param  list<int>  $parts
     */
    private static function bump(array $parts, int $index): ?string
    {
        if ($index < 0) {
            return null;
        }
        $parts = array_slice(array_pad($parts, 3, 0), 0, $index + 1);
        $parts[$index]++;

        return self::pad($parts);
    }

    private static function between(string $version, string $lower, ?string $upper): bool
    {
        return version_compare($version, $lower, '>=') && ($upper === null || version_compare($version, $upper, '<'));
    }
}
