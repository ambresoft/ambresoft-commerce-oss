# Current project structure

Phase 3A's consumed importer lives under `Catalog/Application/Contract`, `Catalog/Application/Command` and `Catalog/Infrastructure/Import`. Its browser controller is `Admin/Controllers/CategoryImportController.php`; its localized Inertia page is `resources/js/pages/Admin/Categories/Import.tsx`. `Catalog/Presentation/Console/PruneCategoryImports.php` provides scheduled private-upload cleanup. `config/category-import.php` owns parser/size/expiry limits. The canonical field definition, not a separate frontend list, drives headers, template and mapping. See [import contract](architecture/category-import.md).

```text
.agents/skills/             shared project, framework and security skills
.claude/skills/             relative per-skill links to the shared library
.github/workflows/         automated quality workflow
app/
  Identity/Domain/Model/        plain User entity and UserId value object
  Identity/Domain/Repository/   UserRepositoryInterface; no Eloquent
  Identity/Domain/Exception/    duplicate-email business failure
  Identity/Application/Command/ registration/reset handlers and input DTOs
  Identity/Application/Contract/ framework-free integration ports
  Identity/Infrastructure/Persistence/ auth UserRecord, mapper, repository
  Identity/Infrastructure/Auth/        Laravel broker/notification adapters
  Identity/Infrastructure/             binding provider
  Identity/Presentation/Http/          auth controllers/requests, URL/rate policy
  Shared/Infrastructure/Modules/       add-on manifest (contract 2), discovery, runtime loader, ZIP stager, lifecycle manager, extension points
  Shared/Presentation/Console/         cms:addons-sync, cms:addon-validate
  Payment/                 payment method contracts/registry, COD, settings, order payments, public payment page
  Authorization/           pure access rules/commands, persistence/gates, operator commands
  Localization/            pure language-management port/handler, locale records, dictionary overlays, browser resolution
  Catalog/Domain/          plain Category, hierarchy rules, typed category events
  Catalog/Application/     category mutation DTOs/port/handler and transaction participants
  Catalog/Infrastructure/  category/translation records, mapper, hierarchy/search queries
  Media/Infrastructure/    shared private-image storage and relational metadata record
  Shared/Application/     consumed image-upload input contract
  Shared/Domain/           consumed Translatable/LocalizedContent contracts
  Admin/                   dashboard/staff/role/locale/category controllers, requests, navigation
  Http/Controllers/      neutral root page only
  Http/Middleware/        minimal Inertia props and private browser responses
bootstrap/                Laravel application/provider wiring
config/                   Laravel infrastructure and add-on locations/upload policy (modules.php)
database/migrations/      infrastructure/identity/RBAC/locales plus categories/translations/media
docker/                   PHP image, PostgreSQL init and optional pgAdmin connection
docs/architecture/        decisions, domain, boundaries and Phase 0 review
docs/addons.md            add-on developer guide; docs/examples/addons/ holds the example add-on
modules/                  bundled first-party add-ons ({vendor}/{name}, e.g. ambresoft/stripe-checkout), loaded only when enabled
public/                   Laravel HTTP entry point; ignored generated build
resources/
  css/                    Tailwind entry point
  js/pages/               Auth, Admin/Dashboard, Users, Roles, Categories/Import, Settings, root/errors
  js/layouts/             shared auth layout and reusable admin shell
  js/components/          admin/auth controls and accessible notifications
  js/types/               minimal shared/page contracts
  js/tests/               form/navigation behavior and foundation render check
  views/                  Inertia root Blade document; payments/page.blade.php (public payment page)
routes/                   web.php, auth.php, admin.php; /up wired by Laravel
storage/                  runtime paths (contents ignored); storage/addons holds uploaded add-ons
tests/Unit/               pure identity/handler tests, module contracts, architecture checks
tests/Feature/            auth/session/security, application use cases, navigation, infrastructure
```

Composer and pnpm manifests/locks define dependencies. Makefile and quality configs define checks. Compose supplies PHP, PostgreSQL, Redis, local-only Mailpit and optional loopback pgAdmin. Generated public/build, vendor, node_modules, .env and runtime storage are ignored. Phase 2 adds lang/en and lang/el keyed catalogs, shared i18n props, small translation helpers and staff/role screens. Phase 3 adds category UI/catalogs and normalized category data only. No Products, Storefront, installed addon examples or future business placeholders exist.

Authorized follow-ups add focused `Catalog/Infrastructure/Import` streaming/planning adapters behind the consumed Application import port, the Categories import page, six shipped keyed UI dictionaries and Localization language-management/dictionary adapters. `config/category-import.php` bounds CSV work; `config/languages.php` defines the trusted six-language pool. The language migration adds only inactive locale reference rows and `ui_translation_overrides`. See [CSV contract](architecture/category-import.md) and [language contract](architecture/language-management.md); neither creates a Product placeholder or generic import/translation engine.

Each context's Domain/Application is framework-free and depends inward; Infrastructure does not import presentation. Core describes essential ownership, not a namespace: see [context-first DDD](architecture/layered-ddd.md). Admin remains a cross-context presentation shell; future Storefront remains absent. Bundled add-ons live in modules/{vendor}/{name} and uploaded ones in storage/addons; both use the contract-2 manifest ([add-on guide](addons.md)). Use PascalCase PHP directories; create no empty Query/Service or future commerce contexts. Phase 0 review paths and superseded ADRs remain historical, not current conventions.

A101 adds `app/Storefront/{Domain,Application,Infrastructure,Presentation}` for trusted theme/block contracts, grid validation, relational layouts and public rendering. `resources/js/storefront/themes/Default` contains the bundled theme, `pages/Storefront/Home.tsx` is the public homepage and `pages/Admin/Layouts` is its editor. The earlier absence statements describe Phase 0 history. See [storefront foundation](architecture/storefront-architecture.md).
