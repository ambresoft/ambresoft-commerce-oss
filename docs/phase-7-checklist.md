# Phase 7 implementation checklist — Simple product stock

Roadmap JSON: not present in the repository (searched tracked files and `*.json`); `docs/architecture/development-phases.md` is the roadmap record. The earlier "Warehousing & Complex Inventory" plan was only roadmap rows and open-question notes; it is marked Cancelled / Archived, superseded.

- [x] Inspect product model/record/mapper, migrations, controllers, form request/input rules, product editor and list, tests, audit inventory and permissions.
- [x] Roadmap: archive the old plan, record the replacement as active Phase 7 (A67).
- [x] Reversible migration: `quantity` (default 0), `list_price`, `min_qty`, `max_qty`, `qty_step`, `out_of_stock_actions`, CHECK constraint, audit capture; existing products start at 0/NULL.
- [x] Shared server validation on every save path; domain rules for limits, step and stock behavior.
- [x] Editor Pricing & inventory section; list Stock column, sort and reference price.
- [x] Existing `products.update` permission only; no new capabilities.
- [x] Tests: backend feature/unit/migration and frontend interaction tests; full `make check`.
- [x] Not built (by design): warehouses, locations, ledger, reservations, thresholds, inventory permissions, notifications, cart/storefront enforcement, import/export.

## Follow-up: Settings → General (A68)

- [x] Reversible migration 001700: single-row `general_settings` (CHECK id = 1, valid action, version), audit capture; `products.out_of_stock_actions` nullable (NULL follows the general setting), Phase 7 default `N` converted to NULL.
- [x] `settings.manage` capability (synchronized, not auto-granted); server authorization on route, controller and save; stale-version rejection.
- [x] Settings → General page with override count and link to overriding products; sidebar entry in the Settings group.
- [x] Product editor: "Use the general setting (current value)" choice, follow/override note, reset action; list filter `stock_action=general|custom`.
- [x] Six-language dictionaries (`settings` group, new product keys) with parity.
- [x] Tests: backend feature/unit/migration round-trips and frontend interaction tests; docs ([general-settings.md](architecture/general-settings.md), A68).
- [x] Full check (2026-10-11): Pint, PHPStan, **305 PHPUnit tests / 29,341 assertions**, TypeScript, ESLint, Prettier, **100 Vitest tests** and the production build pass. Not exercised in a browser session.

