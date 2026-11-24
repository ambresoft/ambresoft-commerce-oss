# Phase 5 implementation checklist

Original roadmap JSON: absent from the repository (including tracked/untracked JSON search). `docs/architecture/development-phases.md` identifies Features / groups / values / assignments after Products and Pricing/Currencies. The user's Phase 5 specification is authoritative.

- [x] Inspect architecture, products/categories, localization, authorization, pricing, media/audits and tests. No existing demo seeders; preserve merchant data.
- [x] Add normalized definitions, translations, groups, options, category restrictions and typed assignments with PostgreSQL integrity.
- [x] Implement server validation, immutable identities/types, impact confirmation, lifecycle, ordering and optimistic versions.
- [x] Integrate product values, category changes, activation, historical retention, duplication and derived remediation.
- [x] Implement translated administration, dedicated ordering, remediation navigation and product Features tab.
- [x] Add guarded, idempotent ten-product demo dataset.
- [x] Verify PostgreSQL behavior, permissions, lifecycle, concurrency, UI interactions and full repository checks; document operations/results.
- [x] Refinement (A61): Feature Purpose (Standard Attribute default; Brand / Author Landing Pages for Single choice only), feature-level SEO removed with audit retention, localized variant landing metadata and image on options, derived eligibility, no public pages/routes.

Decisions: Catalog owns descriptive features. Numeric values use numeric(20,6), separate optional unit. Required completeness uses only default-language content. General is protected reference data. Missing-required remediation is a live relational query, not a stale persisted flag. Configuration and assignments share the existing Catalog advisory transaction lock. Definition/group/option versions and a catalog revision protect editor snapshots. No storefront or Phase 6 functionality.

Verification and operational results are recorded in [features.md](architecture/features.md).
