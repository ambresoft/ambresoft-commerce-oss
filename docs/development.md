# Development and quality checks

## Shared AI skills

The canonical library is `.agents/skills/`. Codex loads it directly; Claude Code loads the same files through relative per-skill symlinks in `.claude/skills/`. Keep `AGENTS.md`, `CLAUDE.md`, `.claude/settings.json`, local settings and MCP configuration in their existing locations. Migration to the shared library preserves skill contents, references, scripts and third-party notices.

`infer-conventions` remains manual-only: its existing Claude `disable-model-invocation` flag is retained, and `agents/openai.yaml` carries Codex's `allow_implicit_invocation: false` policy. The Inertia skill's metadata description uses plain `Link`/`Form` names for Codex validation; its instructions are unchanged. The official skill helper validates 64 skills directly; convention discovery additionally uses Claude's supported frontmatter extension, so its common fields and both invocation policies are checked separately. All 65 skills parse through Boost and resolve through their Claude links in Docker; the relocated security-audit scripts' 65 tests pass.

Edit or update skills under `.agents/skills/`. `config/boost.php` directs Boost's Claude skill writer there so updates do not replace the discovery symlinks with separate copies. Review Boost-generated changes as before; do not run `boost:install` or `boost:update` merely to refresh the links.

**Required for every skill creation, import or update:**

1. Maintain one canonical skill directory in `.agents/skills/` and its relative Claude symlink. Verify that both discovery paths resolve to the same files.
2. Keep shared instructions usable by both tools. Put necessary tool-specific behavior in supported metadata and preserve equivalent invocation restrictions, including manual-only skills.
3. Validate frontmatter and check referenced files and scripts from both paths. Report any tool-specific validation limitation explicitly; do not mark the skill complete with a broken link or an unhandled compatibility issue. The project-wide rule lives in `AGENTS.md`, which `CLAUDE.md` imports.

When adding a new shared skill, create its directory and `SKILL.md` under `.agents/skills/`, then add its Claude link from the repository root, replacing `SKILL_NAME` with the directory name:

```sh
ln -s ../../.agents/skills/SKILL_NAME .claude/skills/SKILL_NAME
```

Commit the canonical files and the symlink together. Remove the matching link when deliberately removing a skill. Keep symlinks enabled in Git on platforms that require extra setup, such as Windows; verify that Claude links resolve after checkout. Reload/restart the coding tool if its skill list has not refreshed.

## Requirements

Docker Desktop/Compose; Node 22.13+ within Node 22 and pnpm 10.24.0 for host frontend commands. PHP/Composer/PostgreSQL/Redis run in Docker; no host PHP required. The plain Laravel skeleton was installed without auth scaffolding. Lockfiles are committed.

## First checkout

From repository root, copy .env.example to .env only if .env does not already exist, then:

```sh
make install
docker compose run --rm --no-deps app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan cms:permissions-sync
docker compose run --rm app php artisan cms:addons-sync
pnpm build
docker compose up -d app
```

After every deployment or update, run `php artisan migrate`, `php artisan cms:permissions-sync` and `php artisan cms:addons-sync` (installs new bundled add-ons disabled and applies upgrades of bundled add-ons; enabled states never change). Keep `storage/addons` (uploaded add-ons) persistent across deployments; set `ADDON_UPLOADS=false` on installations where add-on code may only arrive through deployment. See the [add-on guide](addons.md), [payments](architecture/payments.md) and [shipping](architecture/shipping.md).

Visit http://localhost:8000/admin/login (staff) or /login (customers) and /up (liveness). Authentication, staff/role administration, persisted admin language and Phase 3 categories are implemented; no Products/storefront. No default user credentials exist.

## FrankenPHP application server

The Docker PHP image uses FrankenPHP 1.13.5 with PHP 8.4 on Debian Bookworm. The app runs in classic mode: Laravel boots for each request, preserving the existing request-local service, locale and authorization lifecycle. Octane and persistent application workers are not enabled. Queue workers and the scheduler retain their existing Artisan commands and use the same PHP image/extensions.

`docker/php/Caddyfile` serves only `public/` over HTTP on container port 8000, with 12 PHP threads. Compose still publishes it on loopback at `http://localhost:8000` (or `APP_PORT`). The Caddy administration endpoint and automatic HTTPS are disabled for this local setup. Static assets are served by Caddy; Laravel handles application routes. Streaming notifications retain their uncompressed response path. Existing upload and Mailpit PHP settings stay mounted. The app healthcheck uses Laravel’s `/up`; CLI worker/scheduler containers disable the inherited web healthcheck.

To update an existing local installation:

```sh
docker compose build app worker scheduler
docker compose up -d --no-deps app worker scheduler
```

Use the same Compose project name as the existing installation (`-p cms-foundation` on the established local workspace). This recreates only the PHP services; databases, Redis and their volumes stay in place. No migration or key generation is needed. Rebuild after editing the Caddyfile because it is copied into the image. Production HTTPS, domains and proxy trust require deployment-specific configuration; this loopback Compose setup remains local development infrastructure.

Compatibility verification (2026-11-12): 1,485 backend tests / 159,992 assertions, 652 frontend tests, PHPStan, PHP/JS lint and formatting, production build and both dependency audits passed. Live HTTP checks compared public/auth/cart/search routes, redirects, cookies, static asset bytes, CSRF rejection and private-file protection before/after the server change; valid cookie-backed CSRF and concurrent visitor language isolation also passed. The final app healthcheck passes and queue/scheduler processes run their original commands. The CI HTTP smoke commands were exercised locally; remote CI execution is not claimed. The large reporting fixture now runs `ANALYZE` on its own transactional bulk data so PostgreSQL has representative planner statistics; production reporting behavior is unchanged.

## Local authentication and email

Mailpit starts with the app through Compose. Open http://localhost:8025 to read locally captured registration-verification and password-reset emails. SMTP is internal at mailpit:1025; only the web inbox is published on loopback. `.env.example` uses this local SMTP configuration, no production vendor credentials. For an older `.env`, set `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`, `MAIL_FROM_ADDRESS=hello@cms.test` and an appropriate `MAIL_FROM_NAME`. Keep `APP_URL` consistent with the actual browser port before sending emails.

For a new installation, run `docker compose exec -T app php artisan cms:admin-setup YOUR_EMAIL --name="Your name"` after synchronizing permissions, then open the private setup email in Mailpit. The first super admin registers through this one-use link. Existing installations retain their accounts and grants; public `/admin/register` is closed. Staff are invited through Access management → Users, or created there with an administrator-set password. Staff management is super-admin-only by default; super admins delegate it through roles, including the separate password/recovery-email capability. Migration 2026_10_22_000200 removes the legacy non-super management grants once. New invitation emails use store branding and an embedded logo. `cms:admin-grant YOUR_VERIFIED_EMAIL` remains operator recovery for an existing verified identity. Passkeys can be registered through an invitation or account menu → Passkeys; set `APP_URL` to the exact canonical browser origin (HTTPS in production). Phase 2 uses persisted roles/permissions behind the stable access-admin gate; ADMIN_STAFF_EMAILS is obsolete and ignored. Run cms:permissions-sync after migrating and when enabled module capabilities change. Permission synchronization and `cms:admin-grant` never create accounts or passwords; the private setup command reserves the first-owner identity. Identities without staff roles cannot sign in through `/admin/login`; protected pages still enforce persisted capabilities. See [authorization](architecture/authorization.md) for recovery/safety and [localization](architecture/localization.md) for language preferences.

Login/registration rotate sessions; logout invalidates them. Reset links expire in 60 minutes and are single-use. Email verification requires a signed-in owner; if opened in a different signed-out browser, sign in then reopen the email link. See [authentication architecture](architecture/authentication.md) for route/security details.

This Mac's Docker ARM image layer cache rejected the Mailpit pull with `failed to register layer ... file exists`. The official `linux/amd64` image for the same pinned release was pulled and runs under Docker emulation without deleting any image, volume or global cache. Fresh installations use their native platform; no platform workaround is hardcoded in Compose. Repairing the local Docker cache is an operational follow-up, not an application change.

Generate APP_KEY only on first initialization; do not rotate an existing key casually. PostgreSQL initialization creates cms/cms_test with distinct non-superuser credentials; they cannot connect to each other's databases. The init SQL runs only on a new project volume. Do not fix an existing volume by deleting it blindly. Compose's postgres administrative account is local-only; do not copy it to production.

PostgreSQL/Redis are internal only, app/Vite bind to loopback. Use APP_PORT in .env if 8000 is occupied. Vite currently uses fixed port 5173; keep VITE_PORT at 5173 unless also changing vite.config.ts. This is a developer server/container, not a production deployment.

For live reload, run pnpm dev in a separate terminal. Stop it before checking the built version; a stale public/hot marker points Laravel at a stopped Vite server. Rebuild after frontend changes when using the built version.

### Docker-only frontend alternative

The node tools profile has its own node_modules volume, separate from host native dependencies:

```sh
docker compose run --rm node sh -lc 'corepack enable && pnpm install --frozen-lockfile && pnpm build'
docker compose up node
```

Use the same container prefix for pnpm check instead of host pnpm. Do not copy native node_modules between macOS and Linux. Either route is supported; the Makefile's frontend commands use host Node.

## Verification

```sh
make check
docker compose run --rm --no-deps app composer validate --strict
docker compose run --rm --no-deps app composer audit
pnpm audit
git diff --check
```

Backend: Pint (check), Larastan/PHPStan level 6 and PHPUnit. Frontend: strict TypeScript, ESLint/hooks rules, Prettier, Vitest/Testing Library and Vite production build. Use composer format in the app container and pnpm format to fix formatting. CI repeats make install/check, boots the FrankenPHP services for HTTP smoke checks (health, login, assets and private-file protection), and runs dependency audits with the same Docker database configuration; CI publication is separate from local verification. Composer resolves against PHP 8.3; local tests run PHP 8.4.

The complete backend suite can exceed Composer's default 300-second subprocess timeout locally. The Composer test script disables that timeout for PHPUnit, so ordinary `composer check` completes the entire suite. Lint and static analysis retain Composer's normal timeout. Tests can also run directly with `docker compose run --rm --no-deps app php artisan test --compact` after lint and static analysis.

Frontend security overrides in `package.json` raise vulnerable minimatch 10 to 11 and enforce patched undici 7 / zod 4 releases. The lint tools still declare minimatch 10, so keep that override until their supported dependency ranges adopt a patched release. Type checking, lint, frontend tests and the production build validate this combination.

Tests use cms_test with forced PostgreSQL credentials and an application-boot guard before any RefreshDatabase setup; no SQLite substitution. Database tests migrate only the isolated test database. Web tests use array sessions/cache and synchronous jobs; the infrastructure test checks real PostgreSQL and Redis (DB 14/15, test prefix, random expiring keys, no FLUSHDB). Do not point tests at a merchant database.

The architecture suite checks PascalCase context-first PHP directories, inward dependencies and framework-free Domain/Application code. Plain PHPUnit identity tests boot neither Laravel nor a database; integration tests validate Infrastructure mapping and supported auth adapters. Direct identity-use-case tests cover registration without HTTP/session side effects, repository substitution and uniqueness translation, and password reset broker semantics. Follow [layered DDD](architecture/layered-ddd.md) when adding classes. After namespace changes, run `docker compose exec -T app composer dump-autoload --optimize` and `docker compose exec -T app php artisan optimize:clear`; restart any long-lived workers. Do not rotate APP_KEY or recreate the database for a code-layout change.

No cosmetic catalog data is seeded. The Phase 2 migration initializes required en/el locale reference rows; Phase 3 initializes exactly the protected Uncategorized recovery category. cms:permissions-sync idempotently creates missing permission definitions and reference roles without overwriting grants. Explicit operator bootstrap grants an existing verified identity. Tests construct only scenario-specific staff/category data in cms_test.

## Category administration

Product availability labels: apply `2026_10_15_000800_add_product_availability_statuses`, run ordinary `cms:permissions-sync`, and rebuild with `pnpm build`. Assign `availability-statuses.manage` to staff who manage the shared labels. Products → Availability statuses manages the six initial editable labels and defaults; each product selects both labels in Pricing & stock → Stock. Deleting a used label requires a replacement and invalidates affected product editors. [Availability contract](architecture/product-availability.md).

Apply the additive category migration using `docker compose exec -T app php artisan migrate`, then `pnpm build` (or use the existing Vite dev server). Existing accounts, grants, key and credentials are unchanged. Staff with categories.view see Categories at `/admin/categories`; create/update/delete permissions independently protect actions. No permission upgrade is needed if the existing reference grants were already applied.

Create a root or child, enter a positive numeric sibling position, and use independent English/Ελληνικά tabs. A blank slug derives from the submitted name; duplicate language-scoped slugs are rejected. Search respects the current admin language and its visible fallback. Use Edit to move/reposition; parent categories cannot be deleted until children are moved/deleted. Uncategorized has no normal editing/deletion actions. Image uploads are private JPEG/PNG/WebP originals (5 MiB, 6000×6000 maximum); replacing/removing detaches the previous private original. No general media library or automatic purge exists. See [category operations and future Product contract](architecture/categories.md).

The permission-only Phase 2 follow-up adds product/category CRUD and order view/update definitions, without their features. To apply their documented reference-role grants to an existing installation, explicitly run `docker compose exec -T app php artisan cms:permissions-sync --grant-commerce-defaults`. This adds grants without removing custom permissions or assigning users; ordinary synchronization still leaves existing role grants unchanged. Review the matrix and existing membership in [authorization](architecture/authorization.md) first.

## Runtime operations

### Product administration and queued private-file cleanup

After pulling Phase 4, run `docker compose exec -T app php artisan migrate` and `docker compose exec -T app php artisan cms:permissions-sync`. No demo products or accounts are seeded. Assign the new lifecycle permissions explicitly through Roles; existing super administrators inherit their normal bypass.

Start `docker compose up -d --build worker scheduler`. The scheduler dispatches committed file-cleanup intents each minute; the Redis maintenance worker processes them with retries. Keep both running whenever files can be detached or products permanently deleted. Inspect `docker compose logs worker scheduler` and `docker compose exec -T app php artisan queue:failed`. Manual dispatch is `docker compose exec -T app php artisan cms:product-files-cleanup`; it does not delete bytes inline. Restart workers after deploying code. [Product lifecycle and limits](architecture/products.md).

Product prices are decimal strings in the primary currency, accepted at its configured decimals (Settings → Currencies; EUR with 2 decimals initially). Review the primary currency before production use: changing it later converts every stored price. Private PDF/ZIP/TXT uploads default to 10 MiB per file, within PHP's aggregate 22 MiB POST limit; upload larger collections across successive saves. Normal product saves require complete base fields even when adding files. ProductTest and ProductFileConcurrencyTest run only against the dedicated PostgreSQL test database; never point testing at cms.

### Currencies (Phase 4.1)

After pulling, run `docker compose exec -T app php artisan migrate` (adds `currencies`/`currency_audits` and seeds EUR as the single primary; existing prices are unchanged) and `docker compose exec -T app php artisan cms:permissions-sync` (registers seven `currencies.*` capabilities without granting them). Assign them through Roles. Settings → Currencies manages secondary currencies, manual exchange rates (units per 1 primary unit), formatting and status; making another currency primary requires `currencies.primary`, a typed code and converts all stored prices in one transaction. No external rate provider, storefront selector, tax or payment-currency handling exists. [Currency contract](architecture/currencies.md).

### Shared CSV upload UI

Category and Region imports use the default `CsvImportUpload`, `CsvImportSteps` and `CsvImportInstructions` components. Reuse them for every new admin CSV upload; supply server-configured file limits and feature-specific settings, preview and confirmation. [UI contract](architecture/admin-architecture.md#default-csv-import-ui).

### Category CSV import

After pulling the Settings → General follow-up, run `php artisan migrate` (adds `general_settings`; products still on the Phase 7 default `N` begin following it, so behavior is unchanged) and `php artisan cms:permissions-sync` (registers `settings.manage` without granting it). [General settings](architecture/general-settings.md).

After pulling Phase 8 (taxes), run `php artisan migrate` (adds the tax, rate-area and product-tax tables and the tax settings columns; no existing product, price or setting is changed and no tax is assigned) and `php artisan cms:permissions-sync` (registers seven `taxes.*`/`rate-areas.*` capabilities without granting them). Assign them through Roles, then define rate areas and taxes under Settings. [Taxes](architecture/taxes.md).

After pulling Phase 9 (customers), run `php artisan migrate` (adds customers, addresses, customer groups and memberships; no staff account, product or setting changes and no customer is created) and `php artisan cms:permissions-sync` (registers six `customers.*`/`customer-groups.*` capabilities without granting them). Customer sign-in is not part of this phase. [Customers](architecture/customers.md).

Optional fictitious sample customers (eight people with Greek addresses, one without an address, one disabled, one tax exempt, three groups) are created locally with `php artisan cms:customers-demo 1` (an authorized staff user ID). It refuses production, uses example.test emails, is idempotent and preserves edits; an existing default group is respected. [Customers](architecture/customers.md).

Website → Pages needs `cms:permissions-sync`; assign `pages.view`/`pages.manage`. Three sample pages (privacy policy, returns, contact us with a form): `docker compose exec -T app php artisan cms:pages-demo 1` (local only, idempotent). [Pages](architecture/website-pages.md).

Marketing → Blog (blogs and blog posts) needs `php artisan migrate` (`2026_11_14_000100_create_blogs`, `2026_11_14_000200_add_blog_layouts`) and `cms:permissions-sync`; assign `blog.view`/`blog.manage`. A blog is served at `/blog/{blog}` with the **Blog** layout and lists posts that are active, published (publication date passed; empty = draft) and available; a post is at `/blog/{blog}/{post}` with the **Blog post** layout, canonical URL and Open Graph tags. Hidden blogs/posts are reachable by address only. Staff with `blog.view` preview anything with `?preview=1` (not indexed). Active blogs appear on `/sitemap`. Sample content: `docker compose exec -T app php artisan cms:blog-demo 1` (local only, idempotent: two blogs, eight Greek/English posts with generated covers). Website → Layouts offers a **Blog posts** block (carousel or single post; newest posts of all blogs or one blog, or posts picked by hand) after migration `2026_11_14_000300_add_blog_block_selections`. Catalog → Categories → **Blog posts** tab (needs `blog.view`, migration `2026_11_14_000400_create_category_blog_posts`) links posts to a category in order; the category page shows the public ones as "Related articles" above its questions.

Website → Layouts offers separate **Coupons** and **Offers** carousel blocks after migration `2026_11_19_000100_add_deal_block_selections`. Each uses an ordered manual picker and the shared carousel settings, with an optional title icon. Offers select automatic Marketing → Promotions rules; coupon-linked rules appear with their coupon. Public coupon cards show the code, never the internal staff name. Expired, disabled, exhausted and customer-restricted items are hidden; actual cart eligibility and discounts remain with checkout. No block is added to an existing layout automatically.

Carousel blocks offer **Hide carousel arrows on mobile**, off by default. It hides only arrow buttons below 768px, preserving pagination dots and touch swiping. Banners, products, categories, blog posts, brands, brand products, image menus, coupons and offers share the setting; product-page recommendations use the same control under Main Content → Options. Existing saved blocks need no migration.

Website → SEO Rules (URL redirects only) needs migration `2026_11_15_000100_create_seo_redirects` and `cms:permissions-sync`; assign `seo-rules.view`/`seo-rules.manage`. Old addresses are stored normalized (lowercase, decoded, NFC, no trailing or repeated slashes) and unique as matched; destinations are store paths or public http(s) URLs (no credentials, IPs or bare hosts). Self-redirects and loops through enabled rules are refused. `ApplyRedirects` runs as global middleware before routing for GET/HEAD only, so it also catches addresses that would 404; it follows chains to the end in one hop, ignores loops, keeps the visitor's query (destination parameters win) and never touches `RedirectRules::RESERVED` prefixes (admin, auth, cart, checkout, payments, account, storefront endpoints, assets, `robots.txt`). A test fails when a new route prefix is neither reserved nor reviewed as a redirectable page. No robots.txt handling is part of this.

Website → FAQs needs migration `2026_10_15_000600_create_faqs`, `cms:permissions-sync` and `pnpm build`. Assign `faqs.view/manage/generate` and `ai.logs.view` deliberately; `settings.manage` protects AI configuration. AI configuration lives in Settings → General → AI settings; AI Log is under Logs. The additive `000700_share_ai_settings_and_history` migration preserves existing credentials, call history and explicitly assigned log grants. Category, product and page editors have ordered FAQ linking tabs. Manual editing needs no key; optional generation starts disabled and uses an encrypted, write-only merchant Anthropic key. Four labeled samples: `docker compose exec -T app php artisan cms:faqs-demo 1` (local only, idempotent, preserves edits). No samples are linked automatically. [FAQ contract and API limits](architecture/faqs.md).

Marketing → Banners needs `cms:permissions-sync` after migrating; assign `banners.view`/`banners.manage` through Roles. Background videos are MP4 up to 16 MB and images PNG/JPEG/WebP up to 5 MB. Two sample hero banners: `docker compose exec -T app php artisan cms:banners-demo 1` (local only, idempotent). [Banners](architecture/banners.md).

Shipping countries and regions (nomoi) are managed under Shipping & Taxes. Region CSV import is available through Imports / Exports (`/admin/data-transfer`) or the Regions CSV icon, using existing `regions.manage`. Download the current template or see the [example CSV](examples/region-import.csv) and [import contract](architecture/geography.md#region-csv-import). No additional migration or permission synchronization is required for this import. The scheduler runs `cms:region-import-prune` hourly to clean expired private uploads. To enable Greece and load its 51 nomoi run `docker compose exec -T app php artisan cms:geography-greece 1` (an authorized staff user ID); it is idempotent and keeps edits. [Geography](architecture/geography.md).

Optional sample tax data for Greek addresses is created locally with `docker compose exec -T app php artisan cms:taxes-demo 1` (replace `1` with an authorized staff user ID). It refuses production and creates, once, three "(sample)" rate areas (Greece, Greek Aegean islands by ISO 3166-2 region K/L, and the default rest-of-world area), three sample taxes (standard and reduced VAT, included in price, and a fixed recycling fee) and assigns the standard sample VAT to the Phase 5 demo products if present. An existing default area is reused; later runs change nothing and keep your edits. The figures are illustrative, not legal rates: review or delete them before real use. [Taxes](architecture/taxes.md).

Language administration is available under Settings → Languages with `languages.manage`. The header selector offers only saved active languages for each user's personal UI preference. English/Greek settings are preserved; Spanish/French/Italian/Dutch start inactive. Dashboard editing requires complete localized content in every active language when more than one is active. CSV imports may supply just one active language and leave others empty for later completion; inactive-language columns are rejected. Missing path parents are created disabled in the system default language. [Language and dictionary workflow](architecture/language-management.md).

Open the CSV icon on Categories with `categories.import` (plus create/update capabilities for the requested operations). Download the live template; [format and limits](architecture/category-import.md) and an invented [sample CSV](examples/category-import.csv) are documented. Uploading only previews; confirmation writes atomically. No additional migration or default accounts are needed. Run ordinary `cms:permissions-sync` to register the capability without changing role grants.

The local app mounts `docker/php/uploads.ini` (20 MiB upload, 22 MiB POST, 180-second PHP limit). Recreate only the app container after changing it; do not delete volumes. Production PHP/proxy/timeouts need equivalent explicit configuration and measurement. Private uploads expire after one hour. Run Laravel's scheduler in deployment; the hourly `cms:category-import-prune` removes only expired, unowned import uploads after a grace period. Manual cleanup: `docker compose exec -T app php artisan cms:category-import-prune`. Never log the full CSV or use public storage for import files.

### Optional PostgreSQL dashboard

Use pgAdmin rather than phpMyAdmin: this project uses PostgreSQL, not MySQL/MariaDB. The optional `db-admin` Compose profile uses the [official pgAdmin container](https://www.pgadmin.org/docs/pgadmin4/9.18/container_deployment.html), pinned to 9.18. Set `PGADMIN_EMAIL` and a unique `PGADMIN_PASSWORD` in your ignored `.env`, then run:

```sh
docker compose --profile db-admin up -d pgadmin
```

Open http://localhost:5050 and sign in with those pgAdmin credentials. Expand **Local development → CMS local database**; when prompted for the database password, use `DB_PASSWORD` from `.env` (`local-only-cms` on a default local installation). The preloaded connection uses host `postgres`, port `5432`, database `cms` and user `cms`, not the PostgreSQL superuser. pgAdmin login and database login are separate credentials. PostgreSQL remains internal; only pgAdmin's web interface binds to loopback.

The `pgadmin-data` volume persists dashboard accounts/settings independently of application data. Changing `PGADMIN_PASSWORD` after first initialization does not reset an existing pgAdmin account; change its password through pgAdmin instead. Do not delete volumes to reset credentials. Stop only the dashboard with `docker compose stop pgadmin`. This tool can edit/delete real local data; use migrations for application schema changes. Never expose this local setup publicly or reuse its credentials in production.

Local accounts are not seeded by migrations or committed code. A manually provisioned development staff account, if requested, is restricted to this installation and uses the persisted staff-role gate. Credentials are supplied privately in the task handoff, not stored in project documentation.

Inspect docker compose logs app postgres redis, artisan migrate:status and artisan queue:failed. A worker may later run artisan queue:work redis --tries=3 --timeout=60; no worker or fake job needs to run for Phase 0. Set retry_after above worker timeout before real work. Redis queues default to after_commit=true. Use unique prefixes per deployment; shared Redis must not evict durable queue/session state. Consider separate Redis instances when eviction/availability policies diverge.

Use artisan optimize:clear before tests after experimenting with config/route caches. Rebuild deployment caches and restart workers when module activation changes. Disable does not roll back module schema. Never run compose down -v unless intentionally deleting this project's data with approval; ordinary make down preserves it.

Production deployment, backups/restore drills, HTTPS, non-development PHP runtime, secret management and infrastructure hardening require a later deployment task. [Security](architecture/security.md), [module contract](architecture/module-system.md).

### Product features (Phase 5)

Apply `docker compose exec -T app php artisan migrate`, then `docker compose exec -T app php artisan cms:permissions-sync` and `pnpm build`. Assign the seven `features.*` capabilities through Roles as needed; ordinary sync preserves grants. Products → Features contains definitions/options, groups, ordering and required-value remediation. Product values are edited in the new Features tab.

After pulling the Feature Purpose refinement, run `docker compose exec -T app php artisan migrate` and `pnpm build`. Existing features become Standard Attributes. Any former feature slug/SEO values are retained as `seo_retired` feature audit records. No permission change is needed, because purpose and variant landing metadata use `features.update`. See [Feature Purpose](architecture/features.md#feature-purpose-and-variant-landing-page-data-a61).

The explicitly requested local demo is seeded with `docker compose exec -T app php artisan cms:features-demo 1`, replacing `1` with an existing authorized staff user ID. It refuses production, only supports the curated English/Greek active-language set, uses stable mapping keys, preserves existing data and administrator edits, and creates ten products only once. It never changes the primary currency. [Feature rules, lifecycle, routes, data and verification](architecture/features.md).

### Central audit ledger

Apply audit migrations 001300/001400 with `docker compose exec -T app php artisan migrate`, then `docker compose exec -T app php artisan cms:permissions-sync` and rebuild (`pnpm build`). Restart workers. Super administrators can open `/admin/audit-logs`; other staff cannot access audit history, even with a legacy `audit.view` grant. Viewing audit history generates no activity event. No existing grants/history/catalog rows are overwritten. Retention is disabled unless explicitly configured and invoked. [Audit API, coverage and operator policy](architecture/audit-logging.md).

### Company data

Apply `docker compose exec -T app php artisan migrate` and `pnpm build`. Staff with existing `settings.manage` access can open Settings → Company data. The additive migration creates an empty company record and does not copy any sample merchant details. All fields are optional; contact emails are stored without changing mail delivery. [Fields, validation, audit and concurrency](architecture/company-settings.md).

### Website menus

Apply `docker compose exec -T app php artisan migrate`, run `docker compose exec -T app php artisan cms:permissions-sync`, and rebuild with `pnpm build`. Assign `menus.view`/`menus.manage` through Roles. Website → Menus manages named header/footer/other menus, manual nested links, category-generated trees and optional private item images. No merchant sample menus are seeded, and storefront rendering stays deferred. [Menu rules, integrity, permissions and audit](architecture/website-menus.md).

### General settings tabs and storefront closure

Apply `docker compose exec -T app php artisan migrate` and build with `pnpm build` after the General settings expansion. No new permissions are needed. Settings → General opens Basic settings first. Closing the storefront shows the saved per-language plain-text message at `/` with HTTP 503; `/login`, administration and `/up` remain available. Opening restores the current foundation page. Weight-factor changes convert saved product weights and reject open editors with old versions. The comparison and coupon switches are saved for future storefront/checkout consumers. [General settings contract](architecture/general-settings.md#basic-settings-and-grouped-preferences-a81).

### Orders, statuses and order emails (Phase 9)

After pulling, run `docker compose exec -T app php artisan migrate`, then `docker compose exec -T app php artisan cms:permissions-sync` (add `--grant-commerce-defaults` to give the reference Store Admin/Order Manager roles the new order capabilities). The migration seeds six statuses (Open, Processing, Completed, Cancelled, Failed, Incomplete) with templates in every language, without duplicates.

Customer emails use the method chosen in Settings → General → Emails (PHP `mail()` by default, or SMTP / sendmail). Set the sender there first (Company contact emails are not used for sending); without a sender every email is recorded as failed and can be retried from the order's history. Locally, `docker/php/mail.ini` (mounted by `compose.yaml`) points PHP's `sendmail_path` at a small shim that hands messages to Mailpit, so they appear at http://localhost:8025; recreate the app container (`docker compose up -d app`) after pulling. Production must configure a real `sendmail_path` (Postfix, msmtp, …). Tests always use a recording transport and never send mail.

Optional local sample data: `docker compose exec -T app php artisan cms:orders-demo USER_ID` creates one fictitious order per status through the normal service (with real stock movement and emails to the sample customers' example.test addresses). Reruns only add orders for new statuses.

To review every order email in a real inbox, run `docker compose exec -T app php artisan cms:order-emails-test you@example.com --locale=el` (add `--from=` if no sender is configured). Locally these land in Mailpit; real delivery needs the server's sendmail/MTA.

### Call requests

Apply `docker compose exec -T app php artisan migrate`, run ordinary `docker compose exec -T app php artisan cms:permissions-sync`, and rebuild with `pnpm build`. Orders → Call requests is available to staff with `call-requests.view`; grant `call-requests.manage` and `call-requests.delete` separately through Roles. No sample customer contacts are seeded. Storefront submission is deferred. [Workflow and privacy contract](architecture/call-requests.md).

## Coupons

Marketing → Coupons and order coupon entry are implemented; see [coupon rules and lifecycle](architecture/coupons.md). Apply migrations and run `cms:permissions-sync` before opening the screens. `CouponTest`, `CouponConcurrencyTest`, `CouponAmountsTest` and the coupon frontend tests cover the feature; run them with the existing order, currency, shipping and audit checks. The public storefront cart remains a later phase. On this checkout, the running local Compose project may still be `cms-foundation`; use `docker compose -p cms-foundation …` for that existing stack rather than starting a second app on the same ports.

Marketing → Gift cards is implemented; see [gift card rules](architecture/gift-cards.md). Apply migrations and run `cms:permissions-sync`; no other setup is needed, but the sender address in Settings → General → Emails must be set for cards to be emailed. `GiftCardTest`, `GiftCardRulesTest` and `GiftCards.test.tsx` cover the feature. The PHPUnit suite runs in one process and outgrows PHP's default 128 MB, so `phpunit.xml` sets `memory_limit` to 512M (`artisan test` runs PHPUnit in a child process that ignores `-d` flags passed to `artisan`).

## Automatic promotions

Apply `2026_10_25_000100_create_promotions` with ordinary `php artisan migrate`, run `php artisan cms:permissions-sync`, assign `promotions.view` / `promotions.manage`, and rebuild using `pnpm build`. Hard-refresh to see Marketing → Promotions alongside Coupons. Existing coupons require no recreation and remain unchanged. Automatic rules integrate into the existing order quote/create/reprice pipeline; this repository does not yet have a public storefront cart or customer checkout. [Rules, calculations, snapshots, migration safety and delivery boundary](architecture/promotions.md).

### Product stickers (admin/backend)

Apply `2026_10_26_000100_create_product_stickers`, run ordinary `cms:permissions-sync`, and rebuild with `pnpm build`. Assign `stickers.view` and `stickers.manage` through Roles; existing grants are preserved. Marketing → Stickers manages definitions and display limits; product editors have a manual Stickers tab. No demo stickers are seeded. The batch evaluator is implemented and tested, while storefront implementation is explicitly deferred to React/Inertia. [Sticker contract](architecture/product-stickers.md).

### Product reviews (admin/backend)

Apply `2026_10_27_000100_create_product_reviews` with `php artisan migrate`, run ordinary `cms:permissions-sync`, and rebuild with `pnpm build` (hard-refresh). Assign `reviews.view`, `reviews.moderate`, `reviews.edit` and `reviews.delete` through Roles; no role is granted them automatically and existing grants are preserved. Catalog → Reviews lists, filters, moderates, corrects and deletes reviews. Customer submission, verified purchase and rating summaries are server operations for the future storefront; no public review route exists yet. [Review contract](architecture/product-reviews.md).

### Customer sign-in (A99)

Apply `2026_10_28_000100_add_customer_accounts` with `php artisan migrate` and rebuild with `pnpm build`. **Staff now sign in at `/admin/login`** (register, password reset and e-mail verification moved under `/admin` too); `/login` and `/register` are the customer pages and lead to `/account`. Existing staff passwords and sessions keep working. A customer granted a staff role must reset to a 15-character password before administration opens. [Authentication](architecture/authentication.md#customer-accounts-a99), [customers](architecture/customers.md#sign-in-accounts-a99).

### Address regions, customer account pages and invitations (A100)

No migration. Rebuild with `pnpm build`. Address region fields are now dropdowns of **Shipping & Taxes → Regions** for shipping countries; add regions there (or run `php artisan cms:geography-greece <actor-id>` for the Greek nomoi) before expecting a dropdown. Existing addresses keep their saved codes until edited. Customers manage profile, addresses, password and reviews at `/account`; staff invite customers from the customer page (*Online account*), which needs working mail delivery. [Customers](architecture/customers.md#self-service-invitations-and-reviews-a100).

## Storefront layouts (A101)

Apply `php artisan migrate` and `php artisan cms:permissions-sync` in the app container, then `pnpm build`. Grant `layouts.view`/`layouts.manage` through Roles. Website → Layouts edits Homepage and Default shared regions. Apply stages block-dialog changes; Save layout persists the whole layout with optimistic concurrency. The migration initializes starter configuration once and creates no catalog content. The public `/` now consumes that configuration through React/Inertia; storefront closure remains supported. [Architecture, theme extension, limits and security](architecture/storefront-architecture.md).

### Demo store

`docker compose exec -T app php artisan cms:demo-store` creates a ready electronics storefront: 6 departments (Phones, Tablets, Laptops, Audio, Wearables, Accessories) with 17 sub-categories, 34 products from fictional brands (Aster, Nimbus, Volta, Orbit, Lumo, Kova) with English and Greek descriptions, some on sale, plus a fully specified "Nimbus Pro 16" showcase (three variants by colour, memory and storage, specification features, a Black Friday sticker and timed sale, accessories and reviews) and three storefront coupons, and real CC0 photos from `database/demo/images` (sources in its `CREDITS.md`). It builds hand-made header menus (a category mega menu plus secondary links), a footer, and a full homepage: banners, quick navigation, new arrivals, categories, deals of the week, six videos, a laptops carousel, customer favourites, coupons, brands, the blog, the newsletter sign-up and a link directory. Cash on delivery is switched on. Product links are the category path plus the product slug (for example `/phones/smartphones/aster-x5`). It is idempotent and runs in local/testing; use `--force` for a client install. Sample slugs that already exist get a `-demo` suffix instead of failing.

`--with-admin` (local/testing only, refused elsewhere even with `--force`) also creates the verified super administrator `admin@example.com` (or `--email=`) with a random password printed once, then runs the newsletter, brand, banner, blog, FAQ, page, customer and order demos as that user so every block has content. Rerunning keeps the account and its password.

### Link-group menus and the favicon

Website → Menus has a **Menu type**: *Link groups* menus are free, manual lists (headings with links under them, two levels, no category links; the server and a database trigger enforce this). Add a **Link directory** block anywhere in Website → Layouts and pick such a menu; it shows columns on desktop and an accordion on phones. `cms:demo-store` adds a sample directory to the homepage. Settings → Company → Logo has a **Favicon** upload (PNG, JPEG or WebP): the storefront uses it, the administration and a store without an upload keep the platform icon. Run `php artisan migrate` (one migration, `2026_10_30_000200_add_link_group_menus_and_favicon`) and `pnpm build`.

### Footer

The **Footer** block lives in the shared Default layout (footer region), so every page shows it; a starter one is created empty and renders nothing until a menu or bottom line is set. Its columns come from a *Link groups* menu: top-level headings are the column titles; second-level items are links or, when a heading without a link, plain text lines (address, opening hours). Menu links may also be `mailto:` and `tel:` addresses (validated). Settings: columns, stacked or accordion on mobile, a line above the footer, a bottom rich-text line per language (for example the copyright), colors, and pages to hide it on. `cms:demo-store` fills in sample columns.

### Footer social links, apps and payment icons

Settings → Company has a **Social & apps** tab (Facebook, Instagram, TikTok, LinkedIn, X, YouTube, Pinterest, App Store and Google Play links; public `https://` addresses only, enforced by the server and a database check) and a **Payment icons** tab (up to 12 pictures, in upload order). The footer block shows them (each part can be switched off in the block) in an extra column and in the bottom line. `{store_name}` and `{year}` in footer column titles, links and the bottom line come from the company settings. Footer title and text sizes are block settings (16 px and 15 px by default). Run `php artisan migrate` (`2026_10_30_000300_add_company_social_apps_and_payment_icons`) and `pnpm build`; `cms:demo-store` fills in sample links and placeholder payment pictures.

### Newsletter sign-up block

Add a **Newsletter sign-up** block in Website → Layouts and choose which newsletter list (Marketing → Newsletters) it registers visitors in; only active lists are offered. Settings: background color, full-width background, title sizes/color, an optional terms-of-use link and pages to hide it on. The form posts to `POST /newsletter/subscribe` (throttled, honeypot field, same answer for known and unknown addresses). The block decides the list, the subscription starts unconfirmed and the list's confirmation autoresponder (if active) sends the confirmation link. Run `php artisan migrate` (`2026_10_30_000400_add_newsletter_block_selection`) and `pnpm build`; `cms:demo-store` adds one to the homepage.

### Customer sign-in and registration look

The customer pages (`/login`, `/register`, password reset, verification, invitation) render in `StoreAuthLayout`: a light, store-branded page that has no header and takes the company name and theme colors from the shared `storeBrand` prop (admin pages get `null` and keep the admin layout and dark theme). Password fields have an eye toggle; there is one registration for every customer and no captcha. No migration is needed; rebuild with `pnpm build`.

Settings → Company → Logo also has an optional **Email logo** for white email backgrounds. All outgoing email templates and previews use it, falling back to the store logo when empty. Apply `2026_10_22_000300_add_company_email_logo` with ordinary `php artisan migrate`, then `pnpm build`. Existing logos remain unchanged; no upload or permission synchronization is required. [Email-logo contract](architecture/company-settings.md#email-logo-a111).

Customer accounts (without a staff role) cannot sign in at `/admin/login`: it answers with the ordinary failed-credentials message and starts no session; a customer who is later granted a staff role can.

### Layout page settings and merchant-added layouts

Apply `2026_10_30_000500_add_layout_settings_and_custom_layouts` with `php artisan migrate` and rebuild with `pnpm build`. In Layouts, the gear beside the active tab edits the page title, meta description, meta keywords and custom head HTML (with *Copy to other layouts*); *Add layout page…* creates a layout and links it to a static page. The homepage `<title>`, meta tags and head HTML come from these settings; a page with its own SEO overrides them (see the storefront architecture notes).

### Product short description, similar products and frequently bought together

Apply `2026_10_31_000100_add_product_short_description_and_recommendations` with `php artisan migrate` and rebuild with `pnpm build`. The product editor gains a short description (General), a *Similar products* switch and a *Frequently bought together* tab (up to three products). The storefront product page that shows them is not built yet; see [products](architecture/products.md).

### Product pages

Apply `2026_10_31_000200_add_product_page_layout` with `php artisan migrate` and rebuild with `pnpm build`. Products open at their category path plus slug (for example `/texh/foriti-ipologhistes/macbook-pro-14-mauro-32-gb-1-tb`). Layouts → **Products** arranges the page; its *Main content* block shows the product and can only be moved. See the storefront architecture notes.


### Blended product pictures

Apply `2026_10_31_000300_add_product_image_blend_setting` with `php artisan migrate` and rebuild with `pnpm build`. Settings → General → Catalog → *Blend product picture backgrounds* (on by default) makes white picture backgrounds take the light grey behind them (CSS `mix-blend-mode: multiply`) on product cards, carousels, the product page, bought-together and variation tiles; the full-screen gallery never blends.

### Product card style

Apply `2026_10_31_000400_add_product_card_style_setting` with `php artisan migrate` and rebuild with `pnpm build`. Settings → General → Catalog → *Product card style* chooses how product cards look in every carousel and product block: **Classic** (the existing card, default) or **Showcase** (large picture, heart and cart button on the picture, reference price, price and a whole-percent discount badge from `discount_percent`). The product blocks keep all their own settings for both styles.

### Brands

Apply `2026_11_01_000100_create_catalog_brands`, run ordinary `cms:permissions-sync`, and rebuild with `pnpm build`. Grant `brands.view`/`brands.manage` through Roles. Catalog → Brands manages localized brand records/images; products select one brand in General, and the product list can filter by brand. Website → Layouts offers **Brands** and **Products by brand**, composing the existing carousel/single-item controls. Bestsellers use all-time fully-paid, non-void units. The optional local/testing-only `php artisan cms:brands-demo <staff-id>` assigns recognizable existing products without overwriting assignments or creating products/orders; generated pictures are replaceable placeholders. No layout placements or product detail changes are installed. [Brand contract, ranking and limits](architecture/brands.md).

### Storefront maximum width

Apply `2026_11_01_000200_add_site_max_width_setting` with ordinary `php artisan migrate` and rebuild with `pnpm build`. Settings → General → Appearance has **Maximum site width (px)**, default 1320 and range 320–3840. Existing sites keep their width; saving a new value changes storefront content containers and the product sticky bar, with no admin width change.

### Back to top button

Apply `2026_11_01_000300_add_back_to_top_setting` with ordinary `php artisan migrate` and rebuild with `pnpm build`. General → Appearance → Storefront layout offers **Show Back to top button**, on by default. It appears at the bottom right after scrolling down.

### Wishlists

Apply `2026_11_01_000100_create_wishlists` with `php artisan migrate` and rebuild with `pnpm build`. Customers save products with the heart on any product card (an "add to list" window with the general list and their own lists); `/wishlist` shows them. Layouts → **Wishlist** arranges the page; its *Main content* block sets how many cards fit in a row.

### Product comparison

Apply `2026_11_03_000100_add_comparison_max_products_setting` and `2026_11_03_000200_create_comparison_layout` with `php artisan migrate` and rebuild with `pnpm build`. Visitors compare products from the comparison button on a product page; `/comparison` shows them. General settings → Catalog switches comparison on or off and sets how many products can be compared (default 4); Layouts → **Comparison** arranges the page.

### Category landing pages

Apply `2026_11_04_000100_add_category_landing_pages` with `php artisan migrate` and rebuild with `pnpm build`. Tick **Landing page** on a parent category (Categories → edit → Visibility) and open its storefront path, e.g. `/technologia`. Layouts → **Category landing pages** arranges the page; its *Main content* block sets cards per row, card shadow and the mobile grid or carousel.

### Cart

Apply `2026_11_05_000100_create_carts` with `php artisan migrate`, run `php artisan cms:permissions-sync` (new capability **carts.view**) and rebuild with `pnpm build`. The cart buttons on product cards and the product page fill the cart; `/cart` shows it. Layouts → **Cart** arranges the page; Catalog → **Carts** lists every cart.

### Storefront call requests

Rebuild with `pnpm build`. In Website → Layouts → Default → Store identity, **Show call request icon** enables a phone icon beside the account icon. It is off by default; no migration or permission sync is needed. Visitors submit name, phone and preferred time range; staff handle the result under Orders → Call requests. [Validation and privacy](architecture/call-requests.md#storefront-callback-a112).

### Editable invoice templates and account download

Run `php artisan migrate` (2026_11_10_000100 adds optional product/totals row templates and their audit fields) and `pnpm build`. Existing invoice content is preserved; `{{invoice}}` expands into the full design when opened in the invoice editor. No permission sync or additional dependency is required. `InvoiceTest` and `OrderLineVatTest` cover table preservation, custom rows/preview, discounts, gift-card payments and owner/status-gated downloads; also run the existing order, coupon, promotion, gift-card and account regressions. [Fiscal limitations and deferred AADE addon](architecture/aade-invoicing-addon.md).

Migration `2026_11_10_000200_rename_commercial_invoice_to_order_summary` renames generated legacy document headings/subjects to **Order summary / Σύνοψη παραγγελίας**, preserving custom text/styles and existing template keys/routes. Apply with ordinary `php artisan migrate`; rebuild after translation changes. Official AADE receipts remain deferred.


### Order detail consistency and delivery instructions

Apply `2026_11_10_000300_add_order_address_delivery_details` with ordinary `php artisan migrate`, then `pnpm build`. Existing orders/amounts/shipping selections remain as recorded. New checkout and staff orders retain floor, doorbell name and delivery notes in order-address snapshots. `OrderDetailTest` and `CheckoutTest` cover totals, legacy edits, saved-address isolation and orders placed before shipping was configured; run the existing order, shipping, payment-fee, gift-card, invoice and account regressions. The admin detail separates included VAT from added tax and shows methods near the heading. Hard-refresh after deploying the bundle.

## Website code editor

Run `php artisan cms:permissions-sync` and `pnpm build` after updating. Website → Code editor requires `theme-code.manage` (super admin by default, deliberately delegated through Roles). Save keeps private drafts and never executes or publishes code. An operator applies saved drafts with **`pnpm theme:apply`** using the existing Node/Vite toolchain; failed compilation keeps the working storefront. See [scope, trust, apply and recovery](architecture/theme-code-editor.md). Keep private draft storage persistent and coordinate source deployments with this command.

Checks: `php artisan test --compact tests/Feature/ThemeCodeEditorTest.php`, `pnpm exec vitest run resources/js/tests/CodeEditor.test.tsx`, `node --test scripts/theme-apply.test.mjs`, plus normal types/lint/build checks.

## Public catalog and storefront widgets

No migration, permission or setting. `StorefrontCatalog` is available to add-ons through the container; see the [contract](architecture/storefront-catalog.md). Rebuild with `pnpm build` so storefront pages can show widget frames.

Checks: `php artisan test --compact tests/Feature/StorefrontCatalogTest.php tests/Feature/StorefrontCatalogSearchTest.php tests/Feature/StorefrontWidgetTest.php tests/Unit/Storefront/StorefrontWidgetRegistrationTest.php` and `pnpm exec vitest run resources/js/tests/AddonWidgets.test.tsx`.

## Sitemap and All brands pages

Apply `2026_11_13_000100_add_storefront_directory_layouts` with ordinary migrations, then `pnpm build`. `/sitemap` and `/brands` use the new built-in Website → Layouts tabs, existing `layouts.view/manage` permissions, inherited store chrome and protected main content. Their localized page titles/meta/indexing remain editable in layout settings. No extra capability or sample data is added. Add menu links to the two routes through Website → Menus as needed. This implements the visible HTML sitemap only; XML sitemap generation remains separate. See the [directory contract](architecture/storefront-architecture.md#sitemap-and-alphabetical-brand-directory-a124).

Relevant checks: `php artisan test --compact tests/Feature/DirectoryPagesTest.php tests/Feature/CatalogLandingTest.php tests/Feature/LayoutSettingsTest.php`, `pnpm exec vitest run resources/js/tests/Directory.test.tsx resources/js/tests/CategoryLanding.test.tsx`, plus normal PHP/static/type/lint/build checks.

## Image menu blocks

Website → Menus already supports item pictures under **Appearance**. Create a standard manual menu with top-level links, then add **Image menu** in Website → Layouts. Its display selector offers image-and-label tiles, round images with labels below, or image cards with titles below (the category card design, including rounded corners and a subtle shadow). All three use the store palette, native horizontal scrolling and the existing carousel arrows/drag setting. Menu changes remain live; inactive menus/items and inaccessible categories follow the existing public menu rules. No migration or new permission is required; rebuild with `pnpm build`.

## Brand landing banners

Apply migration `2026_11_13_000200_add_brand_banners`, then rebuild with `pnpm build`. Brands → Edit → Image now includes the separate landing-page banner, optional link and translated alternative text. The existing `brands.view/manage` capabilities apply; no permission synchronization or sample upload is required. See [brand banner storage and rendering](architecture/brands.md#landing-page-banners).

Checks: `php artisan test --compact tests/Feature/BrandBannerTest.php tests/Feature/BrandTest.php tests/Feature/CatalogLandingTest.php` and `pnpm exec vitest run resources/js/tests/Brands.test.tsx resources/js/tests/CatalogLanding.test.tsx`, plus normal static/type/lint/build checks.

### Fashion header style

Website → Layouts → **Identity → Content → Header style → Fashion** puts the company logo on the left, grouped navigation in the middle and configurable actions on the right. Choose its **Menu for navigation** from the existing menus. The same logo, search, account, call request, wishlist and cart toggles and drag/arrow-key icon ordering are shared with Identity's compact style. Hidden icons retain their position. Add a picture to a top-level menu item in Website → Menus → Appearance to show it on the right of that dropdown. Search respects the active search provider, and cart/wishlist respect their store settings. Mobile uses the configured header background/text/icon colors, with the logo left and search, bag and a two-line hamburger right. Its white drawer shows large uppercase rows and dividers with drill-down navigation. **Show account, cart and wishlist links at the bottom of the Fashion mobile menu** defaults on and controls the bottom account section; signed-in customers get their account link and guests get Log in / Register. Disabled cart/wishlist features stay hidden.

Language selection defaults on for compact/fashion and can be disabled per block. Existing saved preferences are preserved. The separate Menu block remains a navigation bar. Apply `2026_11_15_000400_move_fashion_navigation_to_identity` and rebuild with `pnpm build`: any enabled legacy fashion Menu transfers its menu selection/settings to Identity, retains its original navigation data and disables its old placement to avoid duplicate navigation. No tables or columns change.

### Editable Fashion mobile links

Identity → **Mobile menu links** lets staff edit the section title and links for each active language, add/remove rows, open links in a new window, and reorder with the native grip or arrow keys. Account, cart and wishlist are starter links; emptying the list keeps it empty. The section switch lives in this tab. Custom labels and destinations are stored in the block’s locale-linked translation record; URLs use the existing safe-menu URL policy. A default Account destination retains guest sign-in/registration behavior. The link collection is independent of desktop icon visibility; globally disabled cart/wishlist features remain hidden. Apply `2026_11_15_000500_add_identity_mobile_menu_links` and rebuild with `pnpm build`.

Fashion’s search icon opens a full-screen white dialog with a wide rounded search field, a leading search icon and the existing product suggestions. Escape or the close button dismisses it and returns focus to the header icon. The page stays still while searching; no migration is needed. Rebuild with `pnpm build`.

Header search shows a responsive **Recently viewed products** thumbnail carousel above query suggestions when the visitor has eligible history. All three header styles use the same search component and existing guest/customer history (latest 12 unique products). It loads when the field opens through `/search/recently-viewed`, with private/no-store responses; unavailable history stays hidden. Typing keeps the existing query suggestions, minimum length and item-limit controls.

The full-screen search field follows the shop primary color. Classic and Compact keep their inline search on desktop and open this same full-screen search on mobile. Mobile drawers use dynamic viewport height and safe-area padding so their bottom links stay clear of iPhone browser controls.

Classic Identity also offers the language switcher and the same drag/arrow-key icon ordering. All three header styles share these settings; Classic and Compact keep their search field in its existing position.

### Admin order notifications

Run `php artisan migrate` and `pnpm build` after this update. Recreate the local web app with `docker compose up -d --no-deps app` to enable the multi-worker CLI server required by native SSE. Staff with admin access plus `orders.view` and `orders.update` receive persistent notifications for new storefront orders; existing orders are not backfilled. No extra service or queue is needed. See [notification persistence, endpoints and production worker limits](architecture/admin-notifications.md).

### Black Friday Theme addon

Rebuild with `pnpm build` for the storefront stylesheet slot. **Add-ons → Black Friday Theme → Install → Enable**
activates the dark storefront palette. Ordinary `php artisan cms:addons-sync` also installs the bundled package disabled.
Disable restores saved colors on the next storefront navigation/reload. No migrations or customer theme switch.
See [the addon guide](addons.md#black-friday-theme). Verify with `BlackFridayThemeTest`, `AddonManagerTest` and the
frontend `BlackFridayTheme.test.tsx` color-only guard.

### Storefront account shortcuts

Rebuild with `pnpm build`. The account icon in Classic, Compact and Fashion headers opens a native popover with
existing account sections, enabled favorites and sign out. Guests receive sign-in/register links. The popover supports
outside-click/Escape dismissal and follows the storefront palette, including Black Friday Theme. No migration required.


### Store reels

Run `php artisan migrate` (`2026_11_20_000100_create_reels`), `php artisan cms:permissions-sync` and `pnpm build`. Marketing → Reels uses `reels.view`/`reels.manage`; viewing identities/history also requires `reels.analytics`. Upload an MP4 (up to 64 MiB), a cover (up to 5 MiB) and optionally link products. Add a Reels block in Website → Layouts; set its source to manual, newest or most viewed. General → Catalog → Reels period defaults to 90 days. Restart the app container for the new PHP upload limits; configure equivalent production PHP/proxy limits. [Playback, view counting and retention](architecture/reels.md).

### Bestseller settings

Run ordinary `php artisan migrate` (`2026_11_21_000100_add_bestseller_settings`) and `pnpm build`, then hard-refresh. General → Catalog → Best sellers configures sales days (0 = all time), excluded categories including descendants, minimum units and minimum distinct paid orders per product. Defaults preserve existing behavior. Existing `settings.manage` applies; no permission sync is needed. The rules cover product/brand blocks, the bestseller listing and shopping filters. Reports remain historical. Checks: GeneralSettingsTest, GeneralPreferencesTest, StorefrontLayoutTest, BrandTest, CategoryListingTest and frontend GeneralSettings tests.

### Bulk order status

Rebuild with `pnpm build`, then hard-refresh `/admin/orders`. Staff with existing `orders.status` can select orders on the current page, choose an active status and confirm the listed order numbers. The batch (up to 100) commits together; stale orders or failed stock changes reject all updates. Customer emails follow the usual after-commit workflow. No migration or permission sync. Checks: `OrderTest`, frontend `Orders.test.tsx`, PHP static/format and frontend type/lint/build checks.

### Ready for shipment status

Apply ordinary `php artisan migrate` (`2026_11_21_000200_add_ready_for_shipment_order_status`) and `pnpm build`, then hard-refresh `/admin/order-statuses`. The built-in **Ready for shipment / Έτοιμη για αποστολή** appears before Shipped with a dedicated editable customer email in every bundled language. Existing orders and edited templates are preserved; stock is held without duplicate deductions. No permission sync is required. Verify with `OrderTest` and normal PHP format/static and frontend build checks.

## Variation listings and reusable packages

After changing PHP search/index code, restart long-running workers before processing catalog updates. On this workstation use `docker compose -p cms-foundation restart worker scheduler`. An old worker can keep emitting the previous search document format after an index rebuild; rejected critical outbox rows intentionally hide affected products until successfully retried. A rebuild does not clear retry-delayed outbox rows by itself.

Apply `2026_11_20_000100_add_variation_listing_setting` and `2026_11_22_000100_create_product_packages`, then rebuild with `pnpm build`. On this workstation the running application is Compose project `cms-foundation` (port 8000); use `docker compose -p cms-foundation exec -T app` for that application.

Catalog → Variation Groups → **Show as one product** is opt-in per group and defaults off. Enabled groups list their cheapest purchasable member; explicit member pages and selectors retain their real product identities.

Catalog → Product packages manages localized package names and up to 30 ordered products. Staff use existing `products.view`/`products.update` permissions. Product → Buy With also offers a separate package assignment and contents. Category assignment replaces the package on current direct category members only. Assignment lists paginate at 100 products. Packages include the current product once; the add-all action validates every line and rolls back the entire addition on failure. Existing Buy With records are untouched.

Variation display follow-up: apply `2026_11_23_000100_split_variation_listing_surfaces` and `2026_11_23_000200_add_variation_storefront_names`, then rebuild. Each group has independent Carousels / Product lists / Search switches. Existing values initialize all three. Storefront names are locale-linked and separate from the private internal label; blank names fall back to the selected product title. Manual product blocks offer a Variation Groups picker; explicitly selected groups resolve even with automatic carousel grouping off. Product selections precede group selections within the block limit.

Manual product carousel picks now default to **Keep manually selected products** under the block content settings. This preserves each picked product's title, price and identity even when automatic carousel grouping is enabled. Turn it off to group those selections; explicit Variation Groups picks always resolve as groups. Existing blocks inherit the enabled default without a migration.

Package editor: product candidates are fetched only after submitting a nonblank name/SKU search (up to 20 results). Package contents and assignments have separate tabs; selected products remain visible across searches. Names use the active-language tabs, and category assignment has a separate searchable selector. No schema changes are required for this UI update.

Grouped variation filters: apply `2026_11_24_000100_add_variation_card_settings` and `2026_11_24_000200_index_variation_search_groups`, run `php artisan cms:search-reindex --now` when Typesense is enabled, and rebuild with `pnpm build`. Lists/search retain all eligible members' feature values while counting each group once per value. Combined filters must match a real member; cards retain their cheapest purchasable identity. Variation group settings can show selected defining features (four values plus an expandable remainder) and replace quick add with a view-product icon on lists/search. Both controls default off, and carousel presentation stays unchanged. Verify with VariationGroupTest, CategoryListingTest, SearchTest, LocalizationTest and frontend Cart/VariationGroups tests.

Variation card labels: apply `2026_11_25_000100_add_variation_card_feature_labels`, then rebuild. Variation Groups → Show as one product offers optional locale-linked card labels for selected defining features. Blank labels use the existing feature name; labels only affect grouped card summaries and preserve filters/product-page names. Labels save with the other display settings, survive defining-feature reordering, and are removed when a defining feature leaves the group.

Variation color summaries: apply `2026_11_25_000200_add_variation_color_card_style`, then rebuild. Variation Groups → Show as one product → Card action → Color display selects Text (default) or Color swatches. Selected color-style defining features use saved option swatch colors, mark the displayed product's color, and show six circles plus expandable extra colors. Each circle has a translated accessible name and title; missing swatch colors use a neutral diagonal placeholder. Other feature styles retain text summaries.

Clickable variation colors: apply `2026_11_25_000300_add_clickable_variation_colors`, then rebuild. The Card action section offers **Clickable color swatches**, off by default and configurable while Color swatches display is selected. Enabled colors link to their lowest-priced publicly listed purchasable member (current displayed prices, product ID tie-break); colors without a purchasable target remain display-only. Color summaries appear below the image and above the title, including on mobile; RAM/storage summaries keep their existing position. All circles retain translated accessible labels and titles.

Product-page variation compatibility: RAM/storage options stay within the currently selected color-style defining features. A value that only exists in another color is visible but disabled until that color is selected. Within the color, the selector chooses a real public member preserving the most other selections (earlier defining features win ties), so changing RAM may update storage. Color choices can change color and adjust other selections. Groups requiring such adjustments also show a compact carousel of complete alternatives with image, full product title and current displayed price. It reuses the already loaded member cards without extra database queries or requests; out-of-stock existing combinations retain their previous navigation behavior. Mobile feature tiles/pills use equal-sized options in swipeable horizontal rows without arrows. No migration or search reindex is needed. Rebuild with `pnpm build`; verify with `ProductPageTest` and frontend `ProductPage.test.tsx` / `Cart.test.tsx`.


## Admin search recovery

Settings → Search now leads with **Search health & recovery** and a **Repair search** action for staff with `search.manage`. Delayed/failed indexing and updates waiting over five minutes show a warning across admin pages, with a link to this screen. Current outbox state drives the warning; old resolved errors do not keep it visible. The repair action signals a graceful Laravel queue-worker restart, makes queued updates due immediately, and runs bounded indexing with current application code; it starts/resumes a rebuild when the provider reports an outdated or missing index. Large catalogs can require repeated passes. Technical details and existing rebuild/reconcile actions remain available below the collapsed details control.

Queue workers must run under a process manager that automatically restarts exited workers. The local Compose worker uses `restart: unless-stopped`; apply it with `docker compose -p cms-foundation up -d --no-deps worker`. Production deployments need the equivalent Supervisor/systemd/container restart policy. The admin action does not execute shell/Docker commands, change catalog data, or restart the search service. An unreachable engine or recurring failure is explained on the screen and may still require hosting support. Verify with SearchTest, frontend SearchRecovery tests, PHP static analysis, types/lint/build and an admin browser check.

## Storefront development indexing and staff preview

Apply `2026_11_26_000100_add_storefront_noindex` and rebuild the frontend. General settings → Basic settings → Storefront access offers **Discourage search engine indexing (development)**. It defaults off, saves with the existing Save settings action, and is versioned, validated and audited. Enabled stores send `noindex, nofollow` in HTML and `X-Robots-Tag` responses; turn the setting off before public launch. These directives discourage compliant search engines from indexing; they do not prevent requests or provide access control. Existing private-page directives remain in force after disabling it.

Closing the store continues to show visitors the localized closure page with HTTP 503 and Retry-After. Signed-in, email-verified staff who pass the existing `access-admin` gate can browse the storefront and its eligible media while closed. Customer accounts and unverified staff cannot bypass closure. Catalog publication/customer-group rules and cart/checkout switches still apply; staff previews do not reveal unpublished products automatically. Closed-store responses and previews remain noindex regardless of the development setting. The anonymous catalog add-on contract remains closed. This is ordinary storefront access, so cart actions continue to use real catalog/order rules.

Verification: GeneralPreferencesTest covers setting validation, persistence, audit, enabling/disabling indexing, guest closure, verified staff preview, customer/unverified refusal, catalog/media/search/cart access; RobotsTest covers existing admin exclusions. GeneralSettings frontend tests cover saving the checkbox. ProductPageTest, CartTest, CheckoutTest and StorefrontSeoTest cover related regressions. Local browser verification confirms the Greek control and help text render in the existing settings layout.

Follow-up verification completed: 106 backend tests passed (3423 assertions), including AuditTest; 13 GeneralSettings frontend tests passed. Targeted PHPStan, TypeScript, ESLint, formatting and production build passed. The additive migration was applied locally; the new checkbox remains off until explicitly enabled.

## XML Sitemap add-on

Deploy the bundled `ambresoft/sitemap` add-on, run ordinary `php artisan cms:addons-sync` to install/upgrade its owned migrations, enable it in Add-ons and deliberately grant its view/manage/publish capabilities. Restart the worker and scheduler, and rebuild with `pnpm build`. Website → XML Sitemap separates section schedules, draft URL overrides and confirmed publication; the public `/sitemap.xml` appears only after the first Publish. The existing HTML `/sitemap` is independent. No crawler-directive file is managed by this feature. [Design, schema, operation and limits](architecture/xml-sitemap.md).

Relevant backend checks: `SitemapTest`, `SitemapProvidersTest`, `SitemapXmlTest`, `AddonManagerTest`, storefront catalog/SEO/product/category/page/brand/blog/directory tests, cart/checkout and SEO redirect tests. Frontend interaction coverage is `resources/js/tests/Sitemap.test.tsx`. The existing quality commands now include the bundled add-on's trusted React screen. See the [verification report](audits/sitemap.md) for actual results and unrelated baseline failures.

## Brand documents and attachment uploaders

Apply `2026_11_29_000100_create_brand_files`, rebuild the frontend and restart workers/scheduler. Brands now have an Attachments tab using the same file uploader as Products → Attachments. Active assigned-brand documents appear above Questions on the product page and open in the existing storefront modal. Product-specific attachments use this preview too; digital buyer downloads keep their existing purpose and access rules. [Storage, access and verification](architecture/brands.md#brand-attachments).

The local test setup includes Nike at `/admin/brands/9/edit`, three explicitly labelled demo shoes under `/demo-footwear`, and `output/pdf/nike-demo-shoe-size-guide.pdf`. These examples were deliberately created only in the local database, not added as automatic production seed data. The guide is fictional sample content, not an official manufacturer chart.

## Feed Engine removal

Apply `php artisan migrate` (`2026_12_03_000100_remove_timeline_feed`), run `pnpm build`, then restart the worker and scheduler. The migration removes Feed Engine data and Timeline presentation without touching other layouts (A146).

## Google Tag Manager

Rebuild with `pnpm build`, run `php artisan cms:addons-sync`, then enable **Google Tag Manager** in Add-ons and enter the container id under its Settings. The container loads on public storefront pages only; private pages are opened as full page loads. Verify in the browser that `dataLayer` receives `gtm-add-to-cart` and the other events after the matching actions. Checks: `GoogleTagManagerTest`, `resources/js/tests/GoogleTagManager.test.tsx` (A147).

## Google Analytics

Rebuild with `pnpm build`, run `php artisan cms:addons-sync`, enable **Google Analytics** in Add-ons and enter the GA4 measurement ID (`G-…`). For purchases, also create a Measurement Protocol API secret in Google Analytics (Admin → Data streams → stream → Measurement Protocol API secrets) and keep the queue worker running. Restart the worker and scheduler after enabling. Checks: `GoogleAnalyticsTest`, the `CheckoutTest` placed-order test and `resources/js/tests/GoogleAnalytics.test.tsx` (A148).

## GDPR Cookies

Rebuild with `pnpm build`, run `php artisan cms:addons-sync` and `php artisan cms:permissions-sync`, then enable **GDPR Cookies** in Add-ons. Its settings are under the add-on's Settings link. Grant `ambresoft.gdpr-cookies.scripts` deliberately: scripts run in visitors' browsers. Restart the scheduler, which purges old consent records daily. While it is enabled, Google Analytics and Tag Manager load only after consent. Checks: `GdprCookiesTest`, `resources/js/tests/GdprCookies.test.tsx` and `resources/js/tests/GdprCookiesAdmin.test.tsx` (A149).

## Account-security IP location

Account-security history records future registration/sign-in IPs and browser installations for customer and staff identities. Super admins review it from customer detail, the staff Security tab and the main dashboard. Install the locked Composer dependencies, run migrations and `php artisan cms:permissions-sync`, then build assets.

Location and estimated local time additionally require a local GeoLite2-City/GeoIP2-City MMDB database. Obtain/update it through your provider, store it outside the public directory at `storage/app/private/GeoLite2-City.mmdb`, or set `SECURITY_GEOIP_DATABASE` to its absolute path inside the PHP container. Do not commit the database or provider credentials. Without it, history still works and location/time display unavailable. See [the security-history contract](architecture/account-security.md).

## Storefront cache

After migrating and syncing capabilities, Cache in the main admin menu controls the optional Redis storefront content cache. It starts disabled. Clear only storefront content there; do not run Redis `FLUSHDB`/`FLUSHALL` to refresh catalog content. See [cache boundaries, invalidation and production operations](architecture/storefront-cache.md). The cache integration tests use the isolated PostgreSQL database and Redis DB 15; their keys expire, and they never flush Redis.

## Product CSV imports

Products → Import products (also Imports / Exports) supports case-insensitive SKU updates and creation, partial-field updates and protected remote primary images. Run `php artisan cms:permissions-sync` after deploying this feature and grant `products.import` to intended staff alongside create/update/status capabilities. No new SKU index is needed: the existing lower(sku) unique index is reused. See the [CSV contract, limits and examples](architecture/product-imports.md). Keep the Laravel scheduler running for private-upload expiry cleanup.

## Brand CSV imports

Brands → Import brands (also Imports / Exports) supports code-based creation/updates, partial fields and direct URLs for logos, banners and private attachments. Sync permissions, grant `brands.import` alongside `brands.manage`, and build assets. No migration is needed. Keep the scheduler running for expired-upload pruning. See the [brand CSV fields, asset behavior and limits](architecture/brand-imports.md).

## Checkout invoice VAT validation

The **Supported countries** subtab starts with the shop's enabled shipping countries from `/admin/countries` when
`2026_11_12_170048_create_checkout_invoice_countries` is applied. Review/save invoice coverage there independently;
only enabled countries covered by the selected VAT methods appear in checkout. An empty selection hides the invoice
option. Run ordinary migrations and `pnpm build`, then hard-refresh the settings page.

Apply `php artisan migrate`, build with `pnpm build`, then open **Settings → General → Checkout**. Use the free keyless VIES defaults, or choose AADE for Greek companies, the existing Greek AFM checksum-only check,
and/or VAT Sense for broader international coverage in the corresponding subtabs. Supply credentials only for selected
AADE/VAT Sense methods, then explicitly enable invoice requests. Credentials stay write-only and encrypted. The
checkout layout must also show its invoice request checkbox. Only AADE requires customer sign-in. VIES covers intra-EU
registrations; checksum-only does not confirm an active business. Existing orders are unchanged. See [coverage, security and operation](architecture/checkout.md#invoice-vat-verification-a158).

The requested local invoice preview is order **#100026** (`/admin/orders/26`, demo marker `orders.invoice.preview`).
It is labelled DEMO with fictional company/VAT data and a simulated VIES response. It uses the existing demo mouse
product in Open status; creation preserved stock and the shopper cart and sent no payment or email. This fixture was
created only in the local database, not added to deployment seed data.
