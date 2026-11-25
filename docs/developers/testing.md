---
title: Testing
parent: Developer guide
nav_order: 13
---

# Testing

## PHP tests (PHPUnit)

Tests live in `tests/Unit` (plain PHP, no Laravel boot or database where possible) and `tests/Feature` (HTTP, database
and integration tests).

```sh
# the whole suite (also part of make check)
docker compose run --rm app composer test

# one file or one test
docker compose exec -T app php artisan test --compact tests/Feature/AuthenticationTest.php
docker compose exec -T app php artisan test --compact --filter=test_csrf_is_required_for_browser_mutations
```

`phpunit.xml` **forces** the test environment, so tests can never touch your store's data:

| Setting | Test value |
| --- | --- |
| Database | PostgreSQL `cms_test` with user `cms_test` (never SQLite) |
| Cache and sessions | `array` |
| Queue | `sync` (jobs run immediately) |
| Mail | `array` (nothing is sent) |
| Redis | databases 14 and 15 with the prefix `cms_test_` |

The full backend suite can take longer than Composer's default 300-second limit; `composer test` disables that limit.

{: .warning }
> Never point tests at the store database `cms`. Database tests migrate and reset only `cms_test`.

### The architecture test

`tests/Unit/ArchitectureTest.php` enforces the [context-first DDD rules](../architecture/#what-each-layer-may-depend-on):
PascalCase directories, framework-free `Domain` and `Application` layers, inward dependencies and no presentation
imports in `Infrastructure`. If it fails, move the code to the right layer instead of weakening the test.

### Writing tests

- Test the behavior you changed and its important failure modes: permissions (a user **without** the capability gets
  `403`), validation errors, concurrency or version conflicts where relevant.
- Build only the data each scenario needs. Reuse the helpers in `tests/Concerns` (`StaffIdentities` for staff users with
  permissions, `PlacesOrders`, `BuildsReportFixtures`, `GeneratesAddons`) and the fakes in `tests/Fakes`.
- Laravel skips CSRF checks in tests. To test CSRF, switch the environment as `AuthenticationTest` does
  (`$this->app->instance('env', 'production')`).
- Fake external services: add-on tests install packages into a temporary add-ons path and fake provider APIs. See
  `tests/Feature/AddonManagerTest.php` and `tests/Feature/StripeCheckoutTest.php`.

## Frontend tests (Vitest)

```sh
pnpm test                 # all frontend tests
pnpm vitest run <path>    # one file
```

Tests use Vitest with Testing Library in a `jsdom` environment. Test files end in `.test.tsx` and live mostly in
`resources/js/tests`.

## Static checks

| Check | Command |
| --- | --- |
| PHPStan / Larastan (level 6) | `docker compose run --rm --no-deps app composer analyse` |
| Pint (style) | `docker compose run --rm --no-deps app composer lint` |
| TypeScript | `pnpm types` |
| ESLint | `pnpm lint` |
| Prettier | `pnpm format:check` |

## Search benchmark

`tests/Benchmark/SearchBenchmarkTest.php` is outside the normal suites. It builds `BENCH_PRODUCTS` products (default
100,000) and measures storefront searches and suggestions end to end, also during a bulk import.
