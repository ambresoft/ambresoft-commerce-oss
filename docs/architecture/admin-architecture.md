# Admin architecture

## Dashboard palette

The approved palette is centralized in `resources/css/app.css`: emerald accent #05ab7d, hover #079c72, selection tint #e6f5f1, canvas #f8f8f8, white surfaces, borders #e9e9e9, primary text #333333 and muted text #767676. Success uses the emerald accent, danger #ff3d47 and warning #ffc107. The shared top bar and desktop/mobile sidebars use light gray #ececef, dark navigation text and gray active destinations with a thin green indicator. Active tabs and selected rows retain the emerald tint. Green CTA buttons use white labels. Native checkbox/radio controls use the accent. The existing dark theme redefines these shared tokens and uses the white Ambresoft logo; light mode uses the supplied green logo. Reuse the shared tokens/classes for future admin screens instead of adding unrelated colors.

Categories → CSV icon is a native, permission-gated category workflow. Give the icon a localized accessible name and tooltip. Use the existing admin shell and keyed English/Greek translations. Upload/preview never mutates category data; show bounded row/error summaries, explicit confirmation and a terminal result. Download the current template and safe error CSV from the same screen. No standalone import application or future-entity navigation is added. See [category import](category-import.md).

Phase 1 implements the first admin interface. Keep App\Admin\Controllers and future consumed Requests, resources/js/pages/Admin and routes under /admin named admin.*. Admin is a cross-context presentation interface, not a business Domain: invoke App\{Context}\Application handlers with validated input DTOs. Context-specific browser endpoints live in {Context}/Presentation/Http. Domain repository interfaces return plain entities; Eloquent records/mappers belong in context Infrastructure/Persistence. Phase 2 adds staff-user/role workflows and a persisted language selector; commerce workflows remain future guidance. See [context-first DDD](layered-ddd.md) (A29).

| Pattern            | Convention                                                                                                                      |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------- |
| Layout             | Consistent page title, contextual actions and content width; reusable shell, no fake statistics                                 |
| Sidebar/navigation | Permission-filtered grouped links, active state, keyboard access; modules contribute namespaced entries at documented positions |
| Breadcrumbs        | Server-supplied labels/URLs for meaningful hierarchy, not guessed from pathname segments                                        |
| Tables             | Semantic headings, stable server sorting with ID tie-breaker, bounded eager loading and minimal projections                     |
| Filters            | Validated allowlisted query parameters, shareable URL state, clear reset and no-results state                                   |
| Pagination         | Server pagination with total/current-page metadata where supported; preserve filters/sort                                       |
| Selection          | Explicit current page/IDs/all-matching scope; changing filters cannot silently enlarge bulk targets                             |
| Forms              | Inertia forms with typed values, visible labels, server validation/policy checks and preserved input on failure                 |
| Detail             | Organize by domain meaning, separate read-only identity/history and editable sections; no generic attributes panel              |
| Confirmation       | State concrete targets and consequences; authorization rechecked on submit; no optimistic destructive success                   |
| Modals             | Focus trap/restore, Escape policy, labeled heading; use for bounded tasks, not giant catalog editors                            |
| Notifications      | Accessible success/error summaries; distinguish partial bulk success from full success                                          |
| Errors             | Field-level server errors plus summary for hidden sections; expired session/403/failed request handled distinctly               |
| Loading            | Disable duplicate submission, show pending/progress without discarding data; no frontend-authoritative pricing/stock            |
| Empty states       | Separate no records, no matches, loading and failure; offer only implemented actions                                            |
| Responsive         | Collapsible navigation, horizontally usable dense tables, accessible row actions; preserve essential information                |
| Permissions        | Server capabilities explain UI; policies authorize each request/resource/bulk target. Hidden button is not security             |

Serialize identifiers/money/decimals as strings. Never share secrets/full model graphs. Domain validation/transactions remain on the server; simple page-specific projections need not become generic DTO frameworks. Modules submit extra fields through explicit validated operations.

Premium means hierarchy, readable density, predictable navigation, accessible focus/contrast and consistent feedback. Phase 1 uses system sans typography, slate neutrals, emerald success status, subtle borders and minimal shadow. Small native React/Tailwind components live in components/admin and components/auth; repeated styles live in resources/css/app.css. No third-party component/icon library or generic form engine is introduced.

## Implemented shell and navigation

The desktop sidebar collapses from 240px to a 48px icon rail using a 220ms width transition (disabled for reduced motion). Its bottom toggle remembers `cms-sidebar-collapsed` in browser storage. Collapsed groups open beside their icons on click or after a 120ms hover delay; only one flyout is open at a time. Nested groups and permission-filtered routes are shared with the expanded/mobile navigation. The top-bar account trigger shows only the avatar; its dropdown retains the staff name, email and account actions.

`layouts/admin/AdminLayout` composes Sidebar, Header, Breadcrumbs, UserMenu and Notifications. Its 48px full-width top bar and 240px desktop sidebar stay stable around one rounded, bordered workspace. The workspace is an Inertia `scroll-region`; page headings, breadcrumbs and content share its surface. Editor tabs pin to its top edge. Smaller screens use a labeled native navigation dialog (focus trap, Escape, return focus) with Menu/close controls. The original toolbar icons/actions remain, with compact labels on small screens; the existing Ambresoft lockup moves to the top bar. The account menu uses the shared accessible dropdown and POST logout. Server-supplied breadcrumbs do not parse URLs. Auth pages share `layouts/auth/AuthLayout` and server-validated Inertia forms with explicit pending/error/autocomplete states.

`App\Admin\Navigation\AdminNavigation` is the provider-registered contribution point: call `add(id, label, route, ability, position)` from an enabled provider's boot method. IDs are unique/namespaced, positions sort numerically with ID tie-breaker, route names must be `admin.*` or `module.{vendor}.{name}.admin.*`. Entries resolve only after routing boots; missing routes fail instead of rendering dead links. `forUser` returns only authorized `{id,label,href,active}` projections. Register abilities on the server and protect each route independently; navigation registration does not grant access. Modules must enforce `auth`, `auth.session`, `verified`, `can:access-admin` plus their specific permissions. The real core.dashboard, core.users and core.roles entries ship; labels use Laravel translation keys. A navigation entry is active for its resource descendants. Asset/component slots beyond navigation remain deferred.

Phase 2 replaces A25 with persisted capabilities (A30–A31). The access-admin gate still requires active verified staff; granular capabilities protect staff/role operations. [Authorization](authorization.md) documents bootstrap/recovery and safety; [localization](localization.md) documents keyed en/el strings, current-locale props and the persisted selector. Native confirmation dialogs protect deactivation/deletion; existing responsive shell/table/form conventions are reused.

### Grouped navigation

Register optional groups with `addGroup(id, translationKey)`, then pass their ID as the sixth argument to `add(id, label, route, ability, position, group)`. Existing five-argument module contributions remain valid standalone links. Groups may be registered before or after their children, but must exist when projecting navigation; duplicate groups/missing groups fail fast. `forUser` remains a flat, sorted authorized-link list, adding optional `group: {id,label}` metadata. The sidebar renders a group at its first visible child's position and preserves child order. No authorized children means no group metadata or empty dropdown. Modules can contribute to a published group or register a namespaced group without changing Sidebar.

Users and Roles share `core.access` (Access management / Διαχείριση πρόσβασης). Native details/summary provides keyboard-operable expand/collapse on desktop and mobile. A group containing the current resource starts open; each child retains its own active marker and mobile-close behavior. Groups are presentation only, never permission grants or routes.

## Phase 3 category administration

`core.categories` is a real standalone destination protected by categories.view. `/admin/categories` presents paginated lazy sibling branches with accessible expand/collapse buttons, status, numeric position, child count and explicit edit/add-child/confirmed-leaf-delete actions. Search/status filters switch to a paginated localized result list with full ancestor paths. No drag-and-drop, fake catalog or future dead links. Successful deletion resets cached branches from the server; failed deletion preserves errors, never optimistic removal.

Tree rows offer checkboxes for visible deletable leaf subcategories. A contextual toolbar confirms one all-or-nothing deletion of up to 40 selections; root, system and parent rows are not selectable. Navigating/collapsing a branch clears selection. The server remains authoritative for the capability, current versions and deletion rules.

Create/edit uses one shared `Admin/Categories/Form`: bounded parent search excluding the current subtree/system node, numeric position/status, independent active-locale tabs and private image replacement/removal. The edit heading names the persisted category using the server's localized display fallback; that fallback never populates editable fields. A permission-gated Delete action appears after the form's Save/Cancel controls at the bottom of the edit page and reuses the tree's confirmation/versioned request; parents show a disabled action with the child-removal explanation, while the server independently enforces the deletion rule. Tabs retain unsaved values and keyboard navigation without a secondary missing/translated status label. Only explicitly edited locale rows are sent. Server validation and optimistic versions remain authoritative. System Uncategorized is visible as a protected recovery row without normal actions. Category permissions control both UI actions and server requests. [Detailed behavior](categories.md).

The authorized category follow-up adds own age-verification/limit controls and read-only inherited parent restrictions, refreshed from the server when the parent changes. Locale tabs also edit meta keywords and the age warning independently. Existing SEO title/description/slug fields are labeled Page title, Meta description and SEO name (slug), without duplicate storage. English/Greek help text makes clear that these settings do not implement a storefront age gate.

The authorized language follow-up adds a real Settings navigation group for Languages and Translations, both guarded by `languages.manage`. The Translations screen is linked from that sidebar group, not from the Languages settings page. Each staff member's preferred admin language remains a personal setting available from the active-language selector in the header. The system-default selector lists only languages checked active in the current settings form; the server independently enforces that the saved default is active. Catalog forms list only active languages and validate completeness on the server; dictionary overrides never edit merchant content. The dictionary editor searches one language, paginates trusted keys and offers explicit save/restore actions. [Language contract](language-management.md).

## Tabbed admin editors

Admin create/edit pages with multiple sections always use domain-focused tabs, following the Product editor, so users do not have to navigate one long scrolling form. Keep the tab bar compact and sticky, with navigation tabs only. Put Save, Status and related editor actions in a separate responsive `EditorActions` row above the tab bar, outside its border/background: Status at the start (`leading`), then a trash icon button for deletion, then Save at the end. Destructive and impact confirmations use `ConfirmDeleteDialog` / `FeatureImpactDialog`, never `window.confirm` or an inline box (see the admin skill's UI conventions). Use the established emerald active state, and show validation markers on tabs containing errors. Use semantic tablist/tab/tabpanel relationships with keyboard navigation and responsive wrapping. Preserve unsaved inputs and independent language values across tab switches; tabs organize the existing save transaction rather than creating separate persistence operations. Keep lifecycle actions and impact previews visible outside hidden panels. Only show tabs for implemented, applicable sections.

The Feature editor follows this pattern with General, Settings, Categories, SEO and predefined Options for choice features.

## Default CSV import UI

All admin CSV uploads use `resources/js/components/admin/CsvImportUpload.tsx`. The Category and Region import screens are the reference implementations; reuse these exports for the next import instead of copying markup or using a bare file input:

- `CsvImportSteps`: Upload CSV → Review preview → Confirm import. Keep it visible during previews/results; set its status from the actual import state.
- `CsvImportUpload`: the existing media `UploadPanel` drop zone, translated choose button, filename/size with replace/remove, field errors, settings card, progress and an explicit Upload & preview button. Supply the real configured upload byte limit from the controller. Put feature-specific settings (delimiter, path separator, etc.) in its children. Dropping/choosing a file must never upload, confirm or mutate data automatically.
- `CsvImportInstructions`: collapsed format guidance and allowed columns below the workflow. Keep feature-specific help and columns supplied by the feature; shared UI copy lives in `common.csv_import` in both English and Greek.

Keep Inertia form state, preview/error tables, server authorization/validation and explicit confirmation in each feature. The component is shared presentation, not a generic import engine. Disable resubmission during processing, preserve settings and errors, and never conflate uploading, previewing and applying changes. Verify the shared upload interactions and the feature's route/prop contract when adding a new import.

## Required-field labels

Mark mandatory inputs with the shared `RequiredMark` asterisk and expose `aria-required` on their controls. `FormField` automatically marks native required inputs; use `requiredIndicator` for server completeness requirements without adding browser validation that blocks draft saves or hidden tabs. Category and product forms explain the marker in the current admin language. Match conditional and language-specific server rules, including age limits, default-language product names and multi-language category completeness; leave optional descriptions and identifiers unmarked.

## Rich-body fields

Use the [shared Tiptap rich-text editor](rich-text.md) with Visual/HTML modes for every admin rich-body field. Supply a localized accessible label, entity/locale-specific ID, disabled state and the existing field limit. Preserve draft values across locale/tab switches. All corresponding server entry points must consume the shared Symfony RichTextSanitizer; a browser editor is never the authorization or XSS boundary. Keep names, metadata and scalar/plain-text feature values as plain text.

## Country selectors

Use `CountrySelect` for every single-country dropdown. It wraps the shared `Select` with localized search, matching country names and ISO codes without case or accent sensitivity. General tax defaults, Company Data and reusable customer address fields use it. Rate areas retain their existing searchable multi-country checklist.

## Website menus

Website → Menus is the first implemented Website navigation contribution. Reuse the current list card/filter chips/data table/pagination and sticky tabbed editors. Manage one sibling branch at a time, with root/parent navigation; generated category branches clearly explain their read-only live source. Only display implemented header/footer purpose configuration, links and optional item imagery. Menu definitions and item editors preserve independent language drafts; read-only staff can still switch tabs/languages. There is no copied CS-Cart UI, addon/theme configuration or storefront preview renderer. [Rules and scope](website-menus.md).

## Shared editor tabs and actions

Admin screens, including Website management and add-on settings, use `AdminTabs` / `AdminTab` (the add-on `UnderlineTabs` entry point delegates to them). Tabs use text only, a thin primary-color active underline and no filled active background. Overflow uses an ellipsis trigger and a compact dropdown with bounded scrolling. Keep the language controls separated from the tabs by a vertical divider. Bars containing only languages omit borders/dividers and use smaller language buttons. Preserve server permissions, validation markers and keyboard navigation.

Localized editors place one `ContentLanguageSelector` in the tab bar's `language` slot. Show the language codes without a visible label. Keep the editor's existing translation drafts and field rules; do not add language pickers inside its panels. Separate dialogs and independently saved editors can own their own selector. The global interface-language preference remains separate.

Products prioritizes Features and Variations in the bar; Sales and Price History always use the overflow menu. All matching admin list, detail, editor, settings, report and add-on headers use `EditorHeader`: an optional back arrow on the left, thumbnail or semantic page icon, heading, optional entity name/status and metadata, and right-aligned actions. Delete/Trash belongs in the three-dot menu at the far right through its `menu` slot; preserve permission guards, disabled states and existing confirmations. Header submit buttons outside a form must reference its stable `id` through their `form` attribute. Reuse `EditorActionButton` and `EditorActionLink` for Save, Duplicate and Preview. Product duplication uses the saved version and requires saving dirty drafts first. These conventions apply to admin only; storefront presentation is independent.

Layouts uses text-only page tabs, a labelled plus icon to add a layout, and the overflow menu. Its page bar has no collapse arrow or duplicate storefront preview; the global header supplies storefront access.

Editable statuses in admin headers must reuse `EditorStatusSelect`, the compact colored pill used by Products, Categories and Brands. Give it a translated accessible label; keep the visible header free of a separate Status label. Pass through server-defined choices, permissions, requiredness and validation/error descriptions.

### Admin consistency rules

Creation actions belong in the right-hand `EditorHeader.actions` slot with a plus icon. Descriptions contain explanatory text. The shared back link is small and borderless, between breadcrumbs and the identity header. Table entity names reuse the Edit action destination and permission guard. All admin and addon form/detail sections reuse `AdminCard` (`resources/js/components/admin/AdminCard.tsx`) or the shared form `Section` adapter. The component owns the rounded border, divided heading, green icon tile, heading actions and default body padding. Supply a semantic `iconKey` for the section or entity (for example phone for call requests, mail for email templates and package for product packages); reserve the gear for actual configuration. Avoid repeating the page identity icon on every card; use `icon={null}` for text-only card headings when the page header already establishes the identity. Register new keys in the shared icon registry rather than relying on its fallback. Keep layout-specific grids/spacing in its body and use `bodyClassName="p-0"` for full-width tables. Do not copy card markup or introduce a page-specific visual variant; compact item tiles, alerts, list tables and specialized canvases remain distinct controls.

An editor shows a single editable status control. A distinct computed storefront state may appear in metadata below the title; do not repeat the editable status as a badge. Keep status help in `HelpPopover`, available on hover, focus and touch. Translation forms show the locale chosen in `ContentLanguageSelector`, preserving all other locale drafts and error indicators. Deliberate translation comparison tables may display multiple locales.

Customer invitation controls open `ConfirmDialog` before the existing request. Explain which email receives the invitation and that it enables a storefront customer account, without staff access. Cancelling must send no request.

SEO-name fields reuse `UrlSlugInput`, directly or through `FormField.urlPrefix`, to show a fixed URL prefix beside the editable slug. Use `/` for root addresses, `/blog/` for blogs and an ellipsis for unresolved parent paths. Preserve existing locale drafts, field rules and slug-regeneration controls; do not apply this URL presentation to internal role identifiers.

Admin form/detail cards fill the available width of their page or assigned layout column. Do not constrain outer forms, card wrappers or tab bars with fixed `max-w-*` limits; keep reading-width limits on explanatory text and compact controls, and retain the existing bounds for dialogs and drawers.
