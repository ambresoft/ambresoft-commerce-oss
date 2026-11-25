# Website menus — administration only

The explicitly authorized follow-up adds Website → Menus as the first real link in that navigation group. This is merchant menu configuration, separate from the permission-filtered admin sidebar. It does not render a storefront, create public routes, resolve content-page URLs, install a theme, or introduce a visual page builder.

The behavior reference is the official [CS-Cart menus guide](https://docs.cs-cart.com/latest/user_guide/look_and_feel/menus/index.html), reviewed 2026-10-12: manual nested items and dynamic category content. Our schema, code and existing CMS UI are independent. CS-Cart-specific dispatch fields, theme addon tabs, CSS classes and layout blocks are not implemented.

## Menus and items

Administrators can create multiple reusable menus for Header, Footer or Other purposes, name them independently in active CMS languages, and enable/disable them. Purpose is descriptive configuration for later storefront selection, not a placement operation. There are no seeded merchant menus or hardcoded main/footer identities.

A menu uses Manual items or All categories. Manual items have a parent, sibling position, active/disabled status and one of three targets: custom URL, stable category ID, or a heading without a link. Names, optional short plain-text descriptions and image alternative text are entity-specific locale rows. Only the system-default-language name is mandatory; other translations may remain empty. Missing names use the existing current/default/English display fallback; editor fields never receive copied fallback content. Inactive locale rows are preserved and cannot be edited through direct requests. English/Greek UI dictionaries are complete; the four other supported UI dictionaries use the existing explicit English fallback pattern with localized main labels.

Items support arbitrary nesting without a fixed depth limit, moving between valid parents, numeric sibling ordering, optional validated private images and an open-in-new-window flag. Equal positions are allowed, with stable ID as tie-breaker. Every position/move save is a normal transactional, versioned operation, not reordering a search subset. Item and parent/category search inputs use bounded server projections (50 matches plus the selected entry); sibling tables paginate at 40. Menus paginate at 25 and filter/search on the server. There are no bulk mutation controls.

An item targeting a category may generate its submenu from that category's descendants. Generated branches are live, read-only previews, following the catalog hierarchy/position/name. They cannot also contain manual children. All categories uses the current active category tree, including future categories, without materializing duplicate menu items. System Uncategorized and branches with disabled ancestors are excluded. Manual links to disabled categories retain their stable target for administrator review; future storefront consumers must check target availability.

Changing menu source when saved manual items exist requires explicit confirmation tied to the current aggregate version. The UI reports the saved-item count. Items are preserved when hidden by category mode and are editable again after returning to Manual. Disabling a menu or item does not delete its children or values.

Individual deletion requires confirmation. Deleting a manual item deletes its entire saved subtree; deleting a menu deletes all owned items/translations, including manual items hidden by category mode. The interface explicitly describes the irreversible subtree/menu consequence. Captured audit history is retained. Detached image metadata/files follow the existing media retention policy; no filesystem deletion happens as a side effect of a menu delete.

## Architecture and integrity

`Website/Domain/Service/MenuRules` owns the consumed framework-free URL policy. `Website/Infrastructure/MenuAdministration` owns server validation, authorization and transactions; `MenuQueries` owns bounded read projections. The cross-context Admin controller uses browser/Inertia routes. No parallel API, generic repository or unused Application abstraction is added.

Migration `2026_10_13_002200_create_website_menus.php` adds four relational tables: `website_menus`, `website_menu_translations`, `website_menu_items` and `website_menu_item_translations`. Stable bigint IDs are independent of localized names. Locale foreign keys restrict deletion; owner/translation uniqueness prevents duplicates. Parent ownership uses a composite FK `(menu_id,parent_id)` to `(menu_id,id)`. PostgreSQL rejects self-parenting, cycles, cross-menu parents, mutable item menu identities, inconsistent targets, system-category targets and mixed manual/generated children. Indexes support sibling paging and category/media references. Category and media foreign keys restrict destructive deletion.

Every supported write authorizes `menus.manage`, shares Catalog's advisory lock before taking the menu row lock, validates a positive submitted aggregate `version`, and rejects stale writes with refresh/review. An item save increments its own lock version and the aggregate menu version, so any editor opened before another menu/item mutation cannot overwrite it. Menu ID is immutable on items; moving items between different menus is not an operation. The Category participant explains referenced-category deletion failures before the FK is reached; change/remove the linked item first. Global category menus have no individual references and automatically follow catalog changes.

## Security, media and audit

`menus.view` controls lists, editors, previews and private image reads. `menus.manage` controls all writes, independently enforced in the reusable service. Read-only staff can switch tabs/languages; inputs and mutation controls are disabled. Navigation visibility is not authorization.

URLs accept HTTP/HTTPS, root-relative paths or non-empty fragments. Backslashes, protocol-relative URLs, scripts/data/mailto schemes, user/password URL credentials and control/space characters are rejected. They are displayed as escaped text in admin lists; no remote URL is fetched. Internal paths are configuration for future routes, not assertions that a page exists. Future rendering must escape text, validate category availability and apply `noopener`/`noreferrer` for new-window links.

Images reuse `SingleImageUploader`, real image/MIME/dimension validation and private `PrivateImageStorage`: PNG/JPEG/WebP only, at most 5 MiB and 6000 pixels per axis. No remote-image fetching, SVG upload or browser-provided storage path exists. Failed SQL operations compensate newly staged files; shared media maintenance refuses deletion while an item references the image.

The existing transactional database audit ledger captures menu/item/translation mutations and preserves actor/request context. URL changes are recorded as redacted length/change information because query parameters may be private; migration 002200 extends only the existing safe-value field policy for `url`, without rewriting prior logs. Large descriptions use the existing bounded redaction policy. Ordinary CMS APIs cannot alter the ledger. Rollback removes misleading success events, and service failures use the existing explicit failure/denial policy.

## Enable and verify

```sh
docker compose exec -T app php artisan migrate
docker compose exec -T app php artisan cms:permissions-sync
pnpm build
```

Synchronization registers `menus.view` and `menus.manage` without changing existing role grants. Assign them through Roles; Super Admin uses the established registered-capability bypass. No existing catalog, account or settings records are reset.

Backend menu tests persist real PostgreSQL data and cover localization, search/filter/pagination, arbitrary nesting/moves/order, application/database cycles, cross-menu parents, stale writes, category inheritance/live changes, generated/manual conflicts, confirmed mode changes, safe URLs/redacted audit, confirmed cascade deletion, permissions, rollback, referenced category deletion and private images. Frontend tests verify independent translations, preserved tab values, source-change confirmation, nested category targets, generated read-only previews and view-only access; the existing sidebar regression includes the real Website group. The category migration round-trip test unwinds/rebuilds this dependency and verifies that the original permission count is preserved rather than pinning a phase-specific count.
