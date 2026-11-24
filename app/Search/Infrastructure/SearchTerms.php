<?php

namespace App\Search\Infrastructure;

use App\Identity\Infrastructure\Persistence\UserRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * What shoppers search for: one counted row per normalized search text, with the number of products the latest search
 * found. Only the text and counts are kept (no visitor, address or individual time). Staff can keep a term out of the
 * Popular searches block; rarely searched old terms are pruned.
 */
final class SearchTerms
{
    /** Terms searched only once are forgotten after this many days (unless a block shows them). */
    public const PRUNE_DAYS = 90;

    public const SORTS = ['searches', 'last_searched_at', 'term'];

    /** The stored form of a search text: lower case, single spaces. */
    public static function normalize(string $text): string
    {
        return mb_substr(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text))), 0, 100);
    }

    /** Counts one submitted search and remembers how many products it found. */
    public function record(string $text, int $results): void
    {
        $term = self::normalize($text);
        if ($term === '') {
            return;
        }
        DB::statement('INSERT INTO search_terms (term, searches, results, first_searched_at, last_searched_at) VALUES (?, 1, ?, now(), now())
            ON CONFLICT (term) DO UPDATE SET searches = search_terms.searches + 1, results = EXCLUDED.results, last_searched_at = EXCLUDED.last_searched_at', [$term, max(0, $results)]);
    }

    /**
     * The most searched terms that found products and are not hidden.
     *
     * @return list<string>
     */
    public function popular(int $limit): array
    {
        return DB::table('search_terms')->where('hidden', false)->where('results', '>', 0)
            ->orderByDesc('searches')->orderByDesc('last_searched_at')->orderBy('id')->limit(max(1, min(50, $limit)))->pluck('term')->all();
    }

    /**
     * Terms by ID, in the given order (a block's own picks; hidden terms included, since staff chose them).
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function picked(array $ids): array
    {
        $terms = $ids === [] ? [] : DB::table('search_terms')->whereIn('id', $ids)->pluck('term', 'id')->all();

        return array_values(array_filter(array_map(fn (string $id): ?string => $terms[$id] ?? null, $ids)));
    }

    /**
     * The admin list: every counted term, filtered and sorted.
     *
     * @param  array<string, string>  $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters): array
    {
        $query = DB::table('search_terms');
        $search = self::normalize($filters['search'] ?? '');
        if ($search !== '') {
            $query->where('term', 'like', '%'.addcslashes($search, '%_\\').'%');
        }
        if (($filters['visibility'] ?? '') !== '') {
            $query->where('hidden', $filters['visibility'] === 'hidden');
        }
        if (($filters['results'] ?? '') !== '') {
            $query->where('results', $filters['results'] === 'none' ? '=' : '>', 0);
        }
        $sort = in_array($filters['sort'] ?? '', self::SORTS, true) ? $filters['sort'] : 'searches';
        $direction = ($filters['direction'] ?? '') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->orderBy('id', $direction)->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()
            ->through(fn (object $r): array => ['id' => (string) $r->id, 'term' => (string) $r->term, 'searches' => (int) $r->searches, 'results' => (int) $r->results,
                'hidden' => (bool) $r->hidden, 'last_searched_at' => CarbonImmutable::parse($r->last_searched_at)->utc()->toIso8601String()])->toArray();
    }

    /** Keeps a term out of (or lets it back into) the Popular searches block. */
    public function hide(string $actor, string $id, bool $hidden): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('search.manage');
        // Audited by the table's trigger.
        if (DB::table('search_terms')->where('id', $id)->update(['hidden' => $hidden]) === 0) {
            abort(404);
        }
    }

    /** Forgets terms searched once long ago; the table stays bounded however many one-off texts shoppers type. */
    public function prune(): int
    {
        return DB::table('search_terms')->where('searches', '<=', 1)->where('hidden', false)->where('last_searched_at', '<', now()->subDays(self::PRUNE_DAYS))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('storefront_block_selections as s')->whereColumn('s.search_term_id', 'search_terms.id'))->delete();
    }
}
