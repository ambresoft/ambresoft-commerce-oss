# Localization foundation — Phase 2

**Current policy:** A42 defines the six-language pool, Settings screens, active-only admin language choices, UI overrides and strict Category editor completeness. A43 permits partial Category CSV imports. Phase 4/A46 deliberately requires only the configured default-language Product name for normal saves, and its slug for activation; other translations/descriptions are optional. Read [language management](language-management.md) and [Products](products.md). Missing Product editor content stays empty; fallback is presentation-only and never copies merchant text.

Application strings and merchant content are separate systems. UI labels/messages use Laravel keyed language files; future commerce content uses normalized entity-specific translation records. Do not put merchant names into lang files or application labels into an arbitrary translations database.

## Locales and identifiers

locales stores bigint ID, unique code (35 characters), English and native names (80), active/default flags, direction (ltr/rtl), nonnegative sort_order and UTC timestamps. A partial unique index permits at most one default; CHECK requires that default to be active. The shipped pool is en/el/es/fr/it/nl; the additive language migration preserves existing settings and adds four inactive rows. Settings → Languages enforces one active default and at least one active language through `languages.manage`.

**v1 selects language-only identifiers:** canonical lowercase codes from the six-language shipped pool. LocaleCatalog distinguishes shipped languages from currently active choices. Do not use EL, english or en_US. Expanding the pool requires an approved dictionary/configuration change, not a schema redesign or arbitrary admin-entered code. Codes are trusted reference data, never filesystem input.

The 35-character column leaves room for a separately approved canonical BCP 47 expansion (e.g. en-GB). That expansion must update validation and map canonical hyphenated codes to Laravel's region-directory convention only at the loader boundary; it is not implemented or advertised as full BCP 47 parsing now. Current language-only en/el follow Laravel's directory conventions directly. [Laravel localization](https://github.com/laravel/docs/blob/13.x/localization.md).

## Resolution and preference

Admin/browser request → active authenticated user's preferred_locale_id → active system default → en safety fallback. Guests use the default; no arbitrary header/path selects resources. Middleware resets locale before validation/Inertia. Deactivating a preferred language retains the stored ID but resolves requests through the system default until reactivation. UserRecord implements Laravel HasLocalePreference through this active-locale resolution.

PATCH /admin/locale requires active, authenticated, verified staff. A staff member can change their own preference to an active language without language/user-management permission. Persist its ID, rotate the session and return to a validated local admin URL. The header selector uses the active choices and this endpoint; there is no separate account-language page. Other users are unaffected. React updates document lang/direction after Inertia navigation. Catalog forms also receive only active languages.

System default is not a staff preference or a storefront-language setting. Future storefront resolution is independently URL/request locale → supported store locale → store default; admin language never chooses storefront content. No public localized routes, theme system or storefront are implemented.

## UI translations

Each of lang/en, lang/el, lang/es, lang/fr, lang/it and lang/nl contains common.php, admin.php, navigation.php, auth.php, users.php, roles.php, permissions.php, authorization.php, validation.php, categories.php, category_import.php and languages.php. Use stable keys such as admin.dashboard.title, not English sentences as keys. Laravel translates server validation/errors/status/navigation/breadcrumbs; English validation inherits the framework's keyed catalog, while the other dictionaries cover rules consumed by the actual forms.

UiTranslations exports flat key/string maps for the bounded current UI groups, never every installed language. React useTranslation consumes the current server dictionary and named :parameters; it does not duplicate business validation. Fallback merges English, system default and requested UI catalogs in that precedence. Laravel's translator uses requested → default → en for missing server keys. Missing all catalogs is a development defect: parity/literal-key tests catch core keys; do not expose internal keys as normal labels.

The ten current frontend UI groups are bounded Phase 1–3/follow-up dictionaries (including categories, import and language settings); validation and authorization error catalogs remain server-only. As features grow, split exported groups by page/module rather than exporting the entire future CMS. Loading the current dictionary plus fallback resources is not preloading every supported language. No client-side i18n package or independent client locale storage is required.

All six shipped UI dictionaries cover actual authentication pages, pending/verification states, dashboard/shell/user menu, user/role forms/lists, permission labels, categories/imports, language settings, errors and notifications shown by the application. User names, application name, role names/descriptions and environment identifiers remain actual data, not guessed translations. Laravel's stock mail templates remain framework-managed; the preferred-locale contract does not by itself provide translations of every vendor mail sentence. Full branded multilingual email templates remain release/content work.

## Module resources

Enabled providers use loadTranslationsFrom(modulePath.'/lang', 'vendor-module') with lang/en, lang/el and future locale directories. Keys are vendor-module::permissions.moderate, not edits to core permissions.php. Optionally register a bounded browser group through UiTranslations::addGroup('vendor-module::ui'); its current-locale strings inherit the same fallback. AdminNavigation labels accept Laravel keys, including namespaced keys. Providers register deterministic resources, not database grants.

Use PermissionRegistry::add('vendor.module.action', 'vendor-module::permissions.action') for capability labels. Sync definitions explicitly. Disabled providers contribute no runtime namespace/UI group/ability. The module fixture test exercises real Laravel provider loading and the Inertia dictionary without installing an addon. [Laravel package translations](https://github.com/laravel/docs/blob/13.x/packages.md#translations).

## Entity content tables and contract

Each entity owns its translation table: entity → entity_translations → locales. Phase 3 implements category_translations with exact-row mapping into the existing Translatable contract; Product remains future work. Columns include category_id FK, locale_id FK, name, description, seo_title, seo_description, slug, image_alt and timestamps. Use UNIQUE(entity_id, locale_id), an indexed locale FK and deliberate entity/locale deletion rules. Do not create name_en/name_el columns, a generic polymorphic translation table or a giant authoritative JSON document.

Translate customer-facing text: name, description/short description, display text, SEO title/description and locale-specific slug. Keep SKU, stock, status, amounts/currency, weight, IDs and timestamps on their actual non-translatable owners. Structured metadata needs a field-by-field decision; the word metadata is not permission for arbitrary JSON.

Shared Domain Translatable::translation(code) returns an exact LocalizedContent or null. LocalizedContent carries its locale and typed nullable textual fields. Infrastructure adapters/future plain entities implement this interface; do not make Domain depend on an Eloquent trait. Localization Domain TranslationResolver::resolveTranslation(entity, requested, default) checks requested → default → en, returning the actual resolved locale or null if none exist. This small contract is the only reusable content abstraction; no future Product class/table or generic translation engine exists.

Row absence means missing translation. Present empty string means intentionally empty and is preserved; null marks a missing optional field. The resolver falls back at **row level**, not silently field-by-field. Required names cannot be empty under future entity validation; optional intentionally blank descriptions do not resurrect fallback text. Read projections expose requested/resolved locale and completeness, so fallback is visible to admins. Future storefront views must render a deliberate unavailable/content placeholder if all rows are missing, never raw keys or an accidental blank page. Editing always targets the exact requested row, never a fallback row.

## Future editor, completeness and URLs

The Product editor will expose independent English / Ελληνικά / other active-locale tabs. Each tab edits its own name, descriptions, SEO text and slug. Non-translatable SKU/stock/etc. controls remain separate. Submit only explicitly edited locale rows with validated locale IDs/fields; an omitted language means unchanged. Saving English must not overwrite Greek, and vice versa. Never upsert fallback text into a missing translation automatically. Use one transaction for the requested entity and supplied translation edits; decide concurrent-edit/version handling when the real editor exists.

Completeness is derived from exact-row presence and the entity's required translated fields: translated, missing/incomplete, or showing fallback. Display fallback with its source locale, not a false green completeness badge. No product translation indicator or persisted completeness cache is implemented now.

Slugs are locale-specific, with UNIQUE(locale_id, slug) **within each resource type/installation** (A13/single-store A08), plus one translation row per entity/locale. Normalize according to the entity's documented slug rules; locale/slug is not identity. /en/products/red-shirt and /el/products/kokkino-mplouzaki are conceptual future examples only. Category slugs use lowercase transliterated ASCII words and the future prefix /{locale}/categories/{slug}, independent of tree moves. Slug changes need locale-aware redirect history before publishing; no storefront URLs exist yet.

For lists, query/eager-load only requested/default/en translation rows in bounded queries, not all locales and not a per-row resolver database query. Infrastructure should map preloaded records into exact lookups before invoking the plain resolver. Add indexes for actual locale/status/search joins; no premature search engine or translation denormalization.

## Verification and open decisions

Phase 2 tests cover en/el catalogs, persistence/refresh/current-page return, active/invalid/path-like input, locale isolation between requests, localized validation/status, fallback/key parity, module namespaces and pure content fallback/intentional emptiness. Its migration is round-tripped in dependency order only in cms_test, preserving existing identity rows. Category translation coverage is added below; Product translations remain absent.

Historical Phase 3 category forms required the default language on creation and preserved omitted/inactive languages without copying fallback. A42 now applies strict Category editor completeness and A43 permits partial CSV imports. Category-wide optimistic versions reject stale saves. Tests cover en/el independence, active locale expansion, invalid/inactive codes and localized slug uniqueness. Phase 4's separate Product policy is described above. Uncategorized's protected system display label is UI localization, not editable merchant content. [Category editor contract](categories.md).

The requested metadata follow-up includes localized meta_keywords and age_warning_message in the same category-owned translation rows and tabs. Age limits/toggles are not translated; inherited parent values are server-derived. Page title/Meta description/SEO name are UI labels for the existing seo_title/seo_description/slug, not parallel fields. Omitted new optional fields are preserved for backward-compatible clients; explicit empty values remain intentionally empty. No customer verification text or enforcement is automatically inferred from missing translations.

Implemented follow-up: supported-language settings/default/deactivation, personal preferences chosen from active languages, keyed UI overrides and strict Category completeness. The earlier statements that other active Category locales/optional fields can remain blank apply only to historical Phase 3 or current **single-language** mode; with two or more active languages all eight current localized fields are mandatory in every active language on the resulting entity. Future Product editors must validate merged exact rows against the active set, without overwriting omitted languages. Still future: regional BCP 47, Product-specific required fields, slug redirects and branded vendor email templates. See [current contract](language-management.md).
