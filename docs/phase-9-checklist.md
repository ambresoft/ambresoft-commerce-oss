# Phase 9 implementation checklist — Customers & Customer Management

Roadmap JSON: not present in the repository; `docs/architecture/development-phases.md` is the roadmap record (Phase 9 row added, other phases untouched).

- [x] Inspect identity/auth, permissions, localization, geography, settings and Phase 8 tax: no customer table or customer sign-in; staff identities in `users`; no exemption field (only a calculation flag); ISO country list, no region catalog.
- [x] Roadmap: Phase 9 recorded as Customers & Customer Management.
- [x] Reversible additive migration 001900: customers, addresses with owned-default FKs, groups (+ translations), memberships, constraints, indexes, privacy-reviewed audit capture.
- [x] Customer list/search/filter/sort/pagination, detail, create/edit, disable/restore (no delete).
- [x] Addresses: multiple, optional, owned defaults, deterministic fallback, forged IDs → 404.
- [x] Customer groups: CRUD, explicit default, disabled-group rules, reassign-or-refuse deletion.
- [x] Tax: single `customers.tax_exempt`, consumed through `CustomerTaxProfiles`.
- [x] Six capabilities; server-side checks in controllers and operations.
- [x] English/Greek dictionaries (others fall back), audit labels, docs (customers.md, A71).
- [x] Full check (2026-10-13): Pint, PHPStan level 6, **360 PHPUnit tests / 37,515 assertions** (19 new customer tests), TypeScript, ESLint, Prettier, **123 Vitest tests** (7 new) and the production build pass. Migration 001900 applied to the local dev database and `cms:permissions-sync` run (no grants). Not exercised in a browser session.

Not built (later phases): customer sign-in/registration and storefront account, A21 user link, orders/order history, checkout, payments, shipping, returns, customer deletion/erasure workflow, group-based pricing or promotions.

Follow-up: customer blacklist for emails and phones with mandatory reason, computed flag on existing customers (list badge/filter, detail banner), release history and the `CustomerBlacklist` checkout port. Order enforcement waits for the checkout/order phase.
