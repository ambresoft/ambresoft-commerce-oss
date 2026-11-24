# Phase 5: descriptive product features

Phase 5 is Catalog-owned, server-driven Laravel/Inertia functionality. The original roadmap JSON was not found in the repository; `development-phases.md` gives the phase/dependencies and the user's Phase 5 prompt supplies the detailed scope. No storefront, customer options, variations, stock, CSV, comparison UI or search indexing was added.

## Data and boundaries

Migration `2026_10_09_000900_create_features.php` adds feature groups and their translations, immutable feature definitions and locale-linked metadata, category restrictions, stable predefined options and localized labels, product assignments, localized assignment text and normalized option selections. `feature_audits` uses the existing actor/action/context/timestamp convention and retains entity identities after deletion. `feature_catalog_state` tracks editor configuration revisions; `feature_demo_records` maps explicit demo identifiers to records without matching display names.

`FeatureAdministration` and `SaveFeatureData` are consumed framework-free Application contracts. `FeatureRules` provides plain typed-value rules. Laravel validation, SQL queries, authorization, audit records and transactions live in Catalog Infrastructure; the cross-context admin controller delivers Inertia pages. Existing Product administration remains the transaction owner for product fields and feature assignments. No new generic repository or API subsystem is introduced.

The only types are Text, Single choice, Number, Yes/No and Multiple choices. Number uses `numeric(20,6)` with at most fourteen integer and six fractional digits, zero and negative values accepted, and separate optional unit text. Values are transported as decimal strings. Boolean null is distinct from false. Foreign keys enforce choice ownership and assignment type; a partial unique index enforces Single choice cardinality. Database guards preserve feature code/type, General identity, scalar/text type integrity and category reference integrity.

Every feature has exactly one group; General is protected reference data whose name comes from UI translations. Codes derive from the default-language name through the existing slug transliteration, uppercase and collision suffixes; nameless drafts receive FEATURE (with suffix when needed). Renaming never changes code. Feature definitions have no slug or SEO metadata (see Feature Purpose below).

## Completeness, lifecycle and historical values

Incomplete is derived independently of Active/Archived: default-language name is needed, and choice types also need an active option with a default-language label. Drafts can remain incomplete. Activation and feature restoration validate completeness. Archived definitions/options reject edits; restoration never restores archived options automatically. Option restoration requires an active complete parent and its own default-language label. Resolve incomplete parent configuration before archiving when future restoration is needed. Existing archived option assignments are visible with a replacement warning and count as filled whenever the parent remains complete and applicable.

Archiving retains assignments. Permanent feature/option deletion is blocked by all assignments, including inactive history. Removing a current option selection through a normal product edit releases that option dependency. Clearing scalar/text values preserves the stable assignment; existing explicit product trash/permanent-deletion workflows can release the product-owned assignment, subject to their own permissions and guards. No automatic historical deletion is used to make a definition deletable.

Unrestricted features apply globally, including future categories. Restrictions include descendants and use EXISTS predicates, so overlapping ancestors never duplicate assignments. Scope/category changes preserve newly inapplicable assignments and reconcile them when applicable again. Category deletion is blocked when it is explicitly referenced by a feature restriction, preventing accidental conversion of a restricted feature to a global one; update applicability first. Tree moves also reconcile assignments under the existing Catalog lock.

Required checks run on product activation/reactivation (including existing bulk-status operations). An already-active product can be edited while requiring remediation; new rules never automatically change its status. Text requires only default-language content, Number accepts zero/negative, Boolean accepts Yes or No, and choices require selections. Remediation is derived from relational data, not persisted flags: optionalization, archival, scope changes, product deletion/status and value completion are reflected immediately. The dedicated remediation screen links to individual product editors and requires both feature and product viewing permissions.

Duplication remains Disabled and retains existing Phase 4 SKU/media/file behavior. It copies applicable feature values and localized text, including archived references as clearly marked read-only/warning data, without reactivating definitions/options. Inapplicable history is not copied.

## Impact, concurrency and authorization

All configuration, ordering and product assignment mutations share the Category advisory transaction lock. Definitions/options/groups have optimistic versions; assignments have their own versions and the product's aggregate version. Product feature submissions also carry the catalog revision. Category and language changes invalidate that revision. A stale request is rejected; no silent merge occurs.

Impactful operations first roll back and return a concrete preview. HMAC confirmation binds the exact request, impact counts, catalog revision and product revision summary. Changed impacts require a new confirmation. Feature archival/deletion and group deletion always require confirmation; unit/scope/required changes and option lifecycle actions require it when relevant assignments/products are affected. Unit changes never convert numbers. Group deletion moves features into General in relative order. Ordering rejects missing/duplicate IDs and requires a complete unfiltered catalog snapshot.

Seven registered capabilities: `features.view`, `.create`, `.update`, `.status`, `.delete`, `.groups`, `.order`. Product value changes require existing `products.update`; activation requires existing `products.status`. Ordinary permission synchronization does not grant capabilities to existing roles. The registered-capability super-admin behavior remains intact. Gate checks still query fresh persisted authority; the lookup now combines active/verified status and role grants in one query to avoid adding repeated queries as capabilities grow.

## Administration and localization

Products → Features provides paginated server search across active languages and type, group, scope, required, filterable and status filters. `/admin/features/create` and `/{feature}/edit` manage definitions, independent localized text and individual predefined options. `/admin/features/groups`, `/ordering` and `/remediation` provide dedicated workflows. There are no feature/option bulk actions or main-Products feature filters/columns. The only changes to the existing Products/Users index source are formatting needed by the repository's current Prettier check.

The Product editor's Features tab displays ordered groups and active applicable complete definitions, including empty optional values; preserved history/archived definitions are disabled. Category changes refresh the available feature set after transactional save, retaining compatible submitted values and warning before deactivation. Text language inputs never copy fallback content. Feature administration UI is translated in English and Greek; other enabled UI languages currently use the explicit English fallback for this new section. Merchant content supports every active configured language.

Filterability (except Text), comparison and group order are stored independently for future consumers. There are no public feature routes or storefront eligibility promises beyond the current active/complete/applicable rules.

## Feature Purpose and variant landing-page data (A61)

Migration `2026_10_10_001000_add_feature_purpose_and_variant_pages.php` adds `features.purpose`:

- `standard` (Standard Attribute) is the default for new and all existing features. Behavior is unchanged: specifications, filters, comparison, required values, groups and product values. Options expose labels only.
- `landing_pages` (Brand / Author Landing Pages) is allowed only for Single choice. `FeatureRules::supportsPurpose`, server validation and the `feature_purpose` CHECK constraint enforce it, so direct requests and SQL cannot bypass it; type immutability is unchanged. Purpose is set explicitly by an administrator and is never inferred from a name, label, code or brand list. An update that omits `purpose` keeps the stored value.

The feature form's Settings tab shows **Feature Purpose** as two described choices. The special purpose is disabled, with an explanation, for every type other than Single choice. Changing the type while creating resets the purpose to Standard. Archived features are read-only, purpose included; restore first. Selecting a purpose does not require completeness, because drafts can be incomplete. Completeness gates eligibility instead.

Switching Brand / Author Landing Pages → Standard Attribute shows a warning. When any option still has landing metadata, the save also requires the existing HMAC impact confirmation (`landing_variants`). The metadata is hidden, not deleted, and appears again if the purpose is switched back. Options, assignments, labels and history are never changed. Every purpose change writes a `purpose_changed` audit (`from`, `to`, preserved-variant count) next to the normal `updated` record.

Variant properties are columns on the existing locale-linked `feature_option_translations` row: `description`, `seo_title` (200), `seo_description` (500), `meta_keywords` (1000, matching the Category/Product convention) and nullable `slug` (200). The option label stays the variant name, and the stable option ID stays the internal identifier. Slugs use the existing `Str::slug` normalization, the `^[a-z0-9]+(-[a-z0-9]+)*$` CHECK and `UNIQUE (locale_id, slug)` across all variant rows, including hidden and archived ones. The optional "generate from this name" control builds a collision-safe `-2`, `-3`… suggestion from the label. Otherwise a conflict is rejected. A slug is not a URL. Collisions with categories, products, CMS pages or routes are left to the future storefront publication step.

`feature_options.landing_image_media_id` is a nullable unique FK to the shared `media_files` store. It uses the existing private JPEG/PNG/WebP upload rules (5 MiB, 6000×6000) and is served only to `features.view|update` staff at `/admin/features/{feature}/options/{option}/image`. Replacing or removing an image, or deleting the option/feature, releases the previous file only when `SharedMediaDeletion::referenced` finds no product, category or variant reference. `SharedMediaDeletion` also blocks deleting media that a variant still uses.

Standard Attribute options accept only `label`. The server rejects landing translation keys, `image` and `remove_image` for them. The option editor shows a separate **Variant Landing Page** fieldset only when the saved parent purpose is Brand / Author Landing Pages. Archived options keep this fieldset disabled. Option restoration keeps its existing rules: an active complete parent and a default-language label. Option saves record changed landing fields per locale and image `added|replaced|removed` in the audit. Updates of variant metadata never touch assignments, choices, product versions, applicability, required/filter/comparison settings, groups or ordering.

There is no per-option enablement toggle. `FeatureQueries::definitions` derives `landing_eligible` from four conditions: the purpose is Brand / Author Landing Pages, the feature is active and complete, the option is active, and the option has a default-language label. The editor shows this as an informational status. No landing-page entity, CMS page association, automatic page, publication, storefront rendering or public route exists.

### Future page-management contract

A future CMS page can reference `feature_id + option_id`. The composite `UNIQUE (id, feature_id)` on `feature_options` already supports a composite FK. That phase can resolve the purpose, the active/complete status (`completeSql`), the option status, the localized variant metadata, and the products assigned through `product_feature_choices (option_id, feature_id)`, which has a Single choice cardinality index. Per-language validity is the presence of a label/slug row for that locale. Before publishing, it must also check public-slug collisions with other routable entities.

### Migration, retention and rollback

Existing features become `standard`. No option-level landing toggle existed before this refinement, so none needed migrating. Feature-level SEO does not map unambiguously onto variants. Before the columns are dropped, each non-empty feature slug/SEO title/meta description is copied into `feature_audits` as an actor-less `seo_retired` record with locale and values. `down()` restores those values and removes the retention records. Rollback drops the purpose and variant columns and FK, so it discards variant metadata and leaves variant image files as unreferenced media. Use it only before merchants enter variant data. The local installation migrated 8 features, all to `standard`, with 0 retained SEO rows and all 14 options preserved.

## Demo data and operation

Run the additive migration and synchronize capabilities, then explicitly seed in a local installation:

```sh
docker compose exec -T app php artisan migrate
docker compose exec -T app php artisan cms:permissions-sync
docker compose exec -T app php artisan cms:features-demo 1
pnpm build
```

Replace `1` with the ID of an existing authorized staff user. Seeding is refused outside `local`/`testing`. It invokes the existing Category/Product and new Feature operations and requires their capabilities. The curated content supports English and Greek; it refuses installations with other active languages rather than changing language settings or copying translations.

The separate demo catalog contains ten realistic technology/travel products (`DEMO-P5-001` through `010`), six categories, three custom groups plus General, eight definitions and predefined options. It covers all five types, descendant applicability, optional battery-life values, multiple colors/connections and English/Greek text. Brand here is a descriptive demo feature, not a new Brand entity or a future storefront decision. Prices use the configured primary currency's precision and never change the primary currency.

Stable `phase5.*` mapping keys make repeat runs no-ops for existing records, preserving administrator edits. SKU/slug conflicts with real data or removed mapped dependencies fail the entire transaction. No reset, overwrite, production force flag or destructive cleanup is supplied. Rerunning does not recreate deleted mapped records.

## Verification

Feature Purpose refinement (2026-10-10): full `make check` passes: 230 PHPUnit tests / 18,310 assertions, 48 Vitest tests, Pint, PHPStan/Larastan, TypeScript, ESLint, Prettier and the Vite production build. `composer validate --strict` and `git diff --check` pass. Eight new backend scenarios cover the Standard default and purpose retention, the Single-choice-only rule (validation, HTTP and the DB constraint), localized metadata with normalization, per-locale uniqueness and suggestion, unchanged assignments and settings, rejection of Standard options, image upload/replace/remove with shared-media protection, a confirmed downgrade that preserves hidden metadata, archive/restore read-only rules and derived eligibility, the absence of public routes and page tables, and the migration's retention/rollback. The migration round-trip tests also unwind the new migration. Four new frontend tests cover purpose availability, hidden Standard fields, independent localized variant fields with read-only archived variants, and the downgrade warning. A fifth replaces the removed SEO-tab assertions.

Phase 5 original verification:

Full `make check`: 222 PHPUnit tests / 17,626 assertions and 42 Vitest tests pass, with Pint, PHPStan/Larastan, TypeScript, ESLint, Prettier and Vite production build. Composer manifest validation, Composer/pnpm advisory checks and `git diff --check` pass. Focused Phase 5 coverage includes 21 backend scenarios and five frontend interactions. Existing migration-round-trip tests now unwind feature dependencies first, in the isolated PostgreSQL test database.

Local application: migration and permission registration applied; first seed created 10 products and repeat seed created 0. There are 15 total products (five preserved), 71 demo assignments, all five types, zero missing-required remediation products and the unchanged EUR primary. Browser review verified Greek feature lists/settings and the populated product Features tab with independent English/Greek values; captured browser logs reported no errors/warnings.

## Variation purpose (Phase 6)

Phase 6 adds the third purpose `variations` (Variations as separate products, Single choice only, DB-checked), a shared `variation_style` (Text buttons default, Color swatches, Image swatches, Dropdown) and per-option `swatch_color`/`swatch_image_media_id`, kept separately from landing-page data and preserved while another purpose is active. Purpose changes away from it, archive/delete and definition changes that would invalidate a Variation Group are refused. See [variations.md](variations.md).

## Ordering screen (`/admin/features/ordering`)

Presentation redesign only; the server contract is unchanged (`POST /admin/features/ordering` with `catalog_version` and the complete `groups[{id, features[]}]` list, authorized by `features.order`, applied in one transaction and rejected when stale). Groups and features are rearranged client-side in a draft that nothing persists until **Save order**:

- Drag a feature by its handle within or between groups (dropping on a group's dashed end zone appends it); drag a group header to reorder groups. Archived features can be reordered but not moved to another group.
- The same moves are available without a pointer: focus a handle and press ↑/↓, use the row's up/down buttons, or the **Move to group** menu (which appends). Group cards have their own up/down buttons.
- The side panel lists the pending moves, offers Undo for the latest one, **Save order** (disabled while the order equals the saved one) and **Discard changes**. Rows whose group/position differ from the saved order show a marker. Leaving the page with unsaved changes triggers the browser's confirmation.

