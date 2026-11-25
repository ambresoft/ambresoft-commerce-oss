<?php

namespace App\Website\Infrastructure;

use App\Website\Domain\Service\RedirectRules;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The enabled redirects as one cached map (normalized source => [destination, status]), read on storefront requests.
 * Every change to a rule clears it after commit.
 */
final class RedirectMap
{
    private const KEY = 'seo-redirects:map';

    /** @var array<string, array{0: string, 1: int}>|null */
    private ?array $map = null;

    /** @return array<string, array{0: string, 1: int}> */
    public function all(): array
    {
        return $this->map ??= Cache::rememberForever(self::KEY, fn (): array => DB::table('seo_redirects')->where('enabled', true)->get(['source_path', 'destination', 'status_code'])
            ->mapWithKeys(fn (object $r): array => [$r->source_path => [$r->destination, (int) $r->status_code]])->all());
    }

    public function forget(): void
    {
        $this->map = null;
        Cache::forget(self::KEY);
    }

    /**
     * Where a request path finally leads: a chain of rules is followed to its end so visitors and search engines get
     * one hop. The first rule's status is kept. Null when no rule matches or the rules form a loop.
     *
     * @return array{0: string, 1: int}|null
     */
    public function resolve(string $path): ?array
    {
        $map = $this->all();
        if (! isset($map[$path])) {
            return null;
        }
        [$destination, $status] = $map[$path];
        $seen = [$path => true];
        for ($hop = 0; $hop < RedirectRules::MAX_HOPS; $hop++) {
            $next = RedirectRules::internalPath($destination);
            if ($next === null || ! isset($map[$next])) {
                return [$destination, $status];
            }
            if (isset($seen[$next])) {
                return null;
            }
            $seen[$next] = true;
            $destination = $map[$next][0];
        }

        return null;
    }
}
