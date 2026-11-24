# Phase 6 implementation checklist — Product Variations as Separate Products

Original roadmap JSON: absent from the repository (same finding as Phases 4–5). `docs/architecture/development-phases.md` numbers variations differently (customer options as 6, variations as 7); the user's explicit Phase 6 specification is authoritative and is the full scope. No requirement is inferred beyond it.

Decision note: A11/A12 proposed dedicated `ProductVariation` records. The explicit Phase 6 specification chooses the product-as-variation model (every variation is a complete standalone product connected by a Variation Group). This supersedes A11/A12 for variations, as A44 superseded A11 for SKU/price ownership; recorded as A66.

## Discovery

- [x] Products: drafts, completeness (`ProductState`), save/duplicate/lifecycle/bulk under the Catalog advisory lock with `lock_version` checks, audits (`product_audits` + central ledger triggers).
- [x] Features: purpose model (`standard`, `landing_pages`), CHECK constraint, options, landing images, applicability SQL, completeness SQL, impact confirmation (HMAC, `ExpectedPreview`), option lifecycle.
- [x] Categories: participants (`beforeParentUsed`, `beforeDeletion`, `afterCategorySave`), leaf rule, deletion recovery to Uncategorized, eligibility refresh.
- [x] Audit: capture triggers from a reviewed table inventory; new tables need an additive migration; existing migrations must not change.
- [x] Concurrency: every catalog mutation already serializes on `pg_advisory_xact_lock(2142513)`; reuse it for every group operation.

## Implementation

- [x] Schema: `features.variation_style`, purpose CHECK extended with `variations`, option `swatch_color` (canonical `#RRGGBB` CHECK) and separate `swatch_image_media_id`; `variation_groups`, `variation_group_features` (per-group selector order), `variation_group_members` (product UNIQUE → zero-or-one group; `UNIQUE(group_id, signature)`), `variation_group_intents` (Create-variation target, not membership), `variation_group_audits`; audit capture on new tables/columns.
- [x] Feature purpose `variations` (Single choice only), selector style (Text buttons default, Color, Image, Dropdown), swatch color normalization, swatch image via shared media rules, warnings for missing swatch data, preserved dormant purpose data.
- [x] Integrity service: completeness, same leaf category, same type, eligible defining features (purpose/type/active/complete/applicable), exactly one active option per defining feature, stable-ID signature uniqueness including Disabled/Hidden members.
- [x] Group operations: guided create, rename, change defining features (impact preview + confirmation), selector reorder, add (single and all-or-nothing batch), remove, transfer, disband, auto-disband when empty, Create variation via existing duplication + intent.
- [x] Guards: product save (values, type, category), activation revalidation, Trash detaches (auto-disband), restore stays standalone, duplicate stays standalone, feature purpose/archive/definition and option archive, category move/status/deletion.
- [x] Admin: Products → Variation Groups list (search, filters, pagination, sort), group screen (members, readiness, actions), product list column + Grouped/Standalone filter, product editor membership/intent notice, feature editor purpose/style/swatches.
- [x] Permissions: existing `products.view` (read), `products.update` (group configuration/membership), `products.create` (Create variation), `features.update` (style/swatches). Server-enforced.
- [x] Translations (English, Greek; other UI languages use the explicit English fallback as Features do).
- [x] Tests (backend PostgreSQL + frontend), full repository checks, documentation (A66, variations contract, phases).

Verification and operational results are recorded in [variations.md](architecture/variations.md).
