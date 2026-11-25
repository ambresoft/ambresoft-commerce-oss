---
name: ecommerce-admin
description: Build or review this CMS's admin list, detail and form workflows using Laravel, Inertia, React, TypeScript and Tailwind. Use for admin UI and its request/prop contracts; do not build storefront themes or move business decisions into React.
---

# Ecommerce admin

Read [admin-architecture.md](../../../docs/architecture/admin-architecture.md) and [ui-conventions.md](references/ui-conventions.md) for the relevant screen pattern.

- Inspect existing admin layouts, components and page contracts before adding a new screen. Reuse established patterns; do not choose a UI library or design system implicitly.
- Coordinate server-driven navigation and mutations through Laravel and Inertia. Keep business validation, authorization, pricing and stock decisions on the server.
- Type page props and form values deliberately. Return a minimal page-specific projection rather than exposing entire Eloquent graphs or secret fields.
- Keep admin routes, controllers, navigation, page layouts and props distinct from eventual storefront presentation. Share domain services instead of duplicating business logic.
- Keep Admin\\Controllers/Requests as the cross-context presentation interface. Invoke {Context}\\Application handlers with validated DTOs; place context-specific entry points in {Context}\\Presentation\\Http. Do not introduce Domain\\Admin or leak Eloquent records into Domain interfaces. Obtain conventions from [context-first DDD](../../../docs/architecture/layered-ddd.md).
- Make list state predictable: server pagination, allowed sort/filter fields, query-string state and explicit selection scope. Do not imply that selecting a page selects the entire search result.
- For list-row actions, use the established right-aligned order: Delete icon, Edit icon, then the status switch at the far right. Reuse `IconButton` and `Switch`, keep accessible labels and tooltips, and show only permitted actions. See "List / table" in [ui-conventions.md](references/ui-conventions.md).
- Make forms show server errors beside fields, preserve entered values after failure and prevent duplicate submissions. Explain destructive operations and incomplete bulk results.
- Follow the "Established editor patterns" in [ui-conventions.md](references/ui-conventions.md) for every edit page: status at the start of the `EditorActions` row, a trash icon button left of Save, `ConfirmDeleteDialog` instead of `window.confirm`, and centred impact dialogs. Do not wait to be told these again.
- Use semantic controls, keyboard access, readable status labels and consistent feedback. Build polish through reusable components and clear hierarchy, not bespoke interaction on every page.

Implement only the requested admin phase. Reuse Phase 1's shell/navigation/visual conventions; keep unselected future table/form helpers open. Use Vitest for meaningful component behavior and backend tests for server permissions and mutations.
