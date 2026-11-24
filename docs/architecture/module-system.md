# Laravel-native module contract

Core capabilities are essential and not installable/disableable (cash on delivery, orders, catalog…). Optional integrations and commerce enhancements are add-ons. Core cannot import add-on classes; add-on dependencies are declared and acyclic. Add-on PHP has application privileges: installation (by deployment or by an administrator uploading a ZIP) is a trusted action, not a sandbox.

Under A29, business code lives within each context's pure Domain/Application; the host lives in App\Shared\Infrastructure\Modules (manifest, graph, discovery, runtime loader, ZIP stager, lifecycle manager). Add-ons call published context Application contracts, never concrete persistence or controllers. [Context-first DDD](layered-ddd.md). Developer documentation: [add-on guide](../addons.md).

## Contract 2 (Phase 10, A85)

The manifest stays Composer-format (`composer.json`, `extra.cms-module`), now at contract 2: Composer `name`, `version`, `description`, `authors`, `autoload.psr-4` and `require` constraints plus `extra.cms-module.{contract: 2, title, cms, provider, requires, migrations?, contributes?}`. Contract 1 (Composer packages allow-listed in config/modules.php) is superseded; no contract-1 module was ever installed.

Discovery reads manifests from two trusted roots without executing code: bundled first-party add-ons in `modules/{vendor}/{name}` (shipped with the CMS, for example Stripe Checkout) and uploaded add-ons in `config('modules.addons_path')` (default `storage/addons/{vendor}/{name}`). A bundled name wins over an uploaded one and cannot be uploaded. Lifecycle state is the `addons` table (name, source, installed version, enabled/disabled, version); the database is the source of truth.

On every start `AddonRuntime` loads enabled add-ons whose files match the installed version, whose `cms` constraint accepts `CmsVersion::CURRENT` and whose dependencies are loadable at satisfying versions: it registers an isolated PSR-4 autoloader per add-on, the provider (dependency order, lexical tie-break, `ModuleGraph`) and the declared contributions through `ModuleExtensionPoints`. A broken add-on is skipped and logged so the administration stays reachable. Before the schema exists (first migration, `composer install` without a database) no add-on loads. Loading never installs, migrates or writes data.

Extension points are published by core contexts (`ModuleExtensionPoints::add(key, point)`, registered before the host provider). Each validates declarations without executing add-on code and registers them for enabled add-ons. Published: `payment-methods` ([payments](payments.md)), `shipping-providers` ([shipping](shipping.md), Phase 11) and `search-providers` ([search](search.md), A120).

Validation (install, enable, `cms:addon-validate`): name/folder match, semantic version, title, CMS constraint (Composer syntax subset: `^`, `~`, wildcards, comparisons, `||`), PSR-4 prefixes inside the package and not overlapping the CMS, its libraries or other add-ons, provider class file inside the add-on namespace, owned migration names `YYYY_MM_DD_HHMMSS_{vendor}_{name}_*`, dependency constraints, known extension points.

## Contributions and boundaries

| Capability         | Contract                                                                                                                                                                 |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Provider/config    | Laravel register binds services/mergeConfigFrom; boot registers allowed runtime contributions. No migrations, installation, remote calls or merchant data writes at boot |
| Models/services    | Module-owned namespace/tables; use core application operations, never duplicate stock/pricing rules                                                                      |
| Routes/controllers | Names module.{vendor}.{name}.*; admin and storefront route groups separate, appropriate web/auth/authorization middleware. No disabled routes or public admin endpoints  |
| Permissions        | Namespaced stable ability IDs; server policies enforce them. Declaring a permission does not grant it                                                                    |
| Settings           | Typed defaults/validation/scope; merchant settings distinct from deployment config. Encrypt secret values; never share them as Inertia props                             |
| Migrations         | Namespaced/module-prefixed tables, timestamped collision-resistant migration names, explicit dependency order and FK retention                                           |
| Translations       | Laravel namespaced translation keys; data translations remain core/domain records                                                                                        |
| Assets/UI          | Trusted build-time imports and named typed contribution slots, no runtime JSX uploads or arbitrary page replacement. Exact asset/slot API deferred until real UI exists  |
| Events/hooks       | Published contracts in [extensions](extensions.md); no global mutable parameter bags or source patches                                                                   |

Bundled add-ons live in modules/{vendor}/{name}, uploaded ones in storage/addons/{vendor}/{name}. Do not create empty directories for absent modules. Module service providers explicitly load their routes/translations where enabled. Phase 1 implements the navigation registry. Phase 2 adds the consumed permission and bounded UI-translation registries; settings registry remains a design.

### Phase 1 admin navigation contribution

From an enabled provider's boot method, resolve `App\Admin\Navigation\AdminNavigation` and register `add(id, label, route, ability, position)`. Use a unique module-namespaced ID and a parameterless `module.{vendor}.{name}.admin.*` route name. Serve module admin routes under `/admin`, with `web`, `auth`, `auth.session`, `verified`, `can:access-admin` and the module's own authorization. Names do not confer middleware automatically. Register only implemented pages/abilities. The registry validates duplicates/missing routes, sorts position then ID, and filters each user's allowed abilities before exposing relative links. No module is installed as part of Phase 1. This is a navigation contract, not a generic UI/hook framework. [Admin details](admin-architecture.md).

## Phase 2 permission and translation contributions

Navigation grouping is additive: `AdminNavigation::addGroup(id, translationKey)` declares a namespaced group; optional sixth `add(..., position, groupId)` associates children. Existing five-argument calls stay standalone. `core.access` is the published staff/role administration group. Groups render at their first authorized child's position, disappear without authorized children, and never grant route access. See [group behavior](admin-architecture.md#grouped-navigation).

Register unique dotted vendor/module capabilities with App\\Authorization\\Infrastructure\\PermissionRegistry::add(name, translationKey) during an enabled provider's register/boot phase, before the application's booted gate-finalization callback. Laravel gates enforce current active/verified identity and persisted role grants; declaring a capability grants nobody. Run cms:permissions-sync explicitly after deployment, never from provider boot. Disabled modules publish no gates; retained DB grants require deliberate reactivation review.

Load package catalogs with Laravel loadTranslationsFrom(path, 'vendor-module'); use vendor-module::file.key labels in permissions/navigation. Optionally export a bounded browser group through App\\Localization\\Infrastructure\\UiTranslations::addGroup('vendor-module::ui'). Only the current locale and fallbacks are projected, not every language. Resource registration is trusted deployment code, never a request-controlled namespace/path. [Localization](localization.md), [authorization](authorization.md).

## Phase 3 Catalog extension points

Typed pure Catalog Domain events CategoryCreated/Updated/Moved/Deleted are dispatched after outermost commit. Enabled providers may register ordinary Laravel listeners; stable IDs/actor/time are payloads, never Eloquent graphs. Queued listeners must be idempotent and recheck current state. No broad mutable hook bus is introduced.

For atomic reference integrity, enabled trusted providers may resolve `App\Catalog\Application\Service\CategoryMutationParticipants` and call `add('vendor.module.references', participant, priority)`. Implement the framework-free `CategoryMutationParticipant` contract. `beforeParentUsed(parentId)` and `beforeDeletion(CategoryDeletion)` execute under the category transaction lock, before persistence/deletion. The deletion payload supplies categoryId, uncategorizedId and actorId. Participants can veto or perform same-connection SQL; exceptions roll back the mutation. No network effects/queued reassignment in this boundary. Duplicate IDs fail; execution sorts priority then ID. Future core Product uses this consumed contract, not a disabled optional addon, to preserve references and leaf assignment. See [categories](categories.md). Provider boot only registers callbacks, never mutates merchant data.

## Public catalog and storefront widgets

Add-ons read the catalog through the published [`StorefrontCatalog`](storefront-catalog.md) contract, never through
`PublicCatalog`, `BlockContent` or catalog tables (A127). `StorefrontRegistry::widget()` declares one external document,
shown in a cross-origin sandboxed frame on approved storefront pages; see [the contract](../addons.md#storefront-widget-contribution) (A128).
Add-ons that need either declare contract 3 (A129). Contract 3 also adds the settings panel, `AddonStatus` and the
`uninstall-clears` manifest key (A130).

## Lifecycle and schema operations (implemented, A85)

| Operation | Behaviour |
| --- | --- |
| Discover | Read/validate manifests of both roots only; the Add-ons list shows bundled packages before any installation |
| Install | Bundled: Install button or `php artisan cms:addons-sync` (deployment step; installs new bundled add-ons **disabled**). Uploaded: ZIP staged and validated in a private staging folder, moved into place, then owned migrations and the `addons` row commit in one PostgreSQL transaction; any failure rolls the database back and removes the files |
| Enable | Requires the installed version on disk, compatibility, enabled dependencies at satisfying versions and valid contributions; registers the provider in the running process (failure keeps it disabled) and synchronizes declared capabilities (no grants) |
| Disable | Refused while an enabled add-on requires it; contributions stop being offered immediately (payment lists check the database state); data stays |
| Upgrade | Bundled: newer version shipped with the CMS, applied by `cms:addons-sync` or the Upgrade button. Uploaded: newer version uploaded with explicit replace; previous files kept as a backup until the database work commits and restored on failure. Only pending owned migrations run; configuration and data are kept |
| Uninstall | Disabled add-ons without installed dependents: removes the `addons` row and an uploaded add-on's files. Tables, settings, payment records and migration history are retained (reinstall reconnects); nothing is reversed implicitly, except own tables the manifest lists in `uninstall-clears`, which are emptied |

All operations serialize on one advisory lock, require `addons.manage` (uploads `addons.upload`; both super-admin only by default) and are audited. Uploads can be disabled per installation (`ADDON_UPLOADS=false`). Filesystem steps are compensated, not transactional. After enabling/disabling, long-running workers keep already-registered providers until restarted; payment contributions are filtered by the database state regardless.

ZIP safety: size/entry/uncompressed limits, compression-ratio and actual-bytes checks, safe relative names only (no traversal, absolute, backslash, drive or hidden paths, case-insensitive duplicates), no symbolic links or encrypted entries, an allow-list of file types, entries streamed one by one into a fresh staging directory (never `extractTo`), stale staging pruned after a day.

## Storefront color styles (Black Friday Theme)

The consumed `StorefrontRegistry::stylesheet(addonName, css)` presentation slot accepts trusted package CSS from enabled
providers. Core gates it by loaded/enabled ownership and excludes admin; React renders it on storefront/auth/closed pages
and refreshes it on partial Inertia visits. Registration order follows normal provider order. No saved theme data changes,
asset uploads, script injection or build-time addon imports. See [the exact contract and bundled addon](../addons.md#storefront-stylesheet-contribution).

## Contract 4: canonical public content discovery

`StorefrontUrls` and `IndexableUrl` expose default-language, canonical, anonymously indexable storefront content to add-ons without granting access to core records or request-specific customer data. An add-on depending on this contract declares manifest contract 4; contract 2 and 3 packages continue to work. The bundled `ambresoft/sitemap` consumes it for seven separate section providers. It uses the existing lifecycle, owned migrations, namespaced permissions, UI translations, navigation and Laravel scheduler. Its native admin screen is a trusted build-time import through an Inertia page bridge, not uploaded React code. [Sitemap design](xml-sitemap.md).
