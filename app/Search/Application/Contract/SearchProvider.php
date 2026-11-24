<?php

namespace App\Search\Application\Contract;

use App\Search\Application\Command\SearchDocument;
use App\Search\Application\Command\SearchHealth;
use App\Search\Application\Command\SearchQuery;
use App\Search\Application\Command\SearchResult;
use App\Shared\Application\Command\SettingField;

/**
 * A product search engine contributed by an add-on (manifest contribution `search-providers`). The engine owns text
 * matching, relevance, typo tolerance, facet counting and its own index layout; the CMS owns the catalog, decides what
 * is indexed and who may see what, and hydrates every hit from its own records before showing it.
 *
 * `$values` are the provider's stored settings (secrets decrypted, never sent to the browser). `$languages` are the
 * active language codes, the default first; a provider whose index layout depends on them reports `current = false`
 * in health() when they change, and the administrator rebuilds. Every call that reaches the engine throws
 * SearchUnavailable when it cannot be completed; nothing fails silently.
 */
interface SearchProvider
{
    /** Stable identifier, e.g. `typesense`. */
    public function id(): string;

    /** Display name for administrators (not translated). */
    public function name(): string;

    /** @return list<SettingField> Merchant settings (relevance, typo tolerance, synonyms…); connection secrets belong in deployment configuration */
    public function settings(): array;

    /**
     * @param  array<string, string>  $values
     * @return array<string, string> Field key => localized message
     */
    public function validateSettings(array $values): array;

    /**
     * Characters a query needs before it is searched (0: any non-empty query). Shorter queries are refused with a
     * message on the results page and get no suggestions.
     *
     * @param  array<string, string>  $values
     */
    public function minimumLength(array $values): int;

    /**
     * Applies saved settings that live in the engine (e.g. synonyms). Called after the settings are saved and before a
     * rebuilt index goes live.
     *
     * @param  array<string, string>  $values
     * @param  list<string>  $languages
     */
    public function configure(array $values, array $languages): void;

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $languages
     */
    public function health(array $values, array $languages): SearchHealth;

    /**
     * Makes sure a live index exists (empty if new) so changes can be indexed. Idempotent; never deletes anything.
     *
     * @param  array<string, string>  $values
     * @param  list<string>  $languages
     */
    public function prepare(array $values, array $languages): void;

    /**
     * Adds or replaces documents in the live index and, during a rebuild, in `$target` too.
     *
     * @param  list<SearchDocument>  $documents
     * @param  array<string, string>  $values
     * @return array<string, string> Documents the engine refused: product ID => reason (the rest were indexed)
     */
    public function index(array $documents, array $values, ?string $target = null): array;

    /**
     * Removes documents from the live index and, during a rebuild, from `$target`. Missing documents are not an error.
     *
     * @param  list<string>  $ids
     * @param  array<string, string>  $values
     */
    public function remove(array $ids, array $values, ?string $target = null): void;

    /** @param array<string, string> $values */
    public function search(SearchQuery $query, array $values): SearchResult;

    /**
     * Starts a full rebuild: a new, empty index with the current layout, not yet live.
     *
     * @param  array<string, string>  $values
     * @param  list<string>  $languages
     * @return string The rebuild target's name
     */
    public function startRebuild(array $values, array $languages): string;

    /**
     * Documents in a rebuild target, to verify it is complete before it goes live.
     *
     * @param  array<string, string>  $values
     */
    public function count(string $target, array $values): int;

    /**
     * Makes a complete rebuild target the live index in one step. The previous index is kept for recovery.
     *
     * @param  array<string, string>  $values
     */
    public function finishRebuild(string $target, array $values): void;

    /**
     * Drops an unfinished rebuild target. Never touches the live index.
     *
     * @param  array<string, string>  $values
     */
    public function discardRebuild(string $target, array $values): void;

    /**
     * Every product ID in the live index, streamed, for reconciliation with the catalog.
     *
     * @param  array<string, string>  $values
     * @return iterable<string>
     */
    public function indexedIds(array $values): iterable;

    /**
     * Deletes indexes that are neither live nor the most recent previous one.
     *
     * @param  array<string, string>  $values
     * @return list<string> Names of the deleted indexes
     */
    public function cleanup(array $values): array;
}
