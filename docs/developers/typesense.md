---
title: Search with Typesense
parent: Developer guide
nav_order: 7
---

# Search with Typesense
{: .no_toc }

Storefront product search runs on [Typesense](https://typesense.org) 30.2 through the bundled add-on
`ambresoft/typesense`. PostgreSQL stays the source of truth for products, prices, stock, visibility and permissions;
the engine only matches text, ranks, counts facets and paginates.

1. TOC
{:toc}

---

## Who does what

| Part | Lives in | Responsibility |
| --- | --- | --- |
| `search-providers` extension point | core `App\Search` | The `SearchProvider` contract and its data objects (`SearchDocument`, `SearchQuery`, `SearchResult`, `SearchHealth`), `SearchUnavailable` |
| Change capture | core migration | PostgreSQL triggers queue products in `search_outbox` in the same transaction as the catalog change |
| Indexing | core `SearchIndex`, `SearchDocuments` | Builds documents in batches, sends them to the provider, retries, rebuilds, reconciles |
| Visibility | core `SearchVisibility` | Which categories and products a visitor may find, decided on every search |
| Storefront | core `ProductSearch`, `SearchController` | `/search` and `/search/suggest`; validates every parameter; re-reads every hit from PostgreSQL |
| Administration | core | **Settings → Search** (`search.manage`): health, sync, rebuild, reconcile, provider settings |
| Engine | add-on `ambresoft/typesense` | Typesense client, collection layout, synonyms, queries and facets |

## Configuration

The connection is deployment configuration in `.env`, never a merchant setting:

| Variable | Default | Meaning |
| --- | --- | --- |
| `TYPESENSE_NODES` | — | Comma-separated base URLs of a cluster; overrides the next three |
| `TYPESENSE_PROTOCOL` / `TYPESENSE_HOST` / `TYPESENSE_PORT` | `http` / — / `8108` | A single node; no host means "not configured" |
| `TYPESENSE_API_KEY` | — | Admin key; stays on the server |
| `TYPESENSE_COLLECTION` | `products` | The alias searches use |
| `TYPESENSE_TIMEOUT` / `TYPESENSE_CONNECT_TIMEOUT` / `TYPESENSE_IMPORT_TIMEOUT` | `3` / `2` / `60` seconds | Storefront searches / connecting / bulk imports |
| `TYPESENSE_RETRIES` / `TYPESENSE_RETRY_MS` | `2` / `200` | Extra attempts after connection errors, 429 or 5xx (storefront searches retry at most once) |

The local `typesense` Compose service is reachable only inside Docker at `http://typesense:8108`. Any reachable
Typesense works, including a hosted cluster.

**Merchant settings** of the add-on (typo tolerance, minimum query length, synonyms) are stored and shown by the CMS on
**Settings → Search**.

## Turning search on

```sh
docker compose up -d typesense
docker compose exec -T app php artisan cms:addons-sync
```

1. In the administration, open **Add-ons** and enable **Typesense search**.
2. Open **Settings → Search** and click **Build index** (or run `php artisan cms:search-reindex --now`).
3. Restart the worker and scheduler so they load the add-on: `docker compose restart worker scheduler`.

The header search box appears only while a search add-on is enabled.

## What is indexed

One document per product **published as a product**: active, not deleted, with a SKU, a price, a default-language
name and a real (non-system) category.

- **Searchable text:** names in every active language, codes (SKU, MPN, EAN, ISBN, UPC), the brand, the category path,
  and the short description and description as plain text with chosen option labels.
- **Filter, facet and sort fields:** category and brand, the stored catalog price, in-stock flag and availability
  status, approved-review rating, chosen feature options, feature numbers and yes/no features, customer groups and the
  creation time.

Index prices and stock are used only to filter and sort. Every product card shows the **live** price, sale and tax, and
the cart and checkout never read the index.

Layout details:

- each active language gets `name_xx`, `brand_xx`, `category_xx` and `text_xx`; Greek, Cyrillic and CJK use Typesense's
  locale tokenizer (accents, tonos and final sigma are handled);
- features are stored by numeric ID (`opt_{id}`, `num_{id}`), never by merchant text;
- numbers are stored as integers in ten-thousandths, because Typesense floats are 32-bit;
- physical collections are versioned (`{alias}_{fingerprint}_{time}`) behind the alias. Adding a language changes the
  fingerprint, and health reports that a rebuild is needed.

## Keeping the index current

1. **Change capture.** Triggers on products, product translations, customer groups, feature assignments and choices,
   reviews, brands, categories and their translations, feature option translations, availability statuses and the
   inventory-tracking setting queue affected products, only when a column the document uses changes. A stock change
   re-indexes only when stock crosses zero.
2. **Processing.** Every 5 seconds the scheduler queues `ProcessSearchOutbox`. A worker indexes batches of 250 and removes
   products that are no longer published. Each outbox row carries a version, so a change made meanwhile is never lost.
3. **Failures.** Refused documents are retried after 15 s, 30 s, 60 s… up to 1 hour, and marked failed after 8
   attempts. If the engine is unreachable, changes wait 30 s without using up attempts.
4. **Critical changes fail closed.** Unpublishing, moving category, changing customer groups, deleting or emptying the
   name, or stock reaching zero is a *critical* change: until the index confirms it, the product is excluded from every
   search result, count and facet. Above 1,000 pending critical changes (for example during a large import), counts and
   facets are withheld (the *degraded* state) rather than risk counting hidden products.

## Rebuilds and reconciliation

**Full rebuild** (**Settings → Search → Rebuild index**, or `php artisan cms:search-reindex [--now]`):

1. a new collection is created;
2. products are imported in batches as resumable jobs on the `maintenance` queue;
3. changes made meanwhile are written to both collections;
4. the new collection is verified (nothing refused, as many documents as publishable products);
5. only then does the alias switch, atomically.

The previous collection is kept for recovery. A failed or cancelled rebuild drops only its own collection. One rebuild
runs at a time.

**Reconciliation** runs daily at 03:40, from **Settings → Search → Check index against catalog**, or with
`php artisan cms:search-reconcile`: it compares the
index with the catalog and queues missing products, stray documents (hidden immediately) and failed rows.

| Command | Purpose |
| --- | --- |
| `cms:search-sync` | Index queued changes now |
| `cms:search-reindex [--now]` | Full rebuild (queued, or in this process with `--now`) |
| `cms:search-reconcile` | Compare and repair |
| `cms:search-cleanup` | Delete collections other than the live one and the newest previous one (never during a rebuild) |

Nothing runs automatically on start-up, migration or container restart, and the live collection is never deleted
automatically.

## Visibility and security

- Category rules (status, customer groups, **Exclude from search and highlights**) are applied at search time from the
  database, so they take effect immediately without reindexing. Exclusion is inherited down the category tree.
- Every engine request carries the searchable categories, the visitor's customer groups, the pending critical products to
  hide and, when listings hide out-of-stock products, `in_stock:true`.
- Hits are re-read from PostgreSQL through the public catalog before any card is shown. The engine is never the
  authority on what a visitor may see.
- The API key is used only in server-side request headers: never in page props, bundles, logs or messages. The browser
  never talks to Typesense, and there is no public proxy: `/search` and `/search/suggest` accept only validated
  parameters (`q`, `page`, `sort`, `limit` and the listing filters).
- Keep Typesense on a private network; the Compose service publishes no port.

## Production

- Give Typesense persistent storage (the `typesense-data` volume) and a long random API key.
- Monitor its memory: the index lives in RAM.
- Back it up with Typesense snapshots, or simply rebuild from the catalog: losing the search server loses no store data.
- For high availability, run a Typesense cluster and set `TYPESENSE_NODES`.
- To roll back a bad build, point the alias at the kept previous collection
  (`PUT /aliases/{alias}` with `{"collection_name": "<previous>"}`), then reconcile.

**Licensing:** Typesense Server is GPL-3.0 and runs as a separate, unmodified service that Ambresoft Commerce calls over
HTTP. See [THIRD_PARTY.md](https://github.com/ambresoft/ambresoft-commerce-oss/blob/master/THIRD_PARTY.md).

## Writing another search provider

Implement `App\Search\Application\Contract\SearchProvider` in an add-on and declare it under `search-providers` in the
manifest. See the [Add-on reference](../addon-reference/#search-providers).
