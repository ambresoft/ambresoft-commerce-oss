<?php

namespace Ambresoft\Typesense\Infrastructure;

use Ambresoft\Typesense\Domain\TypesenseDocument;
use Ambresoft\Typesense\Domain\TypesenseQuery;
use Ambresoft\Typesense\Domain\TypesenseSchema;
use App\Search\Application\Command\SearchHealth;
use App\Search\Application\Command\SearchQuery;
use App\Search\Application\Command\SearchResult;
use App\Search\Application\Contract\SearchProvider;
use App\Search\Application\Contract\SearchUnavailable;
use App\Shared\Application\Command\SettingField;
use Illuminate\Support\Facades\Cache;

/**
 * Typesense as the store's product search. Searches use the collection alias; physical collections are versioned
 * (`{alias}_{layout}_{time}`) so a rebuild fills a new one and the alias switches to it in one step, with the previous
 * collection kept for recovery. Synonyms are a synonym set attached to every collection, written once per tokenizer
 * locale so Greek synonyms match Greek fields.
 */
final class TypesenseSearchProvider implements SearchProvider
{
    private const TYPOS = ['off' => 0, 'low' => 1, 'normal' => 2];

    public function __construct(private TypesenseClient $client) {}

    public function id(): string
    {
        return 'typesense';
    }

    public function name(): string
    {
        return 'Typesense';
    }

    public function settings(): array
    {
        return [
            new SettingField('typo_tolerance', 'select', 'cms-typesense::typesense.typo_tolerance', 'cms-typesense::typesense.typo_tolerance_hint',
                options: ['normal' => 'cms-typesense::typesense.typos_normal', 'low' => 'cms-typesense::typesense.typos_low', 'off' => 'cms-typesense::typesense.typos_off'], default: 'normal'),
            new SettingField('min_query_length', 'integer', 'cms-typesense::typesense.min_query_length', 'cms-typesense::typesense.min_query_length_hint', default: '2', min: '0', max: '10'),
            new SettingField('synonyms', 'textarea', 'cms-typesense::typesense.synonyms', 'cms-typesense::typesense.synonyms_hint', maxLength: 2000),
        ];
    }

    public function validateSettings(array $values): array
    {
        $lines = $this->synonymLines($values['synonyms'] ?? '');

        return $lines === null ? ['synonyms' => __('cms-typesense::typesense.synonyms_invalid')] : [];
    }

    public function minimumLength(array $values): int
    {
        // Empty means no rule.
        return max(0, min(10, (int) ($values['min_query_length'] ?? 2)));
    }

    public function configure(array $values, array $languages): void
    {
        $items = [];
        $locales = array_values(array_unique(array_map(TypesenseSchema::locale(...), $languages)));
        foreach ($this->synonymLines($values['synonyms'] ?? '') ?? [] as $n => [$root, $terms]) {
            foreach ($locales as $locale) {
                $items[] = array_filter(['id' => 'line-'.($n + 1).($locale === '' ? '' : '-'.$locale), 'root' => $root, 'synonyms' => $terms, 'locale' => $locale], fn ($v): bool => $v !== '');
            }
        }
        $this->client->json('PUT', '/synonym_sets/'.$this->synonymSet(), json: ['items' => $items]);
    }

    public function health(array $values, array $languages): SearchHealth
    {
        if (! $this->client->configured()) {
            return new SearchHealth(false, false, false, message: __('cms-typesense::typesense.not_configured'));
        }
        try {
            $version = (string) ($this->client->json('GET', '/debug')['version'] ?? '');
            $collection = $this->live();
            if ($collection === null) {
                return new SearchHealth(true, false, false, version: $version, message: __('cms-typesense::typesense.no_index'));
            }
            $info = $this->client->json('GET', '/collections/'.$collection);

            return new SearchHealth(true, true, TypesenseSchema::current($this->client->alias(), $collection, $languages), (int) ($info['num_documents'] ?? 0), $this->client->alias().' → '.$collection, $version);
        } catch (SearchUnavailable $e) {
            return new SearchHealth(false, false, false, message: $e->getMessage());
        }
    }

    public function prepare(array $values, array $languages): void
    {
        if ($this->live() !== null) {
            return;
        }
        $this->configure($values, $languages);
        $collection = $this->create($languages);
        $this->client->json('PUT', '/aliases/'.$this->client->alias(), json: ['collection_name' => $collection]);
        Cache::forget($this->cacheKey());
    }

    public function index(array $documents, array $values, ?string $target = null): array
    {
        $collection = $target ?? $this->client->alias();
        $lines = implode("\n", array_map(fn ($d): string => json_encode(TypesenseDocument::from($d), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $documents));
        $response = $this->client->send('POST', '/collections/'.$collection.'/documents/import', ['action' => 'upsert', 'dirty_values' => 'reject'], raw: $lines, kind: 'import');
        if ($response->status() === 404) {
            throw new SearchUnavailable('not_initialized', 'Typesense has no collection '.$collection.'.');
        }
        if (! $response->successful()) {
            throw new SearchUnavailable('rejected', 'Typesense refused the import: '.mb_substr((string) ($response->json('message') ?? $response->body()), 0, 300));
        }
        $failures = [];
        foreach (explode("\n", trim($response->body())) as $n => $line) {
            $result = json_decode($line, true);
            if (! is_array($result) || ($result['success'] ?? false) !== true) {
                $failures[$documents[$n]->id ?? 'unknown'] = mb_substr((string) ($result['error'] ?? 'Unreadable import answer'), 0, 300);
            }
        }

        return $failures;
    }

    public function remove(array $ids, array $values, ?string $target = null): void
    {
        $collection = $target ?? $this->client->alias();
        foreach (array_chunk($ids, 200) as $chunk) {
            $response = $this->client->send('DELETE', '/collections/'.$collection.'/documents', ['filter_by' => 'id:['.implode(',', array_map(fn (string $id): string => (string) (int) $id, $chunk)).']']);
            if ($response->status() === 404) {
                return;
            }
            if (! $response->successful()) {
                throw new SearchUnavailable('rejected', 'Typesense refused the deletion: '.mb_substr((string) ($response->json('message') ?? $response->body()), 0, 300));
            }
        }
    }

    public function search(SearchQuery $query, array $values): SearchResult
    {
        if ($query->categories === []) {
            // Nothing the visitor may see: no request, an honest empty result.
            return new SearchResult([], 0, $query->facets ? [['kind' => 'total', 'n' => 0]] : []);
        }
        $builder = new TypesenseQuery($this->client->alias(), self::TYPOS[$values['typo_tolerance'] ?? 'normal'] ?? 2, $this->indexedLanguages());
        ['searches' => $searches, 'groups' => $groups] = $builder->build($query);
        $response = $this->client->json('POST', '/multi_search', json: ['searches' => $searches], kind: 'search');
        $results = $response['results'] ?? [];
        foreach ($results as $result) {
            if (isset($result['error'])) {
                $code = (int) ($result['code'] ?? 400);
                throw new SearchUnavailable($code === 404 ? 'not_initialized' : 'rejected', 'Typesense refused the search: '.mb_substr((string) $result['error'], 0, 300));
            }
        }
        if (count($results) !== count($searches)) {
            throw new SearchUnavailable('rejected', 'Typesense answered an incomplete search.');
        }

        return $builder->parse($query, $results, $groups);
    }

    public function startRebuild(array $values, array $languages): string
    {
        $this->configure($values, $languages);

        return $this->create($languages);
    }

    public function count(string $target, array $values): int
    {
        return (int) ($this->client->json('GET', '/collections/'.$this->collectionName($target))['num_documents'] ?? 0);
    }

    public function finishRebuild(string $target, array $values): void
    {
        $this->client->json('PUT', '/aliases/'.$this->client->alias(), json: ['collection_name' => $this->collectionName($target)]);
        Cache::forget($this->cacheKey());
    }

    public function discardRebuild(string $target, array $values): void
    {
        $target = $this->collectionName($target);
        if ($target === $this->live()) {
            return;
        }
        $this->client->send('DELETE', '/collections/'.$target);
    }

    public function indexedIds(array $values): iterable
    {
        $response = $this->client->send('GET', '/collections/'.$this->client->alias().'/documents/export', ['include_fields' => 'id'], kind: 'import');
        if ($response->status() === 404) {
            return;
        }
        if (! $response->successful()) {
            throw new SearchUnavailable('rejected', 'Typesense refused the export.');
        }
        foreach (explode("\n", $response->body()) as $line) {
            $id = json_decode($line, true)['id'] ?? null;
            if (is_string($id)) {
                yield $id;
            }
        }
    }

    public function cleanup(array $values): array
    {
        $live = $this->live();
        $prefix = $this->client->alias().'_';
        $own = array_values(array_filter($this->client->json('GET', '/collections'), fn (array $c): bool => str_starts_with((string) ($c['name'] ?? ''), $prefix) && $c['name'] !== $live));
        usort($own, fn (array $a, array $b): int => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));
        $deleted = [];
        // The newest collection that is not live stays as the previous one, for recovery.
        foreach (array_slice($own, 1) as $collection) {
            $this->client->send('DELETE', '/collections/'.$collection['name']);
            $deleted[] = (string) $collection['name'];
        }

        return $deleted;
    }

    /** The collection behind the alias, or null before the first one exists. */
    private function live(): ?string
    {
        $response = $this->client->send('GET', '/aliases/'.$this->client->alias());
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new SearchUnavailable('rejected', 'Typesense refused the alias lookup.');
        }

        return (string) $response->json('collection_name');
    }

    /** @param list<string> $languages */
    private function create(array $languages): string
    {
        $name = TypesenseSchema::collection($this->client->alias(), $languages, now()->format('YmdHis').bin2hex(random_bytes(2)));
        $this->client->json('POST', '/collections', json: ['name' => $name, 'fields' => TypesenseSchema::fields($languages), 'synonym_sets' => [$this->synonymSet()]]);

        return $name;
    }

    /**
     * The languages the live collection has text fields for (a newly activated language needs a rebuild first), read
     * from its schema and remembered for a minute.
     *
     * @return list<string>
     */
    private function indexedLanguages(): array
    {
        return Cache::remember($this->cacheKey(), 60, function (): array {
            $fields = $this->client->json('GET', '/collections/'.$this->client->alias(), kind: 'search')['fields'] ?? [];
            $languages = [];
            foreach ($fields as $field) {
                if (preg_match('/^name_([a-z]{2,3})$/D', (string) ($field['name'] ?? ''), $m) === 1) {
                    $languages[] = $m[1];
                }
            }

            return $languages;
        });
    }

    private function cacheKey(): string
    {
        return 'typesense:languages:'.$this->client->alias();
    }

    private function synonymSet(): string
    {
        return $this->client->alias().'-synonyms';
    }

    /** Only this provider's collection names reach a URL. */
    private function collectionName(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,120}$/D', $name) !== 1 || ! str_starts_with($name, $this->client->alias().'_')) {
            throw new SearchUnavailable('rejected', 'Not a collection of this store.');
        }

        return $name;
    }

    /**
     * Synonym lines: "a, b, c" (all mean the same) or "a => b, c" (searching a also finds b and c). Null when invalid.
     *
     * @return list<array{0: string, 1: list<string>}>|null
     */
    private function synonymLines(string $text): ?array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$root, $rest] = str_contains($line, '=>') ? array_map(trim(...), explode('=>', $line, 2)) : ['', $line];
            $terms = array_values(array_unique(array_filter(array_map(fn (string $t): string => mb_strtolower(trim($t)), explode(',', $rest)), fn (string $t): bool => $t !== '')));
            $root = mb_strtolower($root);
            if (count($terms) < ($root === '' ? 2 : 1) || preg_match('/[\x00-\x1f\x7f]/u', $line) === 1 || max(array_map(mb_strlen(...), [$root, ...$terms])) > 60 || str_contains($rest, '=>')) {
                return null;
            }
            $lines[] = [$root, $terms];
        }

        return count($lines) > 200 ? null : $lines;
    }
}
