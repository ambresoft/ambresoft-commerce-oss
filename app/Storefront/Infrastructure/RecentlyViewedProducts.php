<?php

namespace App\Storefront\Infrastructure;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Bounded visitor history; account writes serialize on the identity, including guest merges. */
final class RecentlyViewedProducts
{
    public const SESSION = 'recently_viewed_products';

    /** @return list<string> */
    public function ids(Request $request): array
    {
        $user = $request->user()?->getAuthIdentifier();
        if ($user === null) {
            return array_map(strval(...), array_keys($this->latest($request->session()->get(self::SESSION, []))));
        }

        return DB::table('recently_viewed_products')->where('user_id', $user)->orderByDesc('viewed_at')->orderByDesc('product_id')->pluck('product_id')->map(strval(...))->all();
    }

    public function track(Request $request, string $product): void
    {
        // Inertia prefetches and HEAD requests are not product views. React rerenders never write history.
        if (! $request->isMethod('GET') || str_contains(strtolower($request->header('Purpose', '').' '.$request->header('Sec-Purpose', '')), 'prefetch')) {
            return;
        }
        $entry = [$product => now()->format('Y-m-d H:i:s.uP')];
        if ($request->user() === null) {
            $request->session()->put(self::SESSION, $this->latest(array_replace($request->session()->get(self::SESSION, []), $entry)));
        } else {
            $this->save((string) $request->user()->getAuthIdentifier(), $entry);
        }
    }

    /** Called on session creation, including unverified registration before a customer profile exists. */
    public function merge(Request $request): void
    {
        $entries = $request->session()->get(self::SESSION, []);
        if ($request->user() !== null && $entries !== []) {
            $this->save((string) $request->user()->getAuthIdentifier(), $entries);
            $request->session()->forget(self::SESSION);
        }
    }

    /** @param array<int|string,string> $entries */
    private function save(string $user, array $entries): void
    {
        DB::transaction(function () use ($user, $entries): void {
            DB::table('users')->where('id', $user)->lockForUpdate()->first(['id']);
            foreach (DB::table('recently_viewed_products')->where('user_id', $user)->get() as $row) {
                if (! isset($entries[$row->product_id]) || CarbonImmutable::parse($entries[$row->product_id])->lessThan(CarbonImmutable::parse($row->viewed_at))) {
                    $entries[$row->product_id] = $row->viewed_at;
                }
            }
            // A product may have been permanently removed since the guest viewed it.
            $existing = DB::table('products')->whereIn('id', array_keys($entries))->sharedLock()->pluck('id')->all();
            $entries = $this->latest(array_intersect_key($entries, array_flip($existing)));
            DB::table('recently_viewed_products')->where('user_id', $user)->whereNotIn('product_id', array_keys($entries))->delete();
            if ($entries !== []) {
                DB::table('recently_viewed_products')->upsert(array_map(fn ($id, $at): array => ['user_id' => $user, 'product_id' => $id, 'viewed_at' => $at], array_keys($entries), array_values($entries)), ['user_id', 'product_id'], ['viewed_at']);
            }
        });
    }

    /** @param array<int|string,string> $entries
     * @return array<int|string,string>
     */
    private function latest(array $entries): array
    {
        $entries = array_map(fn (string $at): string => CarbonImmutable::parse($at)->utc()->format('Y-m-d H:i:s.uP'), $entries);
        arsort($entries, SORT_STRING);

        return array_slice($entries, 0, 12, true);
    }
}
