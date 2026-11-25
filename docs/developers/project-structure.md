---
title: Project structure
parent: Developer guide
nav_order: 2
---

# Project structure

```text
.github/workflows/      CI: quality, security (incl. container scan), CodeQL, release
.agents/skills/         shared AI-assistant skills (Codex and Claude Code); .claude/skills/ links to them
app/
  {Context}/            business contexts: Domain, Application, Infrastructure, Presentation (see Architecture)
  Admin/                administration presentation shell: Controllers, Requests, Navigation, Dashboard, DataTransfer
  Http/                 global middleware and the root controller
bootstrap/app.php       routing, middleware, exception handling, console commands and the schedule
config/                 Laravel configuration plus project files (modules.php, storefront.php, languages.php,
                        *-import.php limits, account_security.php, admin_notifications.php …)
database/
  migrations/           core schema (PostgreSQL)
  demo/                 images and videos used by the demo-store commands
docker/
  php/                  Dockerfile (FrankenPHP), Caddyfile, PHP ini files, local sendmail shim for Mailpit
  postgres/             init-testing.sql: creates the separate test database and its user on a new volume
  pgadmin/              pre-configured connection for the optional pgAdmin service
docs/                   this documentation site, plus internal engineering notes (architecture/, development.md …)
lang/{en,el,es,fr,it,nl}/  keyed interface translations, one PHP file per area
modules/{vendor}/{name}/   bundled first-party add-ons (loaded only when enabled)
public/                 web root: index.php, favicons, brand logos; build/ is generated
resources/
  css/                  Tailwind entry point (app.css)
  js/
    app.tsx             Inertia entry point
    pages/              Inertia pages: Admin/…, Auth/…, Customer/…, Storefront/…, Error, StoreClosed
    layouts/            admin and auth layouts
    components/         shared React components (admin/ holds the admin design system)
    storefront/         storefront runtime: themes/Default, components, consent, events, registry
    lib/                small helpers (for example useTranslation)
    types/              shared TypeScript types for page props
    tests/              Vitest + Testing Library tests
  views/                Blade: the Inertia root document (app.blade.php), email layouts, newsletter, order
                        cancellation and payment pages
routes/
  web.php               storefront, cart, checkout, account; includes auth.php and admin.php
  auth.php              sign-in, registration, password reset, verification, social sign-in
  admin.php             everything under /admin
  webhooks.php          payment provider webhooks (no session, no CSRF)
scripts/                boost-mcp.php (local MCP launcher), theme-apply.mjs
storage/                runtime files; storage/addons holds uploaded add-ons
tests/
  Unit/                 plain PHP tests, including ArchitectureTest
  Feature/              HTTP, database and integration tests
  Benchmark/            optional search benchmark (outside the normal suites)
compose.yaml            local Docker services
Makefile                install / check / up / down shortcuts
```

## Where to put new code

| You are adding… | Put it in |
| --- | --- |
| A business rule | `app/{Context}/Domain/…` (plain PHP) |
| A use case or a port another context calls | `app/{Context}/Application/Command` or `…/Contract` |
| Persistence, a Laravel adapter, a binding | `app/{Context}/Infrastructure/…` and its service provider |
| An admin screen | Controller in `app/Admin/Controllers`, route in `routes/admin.php`, page in `resources/js/pages/Admin/…`, menu entry through `AdminNavigation` |
| A storefront page or block | `app/Storefront/…` and `resources/js/storefront/themes/Default` |
| Interface text | `lang/en/{area}.php` and the other five language folders |
| A database change | A migration in `database/migrations` (add-ons ship their own) |
| An optional feature | An add-on under `modules/{vendor}/{name}` or an uploaded package: see [Creating an add-on](../creating-an-addon/) |

Generated and local-only paths are ignored by Git: `vendor/`, `node_modules/`, `public/build/`, `.env` and runtime
`storage/` contents.
