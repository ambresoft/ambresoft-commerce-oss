# Phase 8 implementation checklist — Tax Management & Calculation

Roadmap JSON: not present in the repository; `docs/architecture/development-phases.md` is the roadmap record (Phase 8 row added, earlier phases untouched).

- [x] Inspect repository, roadmap, pricing/currency, product save path, settings, authorization, audit, localization and tests. No country/region catalog, customer, cart, order or shipping capability exists.
- [x] Roadmap: Phase 8 recorded as Tax Management & Calculation.
- [x] Reversible additive migration 001800: rate areas (+ translations, countries, regions), taxes (+ translations), tax rates, product taxes, tax settings columns, constraints and audit capture.
- [x] Tax definitions: name per language, registration number, priority, status, price-included, shipping/billing basis; create/edit/disable/delete (delete refused while assigned).
- [x] Reusable rate areas with countries, optional regions and one default fallback; deterministic matching; explicit zero never falls back; missing rate reported.
- [x] Percentage (exact fraction) and fixed (primary currency) rates; unique per tax/area; DB constraints.
- [x] Priority groups and compounding; inclusive/exclusive combination rules documented and tested.
- [x] Product Taxes control in the Pricing tab; duplicate copies; bulk "assign to all" with confirmation, capability check and no duplicates.
- [x] Calculation service (unit-price and subtotal methods, estimation address, exemption flag, structured result).
- [x] Tax settings in Settings → General (method, display flags, estimation address).
- [x] Fixed tax amounts follow primary-currency conversion and precision guards.
- [x] Seven capabilities, six-language-safe dictionaries (English/Greek complete, other locales fall back), audit labels.
- [x] Tests: framework-free calculation, backend feature, frontend interaction; docs ([taxes.md](architecture/taxes.md), A69–A70).
- [x] Full check (2026-10-12): Pint, PHPStan level 6, **340 PHPUnit tests / 34,270 assertions** (35 new tax tests), TypeScript, ESLint, Prettier, **116 Vitest tests** and the production build pass. Migration 001800 applied to the local dev database; `cms:permissions-sync` run (no grants). Not exercised in a browser session.

Not built (by design / future phases): shipping methods and zones, external tax providers, ERP, checkout/cart/order totals, discounts, customer accounts and persistent tax exemption, storefront price display, tax certificates, city/postcode rules, tax CSV import/export.

Follow-up: opt-in sample Greek data (`cms:taxes-demo`), local/testing only; clearly marked "(sample)", idempotent, outside production defaults.
