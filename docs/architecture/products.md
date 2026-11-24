# Product administration — Phase 4

## Scope and ownership

Catalog owns products alongside categories. `Catalog/Domain/Model/Product` and `ProductRules` are framework-free; the Application administration port and SaveProductData are consumed by admin HTTP adapters. Eloquent records/mappers, validation, transaction coordination, queries, storage and queue adapters live in Infrastructure. The existing Admin presentation boundary serves Inertia pages, not an API. No variation, inventory, storefront, tax or sale-pricing engine is implemented. Phase 4.1 adds [currencies](currencies.md): prices are amounts in the configured primary currency.

The explicit Phase 4 request supersedes the earlier proposed default-variation model: Product currently owns SKU and basic price. A future variation phase must provide an explicit migration and ownership decision, not create a hidden default variation today.

## Relational model

| Table | Responsibility / integrity |
| --- | --- |
| products | Type, nullable draft SKU/price/category, status, decimal physical measurements, version, actors and soft deletion |
| product_translations | Exact locale-linked name/description/SEO title/description/keywords/slug; unique product+locale and locale+slug |
| product_images | Product-owned images, contiguous positive gallery positions and optional single primary; each media asset belongs to at most one product |
| private_product_files | Generated private storage key, original filename, actual MIME, size and readiness |
| product_files | Product/file associations, editable display name and stable positive ordering |
| product_file_cleanups | Durable transactional cleanup intent with dispatch/retry timestamps |
| product_audits | Actor, timestamp, action and bounded structured context; product ID deliberately survives permanent deletion |

PostgreSQL enforces case-insensitive assigned SKU uniqueness using a partial `lower(sku)` index. NULL drafts do not collide. SKU and localized slugs remain reserved across all statuses and trash. Slugs release only on permanent deletion. Category/media/file foreign keys restrict unsafe deletion; product-specific associations cascade only on physical product deletion. Audit history is not a deletion blocker.

Basic price is `numeric(20,6)`, carried as decimal strings, never floats. Since Phase 4.1 it is denominated in the single primary currency (initially EUR) and accepts that currency's configured decimals (0–6, initially 2), replacing `products.price_scale`. The editor and list show the primary currency through the shared formatter; converted secondary amounts are display-only. Changing the primary currency converts every stored price atomically and bumps product versions ([contract](currencies.md)). Zero is valid. Optional physical measurements are nonnegative `numeric(15,6)`. Weight uses Settings → General’s unit (kg initially) and grams-per-unit factor; factor changes convert saved weights transactionally ([contract](general-settings.md#basic-settings-and-grouped-preferences-a81)). Dimensions use cm. Type changes retain physical fields and private-file associations.

## Drafts, status and completeness

POST creation immediately persists a Disabled draft with NULL SKU, price and category. Abandoning its editor preserves it. Incompleteness is derived from the current default-language name, trimmed assigned SKU, nonnegative price and valid active leaf category; it is independent of Disabled/Hidden status. Normal save requires that complete set. Active additionally requires the default-language slug and a private downloadable file for Digital products. Descriptions and images are optional. Every activation path revalidates current persisted state.

All normal statuses appear in the ordinary list; trash is a separate query. Future storefront consumers must explicitly exclude trash, Disabled and Hidden from ordinary catalog browsing; no storefront query/API is published now. An incomplete draft may be Hidden, but not Active.

Category disable or a system-default language change synchronously disables newly ineligible Active products under the same transaction and bumps their versions. Renaming/reordering a category leaves product status/assignment unchanged.

## Localization, slugs and HTML

Product editors deliberately differ from strict Category editors: only the configured default-language name is required. Other translations may remain absent; all descriptions are optional. Tabs use active locale records, not a hardcoded language list. Existing inactive-language content is retained. Missing editor content stays empty and is labelled; list/future display fallback uses the default language without copying data.

Slugs normalize through Laravel transliteration into lowercase hyphenated ASCII tokens. A new named translation generates a slug if missing. Only generated/regenerated slugs receive collision suffixes. Manual conflicts are errors. Existing slugs survive ordinary name changes; an explicit regeneration control permits URL changes. Uniqueness includes trashed translations in the same locale.

Descriptions use [Symfony HTML Sanitizer](https://symfony.com/doc/7.4/html_sanitizer.html) server-side, with an explicit allowlist: p, br, strong/b, em/i, u, s, ul/ol/li, blockquote, h2/h3, pre/code and a[href,title]. Link schemes are http/https/mailto. Scripts, event attributes and arbitrary unsafe HTML are not retained. Product names remain plain text. The small rich-text editor is not a page builder.

## Transactions and concurrency

Product operations acquire the existing Catalog advisory transaction lock (`2142513`) then the product row lock. The lock coordinates category structure, category CSV operations and language changes. Every editor/lifecycle mutation compares `lock_version`; no field merging occurs. Successful field, translation, association, status, category recovery, trash and restore writes increment it. A stale save returns a validation conflict instructing refresh and writes nothing.

One product has one active normal leaf category; no path is duplicated. CategoryQueries resolves ancestors from the tree. Creating/moving a child under a product-assigned leaf is blocked (including trashed assignments). Category deletion invokes the existing synchronous mutation participant: all affected products, including trashed ones, move to protected Uncategorized and become Disabled in the deletion transaction. Restore similarly recovers unavailable categories to Uncategorized. That recovery remains incomplete until assigned a valid normal leaf.

## Product images and private files

Product images reuse the proven `media_files` storage metadata and `PrivateImageStorage`, but they are exclusive product assets rather than a shared picker/library. The editor can upload, order, remove and choose an optional primary image only from that product's gallery. Server validation accepts only current product associations plus IDs created by the same upload request; PostgreSQL uniquely constrains each media asset to one product. Duplicating a product creates independent physical image copies. Removing an image or permanently deleting its product removes the owned media record and deletes its bytes after transaction commit; trash retains images. A failed save compensates newly uploaded image records and bytes. Category images remain separate existing media references.

Digital uploads are separate, on the private local disk, with generated `product-files/{uuid}` keys. Browser props contain association IDs and filenames, never storage paths or public URLs. Defaults permit PDF/ZIP/TXT, maximum 10 MiB each; actual file MIME/signature, extension, size and safe original/display filenames are checked server-side. This is not malware scanning and no customer download route exists.

Upload tracking and a one-hour expiry intent commit before bytes are written. Byte writes hold the private-file row lock, then mark readiness. An interrupted upload is tracked and eventually collectible. HTTP requests cannot provide arbitrary private-file IDs: only server-staged IDs may create associations. Replacement preserves the existing display name and order. Removing/replacing associations schedules cleanup; Active Digital validation rolls back removal of its last file. Trashing retains all references; duplication copies none of the private files.

### Durable queued cleanup

1. Detach/purge registers `product_file_cleanups` in the same database transaction. Registration failure rolls back the product operation.
2. The scheduler runs `cms:product-files-cleanup` each minute. It dispatches committed intents to Redis `maintenance`; queue failure leaves durable intent for retry.
3. CleanupProductFile locks the private-file row, rechecks all references and holds that lock through physical deletion and metadata removal. Association creation takes the same lock; restrictive FKs backstop concurrent inserts. Referenced files are retained and their old intent cancelled.
4. Missing files/duplicate jobs are safe. Storage errors throw and retain intent; job retries and failed_jobs expose failures. Unacknowledged intents can dispatch again after 30 minutes. Successful product deletion is never undone by a later storage failure.

The outbox is deliberately file-specific, not a generic event delivery framework. Never replace it with inline physical deletion or a check-then-delete sequence outside the reference lock.

## Authorization and lifecycle

| Capability | Operations |
| --- | --- |
| products.view | Normal list; also checked for duplication |
| products.create | Draft creation and duplication |
| products.update | Editor/full save, image and digital-file association changes |
| products.status | Status transitions, including changes within full save, and bulk status |
| products.delete | Move to trash |
| products.trash-view | Trash listing; required with force-delete |
| products.restore | Restore as Disabled |
| products.force-delete | Permanent deletion from Trash only |

Existing persisted staff/RBAC gates are authoritative. Register new capabilities with `cms:permissions-sync`; do not automatically widen existing roles. Super-admin behavior is inherited. Media delivery requires a product view/update/trash capability.

Duplication copies type, translations (new generated unique slugs), price, physical fields, valid category and shared gallery. It starts Disabled, incomplete, SKU NULL, without private files. Restore preserves reserved identifiers and data, checks SKU conflicts, becomes Disabled and re-evaluates completeness.

Permanent deletion requires the current version, exact displayed default-language product name (localized Untitled fallback for unnamed drafts), Trash state, permissions and `ProductDeletionGuards`. Future order/refund/payment/inventory modules must register blockers through that consumed application service and coordinate reference creation with the product lock/FKs. Those modules do not exist now. Audit history alone does not block deletion.

## Admin and operations

The list searches SKU/all translated names, filters type/status/category/completeness, safely sorts supported columns and paginates 25/50/100 (default 50, updated descending). Editor links carry an allowlisted local list URL including query state. Five persistent editor tabs share sticky save/status controls; errors have field messages, summary and tab marks without automatic switching. Explicit saves stay in the editor. Navigation warns about discarded edits.

Bulk Activate/Disable/Hide accepts at most 100 selected current-page IDs per request, evaluates each under its own fresh transaction/authorization and returns individual outcomes. No bulk trash/category assignment is implemented. Audits record draft/completion/edit/status/category/duplicate/trash/restore/purge and bulk outcomes without logging rich text or file bytes.

## Verification and operational limits

ProductTest covers persistence, HTTP flows, validation, localization, sanitation, permissions, media/files, lifecycle, audits and regressions. ProductFileConcurrencyTest uses two real PostgreSQL connections to exercise reference creation versus cleanup locks. Products.test.tsx covers tab/language state, type switching and unsaved-navigation protection. Existing authentication, authorization, localization, category and import tests must continue passing.

Verified 2026-10-09: `docker compose exec -T app composer check` passed 182 backend tests / 12,796 assertions (including earlier-phase regression tests), Pint and PHPStan. Final targeted `php artisan test --filter=Product` passed 26 tests / 396 assertions after the product-owned image refinement. `pnpm check` passed TypeScript, ESLint, Prettier, 31 Vitest tests and production build. Composer strict validation and dependency audit passed. Both additive Product migrations and permission sync succeeded locally; worker/scheduler are running and the Greek product list/editor were reviewed in the browser. No demo products were created by automated verification.

Security review: web middleware retains CSRF/staff/session enforcement; separate gates protect every mutation and bulk target; request fields/sorts/return URLs are allowlisted; shared props exclude private paths; rich text is sanitized server-side; uploaded names never become storage paths; SKU/slug races have database constraints; stale versions and category changes are transactional; exact-name purge and reference guards precede deletion; durable cleanup registration failures roll back deletion while later storage failures remain retryable. File-lock tests exercise both race directions against PostgreSQL.

Run worker and scheduler continuously (see development.md); monitor pending cleanup intents and failed_jobs. Per-request gallery/file associations are limited to 50, new uploads to 10 per field; local PHP POST limit is 22 MiB total, so send large galleries/files across successive saves. Queue/storage availability, production malware scanning policy and audit retention are operational choices requiring deployment configuration; review the primary currency and its precision before entering production prices. Future variation migration and protected commerce references remain separate phases, not placeholders.

## Optional EAN and returnable metadata

The authorized 2026-10-10 follow-up adds `products.ean` (nullable varchar(13)) and `products.returnable` (non-null boolean, default false). Existing records retain all other fields and begin with no EAN and returnable=false. EAN is an external trade-item barcode identifier, distinct from the internal unique SKU. Accept EAN-8 and EAN-13 ASCII digits with the GS1 modulo-10 check digit; store strings to retain leading zeros. Blank input clears to NULL; EAN is not a completeness/activation requirement, is not generated, and is not unique across CMS records. PostgreSQL and server validation enforce the format/checksum. Validation does not verify GS1 registration or ownership. Existing product text search also checks EAN, without adding list columns or dedicated filters.

Both shared, non-localized fields are edited in General under existing product permissions, transaction and optimistic version checks. Omitted fields preserve saved values for older clients. Changes record before/after metadata in product audits. Duplication clears EAN alongside SKU and copies returnable. Returnable persists an administrator setting only; no returns/refund/storefront workflow is implemented.

Apply the additive migration with `docker compose exec -T app php artisan migrate`. Rolling back this migration removes only these two columns and its owned EAN validation function, discarding their new values. Migration round-trip tests run only against the isolated test database.

EAN reference: [GS1 check-digit calculation](https://www.gs1.org/services/how-calculate-check-digit-manually).

Verification for this follow-up: `make check` passed with 237 backend tests / 20,103 assertions and 64 frontend tests, including Pint, PHPStan, TypeScript, ESLint, Prettier and the production build. The additive local migration is applied. Browser review verified the Greek General tab and search label, with no warning/error console messages. No existing product metadata values were changed during browser review.

## Optional manufacturer part number

The authorized MPN follow-up adds nullable `products.mpn` (varchar 200), shared across languages and independent of product completeness. Saves trim surrounding whitespace and store blank values as null; preserve case, leading zeros and manufacturer punctuation. Control characters and non-string/overlong input are rejected. MPN is not globally unique: multiple CMS records may represent the same manufacturer part. Existing saves that omit it preserve the stored value; product duplication copies it. Changes use existing product permissions, optimistic concurrency, transactions and metadata audit events. No new list column or search behavior is introduced.

Migration `2026_10_10_001200_add_product_mpn.php` is additive and leaves existing products with null MPN. Run `docker compose exec -T app php artisan migrate`. General displays MPN to the left of Type using the same equal-width responsive two-column grid as SKU/EAN; both rows stack on small screens.

Read-only Chrome review confirmed equal 501px desktop widths for SKU, EAN, MPN and Type. At a 375px viewport, MPN and Type stack with equal 301px widths and no horizontal page overflow. No console warnings/errors were captured and no product was saved during review.

MPN verification: `make backend` passed Pint, PHPStan and 249 backend tests (20,570 assertions); `pnpm check` passed TypeScript, ESLint, Prettier, 68 frontend tests and the production build. MPN tests cover persistence/projection, blank/omitted input, formatting, duplication, metadata audits, invalid types/length/control characters, stale-write rollback and permission denial.

## Optional ISBN and UPC

The General tab places ISBN and UPC in one responsive row below MPN and Type. Neither field is required for completeness or activation. EAN, ISBN and UPC explanations appear in label tooltips accessible on hover or keyboard focus. Nullable string columns preserve leading zeros; omitted fields preserve saved values and blank input clears them. ISBN accepts ISBN-10 (including a final X) and ISBN-13 with a 978/979 prefix. Spaces and hyphens are removed and X is uppercased before storage. UPC accepts 12-digit UPC-A. Server validation and PostgreSQL constraints check their format and check digit; this does not verify registration. Both fields use existing product permissions, version checks, transactions and metadata/central audit capture. ISBN is copied on duplication; UPC is cleared alongside EAN and SKU. No uniqueness, generated identifiers or search changes are introduced.

Migration `2026_10_16_000200_add_product_isbn_and_upc.php` is additive, giving existing products null values. Apply with `docker compose exec -T app php artisan migrate`. Its rollback removes these fields and its ISBN validation function, and restores the prior product audit trigger.

References: [International ISBN Agency](https://www.isbn-international.org/content/what-isbn/10), [GS1 US barcode types](https://www.gs1us.org/upcs-barcodes-prefixes/barcode-types) and [check digit calculator](https://www.gs1us.org/tools/check-digit-calculator).

## Variation Groups (Phase 6)

Products may belong to zero or one Variation Group without changing ownership of any product field. Saves, activation, Trash, restore and Duplicate respect group rules (type locked while grouped, Trash detaches, Duplicate and restore stay standalone). The Products list adds a Variation group column and Grouped/Standalone filter. See [variations.md](variations.md).

## Simple product stock (Phase 7)

Migration `2026_10_11_001600_add_product_stock_and_ordering.php` adds to `products`: `quantity` (integer, NOT NULL, default 0), `list_price` (numeric(20,6), nullable), `min_qty`, `max_qty`, `qty_step` (nullable positive integers) and `out_of_stock_actions` (`N` | `B`; migration 001700 makes it nullable, where NULL follows [Settings → General](general-settings.md)). One CHECK `product_stock_ordering` enforces non-negative stock, non-negative list price, positive limits, `max_qty >= min_qty` and the action set. `price` and `sku` are unchanged. The migration is reversible; its `down()` drops the columns (discarding values) and restores the earlier audit capture.

**Existing data.** Every existing product receives `quantity = 0`, NULL list price and limits, and action `N` (converted to "follow the general setting" by migration 001700; effective behavior is unchanged). No stock is invented (the repository held no earlier stock value) and `price` is never copied into `list_price`.

**Validation (server, every create/edit path).** `ProductInput` rules apply to the editor save and any service caller: `quantity` integer 0–2,147,483,647 (fractions and text rejected); `list_price` non-negative decimal at the primary currency's precision; limits positive integers; maximum not below minimum; action NULL/blank (follow the general setting) or `N|B`. Omitted fields keep saved values (older clients); blank optional numbers become NULL. There is no import path for these fields (category CSV only).

**Rules defined in `ProductRules`** (pure, unit-tested): unset limits never restrict; valid order quantities are ≥ 1, ≥ min, ≤ max and, with a step, min + whole steps (whole multiples of the step when no minimum). `stockAllows` blocks requests above stock unless the action is `B`; backorders never bypass min/max/step. Reaching zero stock never changes status or visibility.

**Behavior of related operations.** Duplicate (and Create variation) copies list price, limits and action but resets `quantity` to 0. Variations are independent products with independent quantities. Changes use `products.update`; audits record changed fields (product audit metadata and the central ledger).

**Admin.** The product editor's Pricing & inventory tab shows price, list price, stock quantity, minimum/maximum order quantity, quantity step and the out-of-stock action with errors beside fields. The product list adds a Stock column (0 is marked out of stock, informationally), a Stock sort, and shows `list_price` struck through beside the price only when it is above the selling price.

**Limitations.** No storefront, cart, checkout or notification capability exists. Therefore `N`/`B` and the quantity rules are persisted and tested as domain rules but not enforced by a purchase flow; "Sign up for notification" (`S`) is not offered. No stock history, adjustments, reservations or warehouses exist by design (A67).

## Taxes (Phase 8)

The Pricing tab's **Taxes** control assigns zero or more taxes to the product through `product_taxes`. Saving replaces the set inside the product's own transaction and under its optimistic version: `tax_ids` (with `taxes_submitted` marking an intentionally empty list, because multipart encoding drops empty arrays) must reference existing taxes; a disabled tax may stay assigned but cannot be newly assigned. Omitting both keeps the assignments (older callers). Duplicate and Create variation copy the assignments to the new, independent product; variations assign taxes independently. Removing an assignment never deletes the tax. Stored prices are never altered by tax assignment or calculation. [Contract](taxes.md).

## Shipping, release date, packaging and customer groups

Migration `2026_10_14_000700_add_catalog_shipping_and_visibility.php` adds to `products`: `free_shipping` (boolean, default false), `shipping_cost` (numeric(20,6), nullable, primary currency at its precision, like `price`; never float), `available_from` (date, nullable), `box_min_items` / `box_max_items` (nullable positive integers) and `box_length` / `box_width` / `box_height` (numeric(15,6), nullable, in the configured dimension unit). It also adds `product_customer_groups (product_id, group_id)`. CHECK `product_shipping_packaging` enforces a non-negative shipping cost, positive item counts, `box_max_items >= box_min_items` and, whenever either count is set, all three box dimensions above zero. Existing products get the defaults (no free shipping, no cost, no release date, no packaging, no restriction). `down()` drops the columns/table and restores the earlier audit capture.

| Requested field | Implementation |
| --- | --- |
| Free shipping toggle | `free_shipping`; when ticked no shipping charge applies, including `shipping_cost` (the editor disables the cost field but keeps its value) |
| Product-specific shipping cost | `shipping_cost`, e.g. `2.00` |
| Available quantity | Already stored as `quantity` (Simple product stock above); not duplicated |
| Available from (release/pre-order) | `available_from` date; empty means available now |
| Items per box (min/max) | `box_min_items` / `box_max_items`; empty or 0 is stored as NULL and disables packaging (`ProductRules::packagingEnabled`) |
| Box dimensions | `box_length` / `box_width` / `box_height`; separate from the product's own dimensions and required (> 0) only when packaging is enabled |
| User group visibility | `product_customer_groups`, same semantics as Pages: no rows means every visitor, otherwise members of at least one listed group |

**Validation.** `ProductInput` validates every save path. Packaging is checked against the resulting record (`assertPackaging`), so a partial request cannot enable a count without dimensions. Customer groups are submitted like taxes (`customer_groups` plus the `customer_groups_submitted` marker, because multipart encoding drops an empty list); omitting both keeps the restriction. Groups must exist; a disabled group may stay assigned but cannot be newly assigned. Writes are diff-based, recorded in `metadata_changed` audits and the central ledger. Duplicate and Create variation copy all of these fields and the restriction as is.

**Group deletion.** The `group_id` foreign keys of `product_customer_groups` and `category_customer_groups` are restrictive: deleting a customer group that still restricts catalog content is refused with a localized error instead of silently making that content visible to everyone. (Pages and banners keep their earlier cascading behavior.)

**Admin.** The editor gains a **Shipping** tab (free shipping, shipping cost, box packaging; a note for digital products) and an **Attachments** tab (see below); the former "Media & files" tab is now **Images**. "Available from" and the customer-group picker form an **Availability** section at the end of Pricing & inventory, keeping Returnable last in General.

**Limitations.** No storefront, cart, checkout or shipping calculation exists, so these values are stored, validated and audited but not yet enforced. The storefront phase must: hide restricted products from non-members on the server (direct URLs included); refuse purchase before `available_from` (or offer pre-order under an explicit policy); and let shipping calculation consume free shipping, shipping cost and packaging. Whether a product inherits its category's customer-group restriction is an open storefront decision (see [categories](categories.md)).

## Attachments and buyer downloads

Migration `2026_10_14_000800_add_product_attachments_and_price_history.php` adds `product_files.purpose` (`download` | `attachment`, CHECK-constrained; existing rows were digital files and become `download`).

| Purpose | Meaning | Product types |
| --- | --- | --- |
| download | Delivered to customers who buy a digital product; never shown publicly | Digital only; an active digital product needs at least one |
| attachment | Product document shown with the product (size chart, book sample, manual) | Every type |

Both use the same private storage, validation, cleanup and replacement mechanics described above; neither is on a public disk. The allowed types now also include JPEG/PNG/WebP (checked by content signature) so size charts can be images. Each new upload carries its purpose (`file_upload_purposes`, aligned with `file_uploads`); without one the product type decides (download for digital, attachment for physical). An explicit download on a non-digital product is refused. Existing files and their replacements keep their purpose. Downloads already on a product that becomes physical are kept but hidden and are not delivered. The **Attachments** tab shows "Downloads for buyers" (digital products only) and "Attachments" (always); reordering stays within each section.

The product page lists attachments as document cards and serves them through `GET /storefront/products/{product}/documents/{file}` (`ProductDocumentController`): only `attachment` files of that product, only while the product is visible to the visitor (status, deletion, completeness, category and customer groups via `PublicCatalog::products()`), never storage keys. PDFs and pictures open inline under `Content-Security-Policy: sandbox` with `nosniff`; other types download. Buyer downloads (`download`) are never served there and still wait for order entitlement. Attachment display names are a single merchant text, like download names; per-language names remain a later decision.

## Price history

The same migration adds `product_price_history (product_id, price, list_price, recorded_at, actor_id)` with a cascade from the product and non-negative CHECKs. `ProductPriceHistory::record` runs inside every product save (and for the new product created by Duplicate / Create variation) and appends a row only when the saved price/list-price pair differs from the latest row, so unrelated edits add nothing and a failed save leaves nothing. Drafts without a price have no history. Primary-currency changes convert the rows instead of recording a change (see [currencies](currencies.md)), so the history always reads in the current primary currency.

The migration backfills existing products from the central audit ledger, which already held `price`/`list_price` before/after values; entries from before the latest primary-currency change are skipped (they are in another currency) and every priced product ends with its current price. Pruned ledger entries cannot be recovered, so older history is best effort.

The editor's **Price history** tab draws a step chart (the value holds until the next change) of the price or list price with 1-month, 3-month, 6-month and All periods, a hover crosshair and tooltip, the current value labelled at the end, and a table of every change (newest first, up to the latest 2,000 rows). Chart amounts are display numbers; the stored decimals stay authoritative. The history is the basis for later storefront needs such as showing the lowest price of the previous 30 days next to a reduction (EU Omnibus rules); that display is not implemented.

## Product sales

The Sale tab configures fixed sale prices or percentage reductions, customer groups, minimum quantities, priorities, dates and optional discounted-stock quotas. It uses the product editor's existing save and permission contract; ordinary order pricing consumes these rules before taxes. [Sale pricing, stock allocations and lifecycle](product-sales.md).

## Short description, similar products and frequently bought together (A104)

**Short description.** `product_translations.short_description` is an optional, translated, sanitized rich text (at most 5,000 characters, CHECK and validation) beside the full description in the General tab. It follows the existing translation rules: saved per active language, copied by duplication, removed with the product. Storefront product cards carry it with the usual language fallback (current, default, English).

**Similar products (automatic).** `products.similar_products` (default on) is the only stored setting, in the editor's *Similar products* tab. `Catalog\Infrastructure\SimilarProducts::ids()` computes the list live in one SQL statement, so it follows every catalog change without stored lists or jobs:

- candidates are only products of the **same category** (a product sits in one leaf category, so sibling or parent categories are never mixed in; a product without a category gets none);
- ranking inside the category: each shared option of a single/multiple-choice feature 3; each equal yes/no value or number within 10% 1 (active, applicable features; free text is not compared); same product type 1.

Candidates must pass `PublicCatalog::listed()` (active, not deleted, complete, visible category and customer groups, the out-of-stock listing rule). The product itself is excluded, ties prefer the closest price, and at most 12 are returned. A product alone in its category gets no recommendations rather than filler from elsewhere. The query count does not grow with the catalog (one product read, the visibility scope's settings, one scoring query), and it uses the existing indexes on categories, feature choices and assignments.

**Frequently bought together (manual).** `product_companions(product_id, companion_id, position)` keeps up to three companions per product: primary key on the pair (no duplicates), unique position, CHECK `product_id <> companion_id AND position BETWEEN 1 AND 3`, both keys cascading on permanent deletion, audited. The *Frequently bought together* tab searches products server-side and shows each choice's picture, SKU, current price and an *unavailable* marker. `ProductCompanions::sync()` refuses more than three, duplicates and the product itself; new choices must be active, not-deleted products, while a companion saved earlier that later became unavailable is kept so saving the main product never breaks. Duplicating a product copies its companions.

**Storefront read model.** `Storefront\Infrastructure\ProductRecommendations` returns the sections as standard product cards (`BlockContent::productCards()`, the same cards, prices, tax display and stickers as the product blocks): `similar()` (null when nothing qualifies) and `boughtTogether()` (null without a visible companion). The bundle lists the main product and its visible companions; each item says whether it is *included*: only items that can be bought now and show a price. The total is the live sum of included items at their smallest order quantity, recalculated on every read, and null when prices are hidden. Items carry the product id and quantity, so a later *Add all to cart* can submit them unchanged. There is no bundle discount and no stored total.

**Not included.** The storefront product page is a later deliverable: it will render `similar()` with the existing product carousel and `boughtTogether()` as its own section, each only when present. Adding the whole bundle to the cart, bundle pricing and a generic bundle builder are out of scope.

