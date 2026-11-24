# Product search (A120)

Storefront product search runs on a search engine contributed by an add-on: the bundled `ambresoft/typesense`
([Typesense](https://typesense.org) 30.2). The CMS stays the source of truth for products, prices, stock, visibility and
permissions. The engine only matches text, ranks results, counts facets and paginates.

## Architecture

| Part | Lives in | Responsibility |
| --- | --- | --- |
| `search-providers` extension point | core `App\Search` | `SearchProvider` contract, `SearchDocument` / `SearchQuery` / `SearchResult` / `SearchHealth` data, `SearchUnavailable` |
| Change capture | core migration `2026_11_10_000100` | PostgreSQL triggers queue products in `search_outbox` in the same transaction as the catalog change |
| Indexing | core `SearchIndex`, `SearchDocuments` | Builds documents from the catalog in batches, sends them to the provider, retries, rebuilds, reconciles |
| Visibility | core `SearchVisibility` | Searchable categories and hidden products, decided on every search |
| Storefront | core `ProductSearch`, `SearchController` | `/search` (Search layout) and `/search/suggest` (JSON); validates every parameter; hydrates hits |
| Administration | core `SearchSettingsController` | Settings → Search (`search.manage`): health, sync, rebuilds, reconciliation, provider settings |
| Engine | add-on `ambresoft/typesense` | Typesense client, collection layout, synonyms, queries and facets |

The add-on depends only on the published `App\Search\Application` contracts and `App\Shared\Application\Command\SettingField`.
Its settings (typo tolerance, synonyms) are stored, validated, audited and rendered by the CMS like carrier settings.
The connection is deployment configuration, never a merchant setting:

| Variable | Default | Meaning |
| --- | --- | --- |
| `TYPESENSE_NODES` | — | Comma-separated base URLs of a cluster; overrides the next three |
| `TYPESENSE_PROTOCOL` / `TYPESENSE_HOST` / `TYPESENSE_PORT` | `http` / — / `8108` | A single node; no host means "not configured" |
| `TYPESENSE_API_KEY` | — | Admin key, server-side only |
| `TYPESENSE_COLLECTION` | `products` | The alias searches use |
| `TYPESENSE_TIMEOUT` / `TYPESENSE_CONNECT_TIMEOUT` / `TYPESENSE_IMPORT_TIMEOUT` | `3` / `2` / `60` s | Storefront searches / connecting / bulk imports |
| `TYPESENSE_RETRIES` / `TYPESENSE_RETRY_MS` | `2` / `200` | Extra attempts after connection errors, 429 or 5xx (storefront searches retry at most once), with a growing pause |

Nothing is hard-coded to a host. The Compose service is only the local default (`TYPESENSE_HOST=typesense`); any
reachable Typesense works, including a hosted cluster.

## What is indexed

A document per **product published as a product**: active, not deleted, with SKU, price, a default-language name and a
real (non-system) category (`SearchDocuments::eligible()`). It holds:

- **Searchable text:**
  - the names in every active language (real translations only, no copied fallbacks);
  - codes (SKU, MPN, EAN, ISBN, UPC);
  - the brand name;
  - the category path names;
  - the short description and description as plain text, with the chosen option labels.
- **Filter, facet and sort fields:**
  - category ID and brand ID;
  - the stored catalog price (the price category listings filter and sort by, A114);
  - in-stock flag and the availability status the product page shows;
  - approved-review rating;
  - chosen feature options, feature numbers and yes/no features;
  - product customer groups;
  - creation time.

Prices and stock in the index are only for filtering and sorting. Every card shows the live price, sale and tax from the
pricing services, as everywhere else, and the cart and checkout never read the index.

The Typesense layout (`TypesenseSchema`):
- **Language fields.** Each active language gets `name_xx`, `brand_xx`, `category_xx` and `text_xx`. Greek, Cyrillic
  and CJK languages use Typesense's locale tokenizer, which handles accents, tonos and final sigma.
- **Feature fields.** Features are stored as `opt_{featureId}` and `num_{featureId}` by numeric ID, never by merchant
  text.
- **Exact numbers.** Amounts and numbers are integers in ten-thousandths, because Typesense floats are 32-bit and would
  blur filter boundaries.
- **Versioned collections.** Physical collections are `{alias}_{layoutFingerprint}_{time}` behind the alias. The
  fingerprint covers the field layout version and the active languages: after a language is added, health reports
  "rebuild needed" and searches keep using the languages the live collection has until the rebuild.

## Keeping the index current

**Change capture.** Triggers on these tables queue the affected products:
- `products`, `product_translations`, `product_customer_groups`;
- `product_feature_assignments` and `product_feature_choices`;
- `product_reviews`;
- `brands` and `brand_translations`;
- `categories` (a move changes path names) and `category_translations`;
- `feature_option_translations`;
- `availability_statuses` (default labels) and `general_settings` (`track_inventory`).

They queue only on columns the document uses: a stock change re-indexes only when stock crosses zero, and weight,
shipping or version bumps never do. Queuing happens in the same transaction as the change, so a commit cannot lose its
search update, and a failed indexing step never touches catalog data.

**Processing.** The scheduler checks every 5 seconds and queues `ProcessSearchOutbox` while changes are due. A worker
holds one write lock, indexes batches of 250 (`import?action=upsert`), and removes documents of products that are no
longer published.
- **No lost changes.** Each row carries a version, and a worker deletes a row only for the version it indexed, so a
  change made meanwhile is picked up next time.
- **Refused documents.** They are retried after 15 s, 30 s, 60 s… up to 1 h, and marked failed after 8 attempts. They
  stay visible in the admin and are retried by reconciliation.
- **Outages.** If the engine cannot be reached, due rows wait 30 s without using up attempts.

**Consistency interval.** Usually a few seconds; at most the scheduler interval plus a batch.

**Critical changes fail closed.** A change to publication, category, customer groups, a deleted or emptied name, or
stock reaching zero is queued as *critical*. Until the index confirms it, the product is excluded from every search
request, so results, counts and facets all omit it. Beyond 1,000 pending critical changes (e.g. during a mass import),
results are still shown and checked, but counts and facets are withheld ("degraded" state) rather than risk counting
hidden products.

## Rebuilds and reconciliation

**Full rebuild.** Settings → Search → Rebuild, or `php artisan cms:search-reindex [--now]`:
1. A new collection is created.
2. Products are imported in batches by ID cursor, as resumable `RebuildSearchIndex` jobs on the `maintenance` queue.
3. Changes queued meanwhile are written to both collections.
4. The new collection is verified: nothing refused, and as many documents as publishable products.
5. Only then does the alias switch atomically.

The previous collection is kept for recovery. A failed or cancelled rebuild drops its own collection and never touches
the live one. One rebuild runs at a time (database unique index). The first build also sets "search ready": until then
the storefront says search is being prepared.

**Reconciliation.** Daily, or from the admin, or `cms:search-reconcile`. It exports the live IDs and compares them with
the catalog, then queues:
- missing products;
- stray documents, as critical so they hide immediately;
- failed rows, for another attempt.

**Other commands.**
- `cms:search-sync` indexes queued changes now.
- `cms:search-cleanup` deletes collections other than the live one and the newest previous one, never during a rebuild.

Nothing runs on start-up, migration or container restart, and nothing deletes the live collection automatically.

## Visibility and "Exclude from search"

Category rules are applied when searching, from the database, so they take effect at once without reindexing.
`SearchVisibility::categories()` returns the categories that are:
- **visible to the visitor:** active ancestry, customer groups;
- **leaves:** where products are published;
- **not excluded:** no category on the path has *Exclude from search and highlights* (`exclude_from_discovery`).

**Exclusion is inherited down the tree,** like status and customer-group restrictions (this settles the open question
in [categories](categories.md#discovery-exclusion-and-customer-group-visibility)). A product has one category, so a
product inside an excluded subtree is excluded, never partly visible.

Every request to the engine carries:
- `category_id:[searchable categories]`;
- `(public || groups:[visitor's groups])`;
- `product_id:!=[pending critical]`;
- `in_stock:true` when listings hide products without stock.

Results, suggestions, counts and every facet are therefore computed on searchable products only. A stale document of an
excluded category can match nothing.

Hits are then read back with `PublicCatalog::listed($customer)` restricted to the searchable categories, in one query,
before any card is shown. The engine is never the authority on what a visitor may see.

**Customer groups.** They match the catalog exactly. Category restrictions are part of the searchable categories above.
A product's own restriction is in its document and matches a visitor in any of its groups, as `PublicCatalog` does.

**Limits.**
- Shop filters (new, sale, best sellers) are not offered on search: sales are computed per visitor by `ProductSales`.

## Storefront

**Header search box.** The identity block's search field appears only while a search add-on is enabled.
- **Suggestions.** The block setting *Search suggestions* (0–10, default 6; 0 turns them off) controls how many show.
  They start at the minimum query length and wait 250 ms after typing stops. A newer keystroke aborts the older request.
- **Minimum query length.** A provider setting (`SearchProvider::minimumLength()`; Typesense: 0–10, default 2, empty
  or 0 for no rule). Shorter queries are not searched: the field shows "type at least N characters" instead of
  submitting, `/search` answers with the `too_short` state, and suggestions return nothing. An empty query is never a
  search.
- **Keyboard and accessibility.** It is an accessible combobox: arrows move through the products and "see all
  results", Enter opens the highlighted entry or the results page, and Escape closes the list (a second Escape clears
  the field).
- **Outages.** An outage shows "suggestions are unavailable", never "no products".

**`/search?q=`.** The **Search** layout: the filters block beside the protected main content block, configured like
category layouts (`page_type = search`).
- **Reuse.** It reuses the category listing's parameter parsing (`CategoryListing::state()`), filter groups and chips
  (`present()`), cards, sorting UI and pagination. Typesense counts arrive in the same row shape as the SQL facets.
- **Sorts.** Relevance (default), price, newest, rating; price sorts are hidden while prices are hidden.
- **Filter combination.** Filters combine AND across groups and OR within one, with disjunctive counts (one count-only
  request per active group, in the same `multi_search`).
- **Page states.**
  - `no_query`: nothing typed.
  - `too_short`: below the minimum query length (with `min`).
  - `unavailable`: no add-on, or the engine unreachable; HTTP 503 with `Retry-After` on full page loads.
  - `not_ready`: never built.
  - `degraded`: counts withheld.
  - `results`.
- **Not indexed.** Results pages are never indexed by search engines (`noindex`).

**Relevance.** Weights, from strongest:
1. names in the shopper's language;
2. codes (exact, no typos, prefix allowed);
3. default-language names;
4. other languages' names;
5. brand;
6. category path;
7. descriptions.

Ties are broken by rating, then product ID.
- **Typo tolerance.** A provider setting: normal (≤2 per word), low (1), off. Codes never tolerate typos.
- **Query script.** A query written in a language's own script (Greek, Cyrillic…) puts that language's fields first,
  because Typesense reads the query with the first field's tokenizer. "κινητο" finds Greek names on any storefront.
- **Synonyms.** One group per line: "a, b" or "a => b, c". They are stored in the provider settings, written as a
  Typesense synonym set once per tokenizer locale, and applied immediately.

**Popular searches.** Each submitted search (a plain `/search?q=` request, not paging, sorting or filtering it) counts
one row per normalized text in `search_terms`: lower case, single spaces, at most 100 characters, with the number of
products the latest search found. No visitor, address or per-search time is kept.
- **Admin screen.** Reports → Popular searches (`search.manage`) lists the terms with filters for results and
  visibility. Its switch hides a term from the Popular searches block; the change is audited by a trigger on `hidden`
  only, so shoppers' searches never write audit rows.
- **Block.** The *Popular searches* block shows pill links to `/search?q=`: wrapping rows on wide screens, one
  sideways-scrolling row with snapping on phones. *Most searched* takes the top terms that found products and are not
  hidden; *Manual selection* uses the block's picked terms in order (`storefront_block_selections.search_term_id`),
  hidden ones included. It renders nothing without a search add-on.
- **Pruning.** Terms searched once, not hidden, not picked by a block and older than 90 days are deleted daily at 03:50.

## Security

- **The key never leaves the server.** The Typesense key appears only in a request header from the server, never in
  props, bundles, logs or messages (tested), and the browser never talks to Typesense.
- **No proxy.** There is no public proxy: `/search` and `/search/suggest` accept only `q`, `page`, `sort`, `limit` and
  the listing's filter parameters. Each is validated against the catalog (active brands, filterable features, active
  options, numeric ranges) before it becomes a numeric filter expression.
- **Administration.** It requires `search.manage`. Long work is queued, and the page shows recorded state only. Typesense
  should stay on a private network: the Compose service publishes no port.
- **Licensing.** Typesense Server is GPL-3.0 and runs as a separate, unmodified service; Ambre only calls its HTTP API.
  See [THIRD_PARTY.md](../../THIRD_PARTY.md). Redistributing an installer or image bundle that includes the server
  needs the product owner's legal review.

## Operations

1. **Local start.** Run `docker compose up -d typesense`, set `TYPESENSE_HOST` and `TYPESENSE_API_KEY` in `.env`, then
   `php artisan cms:addons-sync`. Enable **Typesense search** in Add-ons, then click **Build index** in Settings →
   Search (or `php artisan cms:search-reindex --now`).
   Restart queue workers and the scheduler after enabling or disabling the add-on (`php artisan queue:restart`): a
   running worker keeps the add-ons it started with, so it would not index queued changes.
2. **Health.** Settings → Search shows:
   - whether the engine is reachable, its version, the live alias → collection and its documents;
   - publishable products;
   - queued, hidden and refused changes, and the oldest wait;
   - last sync, last full build and last reconciliation;
   - recent errors and rebuild runs with real progress.

   `GET /health` on Typesense answers `{"ok":true}`.
3. **Production.**
   - Give Typesense persistent storage (the Compose volume `typesense-data`) and a long random admin key.
   - Monitor its memory: the index lives in RAM.
   - Back up with Typesense snapshots (`POST /operations/snapshot`) or simply rebuild from the catalog: the CMS can
     always rebuild the index. Losing the server loses no data.
   - For high availability, use a Typesense cluster and `TYPESENSE_NODES`.
4. **Recovery.**
   - If the engine is down, search shows "unavailable" and changes wait. When it returns, they are indexed
     automatically.
   - If the index is lost or corrupted, rebuild.
   - To roll back a bad build, point the alias back to the kept previous collection with
     `PUT /aliases/{alias} {"collection_name": "<previous>"}`, then reconcile.

## Benchmark

`tests/Benchmark/SearchBenchmarkTest.php` (outside the suites) builds BENCH_PRODUCTS products (default 100k) and
measures end-to-end storefront searches and suggestions, also while a bulk import runs.

**Setup.** 2026-10-25 on a development laptop: Docker Desktop, PHP 8.4 app container, PostgreSQL 18 and Typesense 30.2
on the same machine.

**Catalog.** 100k products in 10 categories, 40 brands, 2 choice features and a numeric feature. Each figure is
end-to-end through the storefront's own validation, visibility queries, the engine (disjunctive facets included) and
card hydration, 25 runs each, from two runs:

**Full build.** 100,000 documents in 148–182 s (550–680 documents/s, batches of 250).

**Results page** (p50 / p95):

| Case | p50 | p95 |
| --- | --- | --- |
| Exact SKU | 19 ms | 25 ms |
| Exact name | 17 ms | 27–34 ms |
| Typo | 18 ms | 20 ms |
| Several words (1,667 hits) | 26 ms | 31 ms |
| Broad query with all facets (20,000 hits) | 44 ms | 61–65 ms |
| Brand filter (2,500 hits) | 56 ms | 185 ms |
| Two feature filters (3,334 hits) | 39 ms | 47 ms |
| Price range (4,000 hits) | 59–71 ms | 94–96 ms |

**Suggestions:** p50 26–67 ms and p95 29–143 ms. The slowest is "prod", which matches all 100k products.

**During a concurrent 20,000-document import:** broad facets p50 124 ms (p95 171 ms), other cases at most p95 115 ms.

The brand-filter p95 outlier and the run-to-run spread are laptop noise. Re-run the benchmark on production-like
hardware before relying on these numbers.

**Choices these numbers support.** Suggestions are debounced at 250 ms, storefront searches time out at 3 s with at most
one retry, and imports use batches of 250 with a 60 s timeout. A full build of 100k products takes about 3 minutes in
the background while the previous index keeps serving.
