# Phase 0 final architecture review

Reviewed 2026-10-08. Phase 0 is complete within development-foundation scope; production deployment and future product phases are not claimed complete.

## Before / after

Before: documentation/skills-only repository, no Laravel, dependencies, schema or tests. The original audit is [audit.md](audit.md); earlier overview/AGENTS decisions had been committed before this resumed pass. Existing work and tracked Finder metadata were preserved.

After: plain Laravel 13.35 / Inertia Laravel 3.5, React/Inertia client 3.8, TypeScript 6, Tailwind 4, PHP 8.4 Docker development, PostgreSQL 18 and Redis 7.4; exact versions in lockfiles. A neutral page and liveness endpoint run locally. Composer resolution targets PHP 8.3; execution verified on PHP 8.4.

The user freed host storage. Docker required process recovery; the previously approved unused build-cache prune reclaimed 22.34 GB. No images, containers' data volumes or unrelated project files were deleted.

## Ten-year boundary review

| Risk | Resolution / remaining gate |
| --- | --- |
| UI/domain coupling | Core cannot depend on Admin/Storefront; transport-specific props/controllers stay outside Core |
| Optional dependency inversion | Core never imports optional module classes; module dependencies declared and cycle checked |
| Activation bypass | Explicit approved/enabled lists; CMS packages cannot declare provider auto-discovery; trust remains deployment responsibility, not a sandbox |
| Hidden schema changes | No boot-time migrations; explicit tracked deployment lifecycle, no disabling rollback or implicit purge |
| Duplicate stock truth | ProductVariation owns SKU/stock/base price; option combinations validate customization only |
| Generic attributes/data blobs | Features/options/values/assignments remain distinct and relational; no copied CS-Cart schema |
| Tenant scope assumption | Single-store v1, no universal store_id; future multi-store is an explicit migration project |
| Money/JS precision | Exact minor units/currency and decimal rates; browser IDs/amounts strings; tax rounding remains a gate |
| Storefront lock-in | Independent future Inertia/theme layer, shared core operations, no layout-builder database |
| Speculative abstractions | No repositories/CQRS/bus/hook engine, placeholder models, generic UI registries or fake addons |
| Dependency compatibility | PHP 8.3 Composer resolution prevents accidental PHP 8.4-only lock; TypeScript 6 matches supported ESLint range |
| Test code in shipped pages | Frontend tests live outside Inertia page discovery; production manifest contains only real page/entry assets |
| Diagnostic exposure | Inertia prop recorder/DevTools and SSR explicitly off for foundation; only / and /up registered |
| Operational isolation | Separate PostgreSQL application/test roles/databases; guard before destructive test refresh; internal DB/Redis ports |

## Verified evidence

- make check: Pint, Larastan/PHPStan level 6, 13 PHPUnit tests / 35 assertions, strict TypeScript, ESLint, Prettier, one Vitest test and Vite production build passed.
- Docker-only frontend clean dependency-volume install/check also passed.
- Composer manifest/lock validation and PHP/JavaScript advisory checks passed.
- PostgreSQL migrations run; application database contains only migrations and failed_jobs.
- Real Redis connectivity and isolated PostgreSQL migration tests passed.
- HTTP / and /up returned 200; root response has Foundation Inertia props and built assets. Login/register/admin/catalog/API paths remain absent.
- Compose configuration, workflow YAML and local documentation links checked; git diff --check clean.

GitHub Actions is configured, not executed remotely. No browser automation surface was available for a full browser/visual check; HTTP contracts, component rendering and asset compilation were verified instead. No production readiness, third-party addon compatibility or PHP 8.3 execution test is implied.

## Decisions and handoff

See [decision register](decisions.md), [domain model](domain-model.md), [database strategy](database-principles.md), [module contract](module-system.md), [admin conventions](admin-architecture.md), [roadmap](development-phases.md) and [setup](../development.md).

Before Phase 1 choose authentication/permission tooling, staff enrollment/initial administrator bootstrap, recovery/verification/MFA scope and admin component/design conventions. Before later phases resolve exact feature/option/variation rules, stock policies, currencies/tax/rounding/discounts, theme SSR/packaging, module lifecycle persistence/distribution/assets and audit retention.

Skills kept their six focused responsibilities and reference files; no skill duplication or fake skills. Root AGENTS remains concise with phase limits, stack/global boundaries, relevant-skill routing and links to project rules.

No authentication, admin shell, catalog CRUD/models, storefront, cart, checkout, orders, payments, REST API or page builder was implemented. No future ecommerce migrations/factories/seed data. Phase 1 requires separate authorization.

## Changed files

The following inventory covers this resumed pass; earlier committed overview/AGENTS/audit groundwork is described above. Generated .env, dependencies, caches and builds are ignored, not source deliverables.

```text
.dockerignore
.editorconfig
.env.example
.gitattributes
.github/workflows/quality.yml
.gitignore
.prettierrc.json
LICENSE
Makefile
README.md
THIRD_PARTY.md
app/Core/Modules/ModuleGraph.php
app/Core/Modules/ModuleManifest.php
app/Core/Modules/ModuleServiceProvider.php
app/Http/Controllers/FoundationController.php
app/Http/Middleware/HandleInertiaRequests.php
artisan
bootstrap/app.php
bootstrap/cache/.gitignore
bootstrap/providers.php
compose.yaml
composer.json
composer.lock
config/app.php
config/cache.php
config/database.php
config/filesystems.php
config/inertia.php
config/logging.php
config/mail.php
config/modules.php
config/queue.php
config/services.php
config/session.php
database/migrations/2026_10_08_000000_create_failed_jobs_table.php
docker/php/Dockerfile
docker/postgres/init-testing.sql
docs/architecture/admin-architecture.md
docs/architecture/api-policy.md
docs/architecture/audit.md
docs/architecture/database-principles.md
docs/architecture/decisions.md
docs/architecture/development-phases.md
docs/architecture/domain-model.md
docs/architecture/extensions.md
docs/architecture/initial-schema.md
docs/architecture/module-system.md
docs/architecture/observability.md
docs/architecture/phase-0-review.md
docs/architecture/security.md
docs/architecture/storefront-architecture.md
docs/development.md
docs/project-structure.md
eslint.config.js
package.json
phpstan.neon
phpunit.xml
pint.json
pnpm-lock.yaml
public/.htaccess
public/index.php
public/robots.txt
resources/css/app.css
resources/js/app.tsx
resources/js/pages/Foundation.tsx
resources/js/tests/Foundation.test.tsx
resources/js/tests/setup.ts
resources/views/app.blade.php
routes/web.php
storage/app/private/.gitignore
storage/framework/cache/data/.gitignore
storage/framework/sessions/.gitignore
storage/framework/views/.gitignore
storage/logs/.gitignore
tests/Feature/FoundationTest.php
tests/Feature/InfrastructureTest.php
tests/TestCase.php
tests/Unit/ModuleContractTest.php
tsconfig.json
vite.config.ts
vitest.config.ts
```
