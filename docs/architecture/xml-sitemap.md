# XML Sitemap add-on

Authorized scope: Tasks 14–23 and the regression review in Task 29. The existing HTML `/sitemap` remains independent. No crawler-directive file is read, generated, managed or changed by this add-on.

## Persistence and publication plan

`ambresoft/sitemap` uses the existing Composer manifest, AddonManager migrations, enabled-only provider, permission/translation/navigation registries and Laravel scheduler. Installation follows the existing disabled-by-default lifecycle; disabling/uninstalling retains data. No migration runs at provider boot.

The foundation migration creates six PostgreSQL tables:

- `ambresoft_sitemap_sections`: seven section identities, draft enabled state, refresh frequency, successful/attempt/next timestamps, status and safe failure text. Index due work; constrain frequencies/statuses.
- `ambresoft_sitemap_state`: singleton, optimistic draft version, unpublished-draft flag and current published-version foreign key. Manual changes use version checks.
- `ambresoft_sitemap_draft_urls`: section/source unique identity, discovered canonical URL, nullable inclusion and URL overrides, actual content modification time, eligibility and warnings. Removed content stays unavailable so overrides survive rediscovery.
- `ambresoft_sitemap_versions`: immutable version UUID, canonical origin, section-settings snapshot, publication time and included URL count.
- `ambresoft_sitemap_version_urls`: immutable complete draft metadata/overrides per version, including excluded rows, so automation starts from the published selection instead of a manual draft. Composite section/source uniqueness and indexed version foreign key.
- `ambresoft_sitemap_files`: serialized XML per version/name, URL count and measured UTF-8 byte length. Composite version/name identity; enforce protocol ceilings in validation and database checks.

A nonblocking PostgreSQL transaction advisory lock serializes manual/scheduled mutations. A publication builds and validates every immutable URL set, part and index in one transaction, then switches the singleton's published-version pointer. Readers see a complete old or new version. Failure rolls back the candidate version and leaves the old pointer intact. Versioned part URLs continue to resolve their original immutable version.

Manual refresh and Save Draft never update that pointer. Scheduled refresh uses the last published rows/settings and changes only its due section. It never publishes a manual draft. When a manual draft is pending it leaves draft rows/version untouched; otherwise it synchronizes the refreshed section back into the clean draft and increments its version, making a stale browser save fail safely. The first publication is always explicit.

## Core boundary

Core publishes a read-only `StorefrontUrls` contract with canonical, anonymously accessible, indexable content and actual content timestamps. Its adapter reuses storefront publication scopes, URL builders, layout indexing settings, category ancestry, availability and redirects. It does not build sitemap XML or store sitemap state. All section providers, overrides, schedules, admin workflow and publication belong to the add-on.

Discovery uses the configured `app.url` origin and default storefront language: language selection currently uses sessions, not independently canonical language routes. Translated aliases are not invented as separate sitemap URLs. Product variation members keep their real product-page identities; carousel/list grouping does not merge distinct canonical pages.

## Protocol

Each XML URL set is limited by both 50,000 entries and 52,428,800 actual uncompressed bytes. Larger results publish an index and versioned parts together. URL modification times come from content records; index modification times describe the generated sitemap files. No priority/changefreq hints are emitted.

References: [Google sitemap guidance](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap), [sitemap indexes](https://developers.google.com/search/docs/crawling-indexing/sitemaps/large-sitemaps), [protocol](https://www.sitemaps.org/protocol.html).

## Administration and operations

The bundled add-on uses contract 4 and the standard Ambresoft icon. New installations remain disabled until enabled through Add-ons. Enable it, assign `ambresoft.sitemap.view`, `ambresoft.sitemap.manage` and/or `ambresoft.sitemap.publish` deliberately, then open Website → XML Sitemap (`/admin/sitemap`). Super administrators retain their existing permission bypass; no reference-role grant is added.

The overview has persisted per-section switches and hour/day/week/month/year/Never schedules, counts from the latest successful discovery, and refresh/error timestamps. The Draft URLs tab paginates 50 rows at a time. Inclusion is Automatic/Include/Exclude. A URL override must resolve to the current canonical URL of the same source identity on the configured storefront origin; it cannot create a new route or change a slug. Empty overrides follow discovery again. Removed or unsuitable entries remain visible with warnings and cannot force unpublished/private content into XML.

Product and category editors also offer **Additional → Exclude from XML sitemap**, saved with the ordinary catalog Save button. A category exclusion applies to itself, every descendant category and their products. This is inherited during discovery, without rewriting child flags; clearing a parent preserves exclusions explicitly set on individual children. Moving a category changes inherited eligibility at the next refresh. Source-level flags cannot be overridden by Include in the sitemap table. Existing sitemap overrides remain stored and apply again when the source becomes eligible. These preferences affect XML sitemap membership only; they do not hide storefront content or add noindex directives. Their effects follow the existing refresh/publication workflow; saving a catalog edit does not publish XML.

Generate/Refresh Draft queues discovery; Save Draft saves only submitted rows. Neither publishes. Publish requires a separate confirmation and an optimistic version match. Refresh the first draft before publishing. Public `/sitemap.xml` is 404 before the first publication. The previous complete publication survives failures. With one part it returns a URL set; with multiple parts it returns an index of `/sitemap-{version-uuid}-{part}.xml` files. Part files remain immutable and independently accessible for previously published versions. Public GET/HEAD requires no account and supports ETags. The existing HTML `/sitemap` is separate.

The provider registers a minute scheduler callback which only dispatches due sections. A section set to Never does no automatic work; manual refresh/publication still work. A queued section changed to Never is cancelled safely. Scheduling starts only after the first explicit publication. Failed attempts retain the prior successful timestamp and publication and expose a safe error with a five-minute retry opportunity. Dispatches carry generation tokens; stale queued work and overlapping mutations cannot overwrite newer edits.

Keep the existing Redis worker and scheduler running. Restart them after enabling, disabling or upgrading the add-on so long-lived processes load the correct provider/autoloader state. Jobs select a timeout below the existing queue connection's reservation (`retry_after`), capped at 30 minutes. On large catalogs, configure the existing `REDIS_QUEUE_RETRY_AFTER` appropriately and restart workers; do not set an execution timeout longer than the reservation. Publishing is an administrator operation; production PHP/proxy request limits must accommodate publication work. Public XML requests never discover content or generate files.

Versions and XML are stored in PostgreSQL, so Docker containers do not rely on local public files or atomic filesystem renames. Old versions are retained for immutable part links; storage retention/purging is not implemented and must be planned for high-frequency large catalogs. Uninstall retains owned data under the existing lifecycle. Source entities are intentionally not foreign-keyed across mixed content types; deleted content is marked unavailable on refresh instead of blocking catalog deletion.

## Migrations

- `2026_11_27_000100_ambresoft_sitemap_create_sitemap`: creates the six owned tables, singleton/section reference rows, foreign keys, indexes and constraints.
- `2026_11_27_000200_ambresoft_sitemap_retain_invalid_url_diagnostics`: widens discovered URL columns to retain overlong-address warnings and enforces object-shaped section snapshots. It preserves existing data. Narrowing during rollback refuses to truncate long diagnostics.
- Core `2026_11_28_000100_add_catalog_sitemap_exclusions`: adds non-null `exclude_from_sitemap` booleans to categories/products, default false for existing and new records. Catalog owns these preferences and preserves them independently of add-on activation. No descendant backfill or exclusion index is required; discovery already reads category ancestry and scans eligible product identities.

The follow-up is version 1.0.1 because the initial add-on was already installed locally during implementation. Use the existing add-on upgrade operation or `cms:addons-sync` to apply it; do not drop tables or rewrite installed data.

## Discovery details and limits

All seven core content types use actual storefront visibility and URL helpers. Products must be purchasable under current stock/backorder, release-date and quantity rules; every variation retains its distinct real product URL. Hidden, disabled, trashed, restricted, age-gated, noindex, redirected and conflicting URLs are excluded. Brand discovery follows the selected dedicated-brand or legacy feature-option landing-page mode, without introducing another content model.

Content timestamps are taken from actual source updates (including product/category translations). Legacy feature options have no reliable modification timestamp, so lastmod is omitted. Homepage lastmod uses the known homepage/default layout modification timestamps; it does not invent a date for automatically changing block content. Default-language paths use the existing translation fallback. No hreflang or translated aliases are invented for session-selected languages.

A manual publication revalidates saved selections against current eligibility and canonical addresses. If content became unavailable or moved, refresh/correct the draft first. Scheduled refresh validates its refreshed section while retaining other sections' last published state until their own schedules run. Changes to the configured canonical origin require a fresh draft and publication.
