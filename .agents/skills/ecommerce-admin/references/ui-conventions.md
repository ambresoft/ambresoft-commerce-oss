# Admin UI conventions

Status: reusable patterns for authorized admin work. Phase 1's shell, palette and native React/Tailwind components are established in [admin architecture](../../../../docs/architecture/admin-architecture.md); reuse them. List/detail/bulk conventions remain guidance for later authorized screens, not implemented features.

## Page and server contracts

Keep a consistent page title, contextual actions, breadcrumbs where useful and predictable feedback placement. Use reusable layout, field, table, status and dialog components once chosen, without forcing a generic schema-driven form engine onto domain-specific workflows.

Type only the data the page needs: identifiers, displayed fields, pagination/filter state, errors and relevant capabilities. Serialize monetary/decimal values deliberately instead of assuming JavaScript number is exact. Distinguish missing, null and empty form values. Never expose credentials, internal configuration or whole related models merely for convenience.

Use Laravel validation and Inertia's redirect/error-prop flow for ordinary forms, rather than designing a separate JSON validation API. Check the installed Inertia version's conventions at implementation time; the researched v2 docs describe redirected errors and preserved form state. [Inertia validation](https://inertiajs.com/docs/v2/the-basics/validation).

Server policies authorize both page access and each mutation. UI capabilities help explain available actions but cannot replace authorization. Allowlist sortable/filterable fields and validate query parameters on the server.

## List / table

- Place domain-appropriate search, filters and primary action predictably. Persist shareable list state in the query string; use server pagination and stable sorting with a tie-breaker.
- Distinguish a loading state, empty catalog, no matches and failed request. Give useful next actions without implying nonexistent features.
- Present identifiers, names and important commerce state clearly; use text plus color for statuses. Show price with currency and stock with its units/policy context when those domains exist.
- Define row actions and bulk selection separately. Explicitly state current-page, explicit-IDs or all-matching selection; changing filters must not silently broaden a pending action.
- **Row action order:** right-align the action group and always place available actions left to right as **Delete icon → Edit icon → status switch**. The switch is the rightmost control. Reuse `components/admin/list/IconButton` for Delete (`trash`, danger styling) and Edit (`edit`), and `components/admin/Switch` for status; use icons instead of visible Edit/Delete text links. Give icons translated accessible labels and tooltips, preferably including the record name. Omit actions the staff member cannot use while preserving the relative order of the remaining controls; a read-only View action keeps its appropriate label. Keep existing deletion confirmation and pending-state behavior. This order applies to list rows; editor headers retain their separate Delete → Save convention below.
- Keep large lists bounded and fetch only needed relations to avoid unbounded payloads and N+1 queries. Introduce virtualization or advanced grids only if demonstrated needs justify them.

## Detail / edit

Organize sections by domain meaning: features, customer options, variations, stock and prices should not become one generic “attributes” panel. Use tabs or sections only when they reduce cognitive load. Do not display interactive controls for unimplemented future phases.

Separate read-only identity/history from editable fields. Make the primary save action and return path clear. Decide how partial section saving interacts with transaction boundaries; do not let each React component independently mutate related business state.

## Forms

Use visible labels, concise help, requiredness and examples where domain meaning is unclear. Map backend errors to fields and provide a summary for errors outside the visible section. Preserve entered values on validation failure, show pending state and prevent accidental duplicate submissions. Do not use optimistic success for stock, price or destructive changes without a deliberate reconciliation strategy.

Use appropriate domain controls for categories, feature values and option choices after those models are settled. The same visual select control does not make the underlying concepts interchangeable. Keep identifiers and calculations authoritative on the server.

## Established editor patterns (reuse; do not reinvent or ask again)

These were agreed during real screens (features, products, categories) and apply to every new edit page.

- **Top action row:** use `EditorActions` above the tab bar. Status goes at the start (left) through its `leading` prop; the actions sit at the end (right) in this order: delete icon button, then Save. Status is also available on every tab, never only inside a General tab. Statuses read as colour plus text: green for active, red for archived/disabled.
- **Delete is an icon button:** a trash `Icon` (`button-secondary px-3 text-red-700 hover:bg-red-50`, `aria-label` and `title` set to the delete label), left of Save, shown only with the delete capability and on existing records. Do not add a separate "delete" card or text button at the bottom of the page. Disable it, with a tooltip explaining why, when the server would refuse (for example a category with children).
- **Never use `window.confirm`.** Destructive confirmations use `components/admin/ConfirmDeleteDialog` (design: red trash tile, title, "cannot be undone" subtitle, impact facts, optional green "Archive instead" box, optional type-the-code field, Cancel + red confirm). Type-to-confirm is for deleting a whole definition that other records depend on (a feature); reversible actions (product Trash) and plain records (a category) skip it. Use `subtitle` and `confirmLabel` for reversible wording.
- **Impact confirmations are dialogs too.** When the server answers with an impact preview, show `FeatureImpactDialog` (or the equivalent) centred on screen, never a box at the top of the page; confirming repeats the attempt that needed it in one click. A deletion already confirmed in its own dialog must not ask the same question twice. Hide the plain "please confirm" error that the dialog replaces.
- **Inline-editable collections** (options and similar): a spreadsheet-style table with search, All/Active/Archived tabs with counts, one column per active language, a count column, a status switch and a row menu (activate/archive, details, delete in red). Edits and new rows collect in a sticky "N changes pending · X new, Y modified" bar with Discard and Save, saved atomically through one batch endpoint guarded by per-row versions. Lifecycle actions (archive/restore/delete) stay immediate because they need impact confirmation. Requests must send every field the server validates (for example `translations`); surface failures next to the table instead of failing silently.
- **Empty error boxes:** `FormErrors` ignores empty messages; never pass a fixed key with a possibly undefined value expecting it to hide.
- **After a change, rebuild:** run `pnpm build` so a running app serves the new bundle, and say "hard-refresh" in the handoff. A stale build looks like an unfixed bug.
- **Tests:** scope image/role queries to the main content (the shared top bar has the logo); assert the dialog (`alertdialog`) instead of a mocked `window.confirm`; check translation groups are registered in `UiTranslations` or the Localization test fails.

## Bulk and destructive operations

Preview the concrete target scope and changed fields. Explain removal/copy/disable consequences; authorization and validation still apply to every affected record. Decide whole-batch versus per-row atomicity and report failures accordingly. Do not label a partly successful operation as fully complete. Add progress/jobs only when the operation's size requires them.

## Interaction quality and verification

Support keyboard navigation, visible focus, semantic table headings, labeled controls and dialog focus management. Keep responsive behavior usable for dense data; avoid hiding essential actions with no alternative. Use consistent spacing, typography and feedback rather than adding animation or custom widgets as a definition of “premium.”

Verify backend policy/validation/operation behavior. Use Vitest for meaningful component interactions, such as selection scope or displayed error state, rather than snapshotting every class. Inspect representative loading, empty, error and success states visually and by keyboard when screens are implemented.
