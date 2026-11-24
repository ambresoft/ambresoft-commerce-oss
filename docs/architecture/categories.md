# Categories — Phase 3 contract

Category management is the only catalog feature authorized here. Catalog owns categories; Admin delivers browser/Inertia screens. Domain/Application stay framework-free. Products, memberships and storefront routes remain absent.

Phase 3A adds [category CSV import](category-import.md): explicit current-field mapping, read-only preview, ID-based partial updates and default-locale hierarchy resolution. Confirmation reuses normal category services, constraints, positions and events inside one transaction. No category redesign or Product implementation is introduced.

## Tree and ordering

Use adjacency (`parent_id`) with indexed PostgreSQL recursive CTEs for ordered ancestors, descendants and leaf detection. No fixed depth, nested-set renumbering, materialized path, Redis tree or path copied onto products. Reparenting changes one parent reference; descendants resolve their new ancestors immediately. This favors simpler writes over precomputed read paths; measure real catalog sizes before adding a derived projection. See [PostgreSQL recursive queries](https://www.postgresql.org/docs/current/queries-with.html).

Normal positions are positive integers unique per sibling set, including roots. Gaps are preserved. Inserting/moving into an occupied position shifts subsequent siblings upward in the same transaction; deletion leaves a gap. A deferrable `UNIQUE NULLS NOT DISTINCT(parent_id, position)` allows atomic shifts without temporary collisions. System Uncategorized is an immutable root at position zero, outside normal ordering. Position overflow is rejected, never wrapped. No drag-and-drop or alphabetical canonical order.

All supported mutations acquire transaction-scoped advisory lock 2142513 before reading the tree and recheck actor capabilities. PostgreSQL parent FK/row checks and triggers additionally reject cycles, system-parent use and system mutation/deletion. Child FK deletion is restrictive. Optimistic `lock_version` rejects stale forms; shifted siblings also advance their version. Normal category removal is hard deletion of a leaf plus owned translation rows, not blanket soft deletion. There is no published storefront/history to retain yet; redirect/retention policy is required before publication.

Bulk deletion in the tree is limited to visible, normal leaf subcategories (not roots), at most 40 per request. Selection clears on branch navigation/collapse so the submitted scope remains understandable. The server independently requires `categories.delete`, rechecks each ID, version and child count under the tree lock, then invokes the same deletion participants and post-commit events as individual deletion. One transaction covers the entire batch; any stale/protected/invalid target or participant failure rolls back every deletion. Parent and system-category deletion remain unavailable through this route.

## Content and system category

`category_translations` owns name, sanitized rich-text description, SEO title/description, slug and image alt text, linked to Category and Locale. Unique category/locale and locale/slug constraints apply. Slugs normalize to lowercase ASCII hyphenated words; explicit inputs are normalized, blank slugs derive from the submitted name. Greek transliteration uses Laravel's slug facility. Resource identity is the category ID, not slug or path. Future URLs use `/locale/categories/slug`; no storefront route exists now. Locale-aware redirect history must precede publication.

### Requested metadata and age-settings follow-up

| Requested field | Implementation |
| --- | --- |
| Image | Existing image_media_id/private-image workflow; no duplicate image column |
| Meta description | Existing localized seo_description, labeled Meta description |
| Page title | Existing localized seo_title, labeled Page title |
| SEO name | Existing localized slug, labeled SEO name (slug) |
| Meta keywords | New localized meta_keywords, optional text up to 1,000 characters |
| Age warning message | New localized age_warning_message, optional plain text up to 2,000 characters |
| Age verification | New non-translatable age_verification boolean, false by default |
| Age limit | New nullable positive integer age_limit in years; required when enabled |
| Parent age verification / limit | Read-only derived effective settings of the selected parent and all ancestors; never copied into child columns |

The additive 000400 migration backfills safe defaults without changing existing content, images, IDs, grants or credentials. Down drops only these new fields/checks and therefore loses their values; never roll it back against merchant data casually. Category/locale omission preserves existing rows; legacy translation submissions omitting the new optional fields also preserve them. Explicit empty text clears only that supplied field/language. Existing own age settings remain unchanged if the settings pair is omitted; the current form sends the pair explicitly.

Own enabled restrictions combine with ancestor restrictions using the **highest minimum age**. A disabled own toggle cannot weaken an ancestor restriction; an inactive stored own age limit does not contribute. CategoryAgeRestrictions resolves a root-through-category path without framework dependencies; ties select the closest enforcing category as the warning source. The parent projection uses the path through the selected parent, excluding the current category. A move or ancestor edit changes the derived result immediately, without descendant copies or mass updates. The editor refreshes inherited values through an authorized Inertia partial response and does not show stale values while another parent is loading.

This is configuration and domain resolution, **not implemented customer age verification**. No birthdate collection, visitor gate, storefront route or checkout behavior is added. Future storefront access must enforce the effective restriction on the server and use the enforcing category's localized warning (or an explicitly chosen generic localized warning when empty). Verification mechanism, age-proof/session/privacy policy and direct Product access enforcement remain storefront/Product-phase decisions; metadata alone is not an enforcement mechanism.

CS-Cart documents image, page title, meta description/keywords, SEO name, and age toggle/limit/warning semantics in its [category properties](https://docs.cs-cart.com/latest/user_guide/manage_products/categories/category_attributes.html). Preserve those conceptual meanings, reuse our existing SEO/media ownership, and modernize age inheritance as the explicit rule above. CS-Cart packages age verification as an addon; this user-authorized follow-up adds category-owned configuration only, not a copied addon/schema or full verification subsystem. Its documentation does not establish our ancestor-combination policy.

The language-management follow-up A42 governs dashboard editing. With **two or more active catalog languages**, require the six required localized fields in every active language on normal saves, including core-only edits. The authorized correction keeps descriptions and age warning messages optional in every language, including image-only saves and age-restricted categories. Validate the resulting entity by combining exact persisted rows with the submitted patch; never write a fallback copy. A43 permits partial CSV imports with any active language and preserves missing translations as empty tabs for later completion. Blank slugs still derive normally. With **one active language**, expose only that language and retain name/slug requirements plus existing optional-field semantics. Inactive content is retained. Activating a language does not fabricate/backfill content; incomplete imported/existing categories must be completed before a normal editor save. Tabs retain independent unsaved edits. Read-only list fallback remains requested → system default → English; a category imported only in another language can be found by ID or by switching the admin locale. No storefront publication is implemented. See [language management](language-management.md).

Migration reference seeding creates exactly one `system_key=uncategorized` record, disabled, parentless and image-free. Its display name comes from keyed UI catalogs (`categories.uncategorized`), not mutable merchant translations. Normal editing, deletion, parenting and catalog assignment exclude it. It remains visible to staff as a protected recovery category. Future storefront visibility requires a normal active category and active normal ancestors; a system category is never navigational.

## Deletion and future products

The synchronous Category mutation participant contract executes inside the category SQL transaction under the tree lock. A before-delete payload supplies removed category ID, Uncategorized ID and actor ID. Future Product implementation must reassign its affected last/primary memberships to Uncategorized before category removal, preserve products/other memberships, and use restrictive category FKs. Failure aborts the whole operation. `CategoryDeleted` is a post-commit fact, **not** the reassignment mechanism. A before-parent-use callback lets the future Product domain prevent an assigned leaf from becoming a parent (or implement a separately approved transactional reassignment policy).

Normal future product assignments must target normal leaves; disabled-state assignment/publishing and single-versus-multiple leaf membership remain Product-phase decisions. Product operations must coordinate with the same category transaction lock when checking leafness/mutating memberships. Uncategorized is available only through recovery operations. Path/reporting/aggregation use ancestor/descendant IDs from the category tree, never duplicated product hierarchy.

Participants register unique namespaced IDs with deterministic priority/ID order. They may veto or perform SQL work in the same connection/transaction, never queued work or irreversible remote effects. After commit, pure typed CategoryCreated/Updated/Moved/Deleted facts carry IDs, actor and occurrence time, not Eloquent graphs. Optional listener failure cannot undo a committed category change; no outbox or exactly-once guarantee is claimed.

## Media and admin

Use the shared private Laravel storage capability and relational media metadata. Accept only validated JPEG/PNG/WebP, up to 5 MiB and 6000×6000 pixels. Generate storage keys; never trust uploaded paths/extensions, accept SVG or fetch remote URLs. One category image has localized alt text; replacing/removing it changes the explicit reference. Failed writes compensate new private uploads. Detached originals are retained privately pending a documented retention/purge workflow; no public disk, media browser or derivative engine. Authorized category-scoped delivery exposes neither disk keys nor a general file endpoint.

The primary UI is a lazy, paginated expandable tree using the same Inertia browser index route and partial props. Load only the requested sibling page, with aggregate child counts and bounded fallback translations; never a per-row query or entire unbounded tree. Search/status filters produce a paginated result list with ancestor paths in the current admin locale/fallback. Search uses PostgreSQL bound ILIKE, not external search. Tree expansion is UI state, not an API-first layer. Parent selection excludes the current subtree/system category and uses bounded search instead of loading every category.

Existing categories.view/create/update/delete gates protect each request and are rechecked by the mutation adapter. System restrictions apply even to super administrators. English/Greek UI strings use Laravel catalogs. The authorized rich-text follow-up uses the shared Visual/HTML editor and server sanitization policy for descriptions, including CSV imports; see [rich-text controls](rich-text.md).

## Open before later phases

Product membership cardinality/publication policy; storefront delivery/derivatives; media retention/purge; localized redirect history and URL publication; durable category audit retention. None authorizes implementation in Phase 3.

## Implemented persistence and entry points

| Record | Persistent information | Integrity |
| --- | --- | --- |
| categories | id, parent_id, position, status, system_key, image_media_id, lock_version, age_verification, age_limit, exclude_from_discovery, timestamps | Restrictive parent/media FKs, unique image/system identity, sibling uniqueness including roots, age/row checks and cycle/system triggers |
| category_translations | id, category_id, locale_id, name, description, seo_title, seo_description, slug, image_alt, meta_keywords, age_warning_message, timestamps | Unique category/locale and locale/slug, owned-row cascade, restrictive locale FK, nonblank name, normalized slug and warning-length checks |
| media_files | id, disk, storage_key, mime_type, byte_size, width, height, timestamps | Unique generated key, MIME/size/dimension checks; private storage only |

GET /admin/categories, GET /admin/categories/create, POST /admin/categories, GET /admin/categories/{category}/edit, PUT/PATCH /admin/categories/{category}, DELETE /admin/categories/{category}, and authorized GET /admin/categories/{category}/image are browser routes under the existing active/verified staff boundary. No API/category storefront route. Each submitted translation row is a full locale edit of the original fields (optional omitted original text normalizes to empty); omitted locale rows are unchanged, including multipart core/image-only edits. The new metadata fields preserve existing values when omitted for backwards compatibility, as specified above.

## Phase 3 review and verification

Baseline was a clean Phase 2 repository with 88 backend/13 frontend tests and no Catalog/media records. New directories/types are consumed by actual category operations; pure layers remain inward, Admin is presentation, Media is shared infrastructure. No repository/CQRS/hook engine, materialized path or external search service. SQL lock ordering and transaction participants preserve a path for future Product leaf/reassignment invariants without implementing Products now.

Security review: all browser routes retain session/verification/staff middleware; granular gates are rechecked in mutation adapters. CSRF remains enabled; validated explicit fields prevent system-key/media-ID mass assignment. Version checks reject lost updates. Bound queries, escaped plain-text React output and keyed localized validation prevent SQL/XSS shortcuts. Uploads use MIME/dimension/size validation, generated private keys and authorized category delivery with nosniff/no-store; SVG/remote imports and client paths are rejected. Transaction failure compensates new files without masking the original exception; detached private originals are deliberately retained, not silently purged.

Verification includes PostgreSQL constraints/cycles, 20 nested levels, sibling collision/moves, independent en/el and additional locale rows, exact/fallback distinction, stale edits, participant veto/SQL rollback, cross-session advisory lock contention, outer-commit event timing/rollback, media compensation, multipart edits, permission/CSRF and additive migration up/down/up in cms_test. Existing authentication/RBAC/localization regressions remain covered. Vitest exercises lazy tree expansion/cache/collapse, partial-prop root preservation, independent unsaved tabs/keyboard, confirmed versioned deletion/cache reset and Greek rendering. Local browser review covered Greek list/editor, independent unsaved content tabs, 375px/no-overflow mobile navigation/Escape and a clean console. Review drafts were discarded; only system reference data was seeded locally, with account credentials/preferences unchanged.

Final gate: 114 backend tests / 3,077 assertions with no PHPUnit notices; 18 frontend tests; Pint, PHPStan/Larastan, TypeScript, ESLint, Prettier, production Vite build and git diff --check pass. Local additive migration/routes verified. CI remote execution and future Product/storefront behavior are not claimed.

Metadata/age follow-up gate: 120 backend tests / 3,315 assertions and 19 frontend tests; full make check and git diff --check pass. Added coverage includes validation/SQL age constraints, strictest ancestor and tie-source resolution, parent-selection partial responses and hierarchy moves, independent localized metadata/warnings, legacy omission preservation, explicit clearing and additive migration round trips. Local 000400 migration is applied. Read-only Greek browser inspection confirms the new controls at desktop and 375px without horizontal overflow or console warnings/errors; drafts were not submitted. No customer age gate or Product implementation is claimed.


## Article 39a device classification

Article 39a eligibility was removed from core by migration `2026_12_11_000100_remove_category_article39a_eligibility.php`, which drops `categories.article_39a_eligible`, its constraint and its audit capture. Greek reverse-charge VAT classification belongs in a future add-on.

## Discovery exclusion and customer-group visibility

Migration `2026_10_14_000700_add_catalog_shipping_and_visibility.php` adds `categories.exclude_from_discovery` (boolean, default false; never true on the system category) and `category_customer_groups (category_id, group_id)`. Both are edited in the General tab's **Visibility** section and imported by CSV (see [category import](category-import.md)).

- **Exclude from search and highlights** marks the category's products to be left out of global site search and product carousels/highlight widgets (Best sellers, Featured products and similar). It does not hide the category, its listing page or product pages.
- **Customer groups** follow the Pages semantics: no rows means every visitor; otherwise members of at least one listed group. Submission, validation, disabled-group and deletion rules match products (see [products](products.md)).

Both settings are stored per category, without copying them to children or products. Storefront visibility (`PublicCatalog`) applies status and customer-group restrictions of every ancestor. Product search (A120, [search](search.md)) applies *Exclude from search and highlights* the same way, to the category and its whole subtree, at query time. Product carousels and highlight blocks do not apply the exclusion yet.
