# Phase 6: product variations as separate products

Authorized by the explicit Phase 6 specification (2026-10-10). The original roadmap JSON was not found (same finding as Phases 4–5); the specification is the full scope and nothing beyond it was inferred. Decision [A66](decisions.md) supersedes the earlier dedicated-`ProductVariation` proposal (A11/A12) for variations. No storefront, public route, selector rendering, inventory or later phase is implemented.

## Model

Every variation is an ordinary, complete product. It owns its names, translations, SKU, price, status, category, images, SEO, slugs and feature values; nothing is inherited or synchronized. A **Variation Group** only relates products and records which features distinguish them.

| Table | Responsibility / integrity |
| --- | --- |
| `variation_groups` | Immutable ID, admin-only non-localized non-unique `name` (CHECK non-blank), `lock_version`, actors, timestamps. No status. |
| `variation_group_features` | Defining features with a per-group selector `position`; PK (group, feature), UNIQUE (group, position); feature FK RESTRICT. |
| `variation_group_members` | `product_id` UNIQUE (zero or one group per product), PK (group, product), `signature` with UNIQUE (group, signature) and a format CHECK. Group delete cascades; physical product deletion removes only its membership. |
| `variation_group_intents` | Create-variation target for a still-standalone product (product PK). Not membership; cascades with group or product. |
| `variation_group_audits` | Domain audit (group ID survives disbanding), same actor/action/context convention as `product_audits`. |

Feature columns: `features.variation_style` (`text` default, `color`, `image`, `dropdown`; CHECK), `feature_options.swatch_color` (CHECK `^#[0-9A-F]{6}$`), `feature_options.swatch_image_media_id` (nullable unique FK to `media_files`, RESTRICT) — separate from `landing_image_media_id`. `feature_purpose` CHECK now allows `variations`, still Single choice only.

Migration `2026_10_11_001500_create_variation_groups.php` is additive. It also attaches central-ledger capture to the new tables and re-attaches `features`/`feature_options` capture with the new columns (`AuditTables::variationGroups()`; the historical 001300 inventory is unchanged). Rollback drops the tables/columns, returns `variations` features to Standard Attribute and leaves swatch files as unreferenced media; use it only before merchants enter group data.

## Combination identity and integrity

A combination is the set of `(feature ID, option ID)` pairs for the group's defining features, read from the existing Phase 5 assignments (`product_feature_choices`); there is no second value store. The stored signature is `featureId:optionId` pairs sorted by feature ID, so selector order never changes it, and labels/translations are never part of it. It is an index aid: every check recomputes it from current values.

`VariationGroupIntegrity` evaluates, inside the caller's transaction:

- at least one defining feature, each `purpose = variations`, Single choice, active and complete;
- at least one member; members not trashed and complete under existing `ProductState` rules;
- one shared active leaf category and one product type (reference = other members than the product being edited);
- every defining feature applicable to that category (Phase 5 descendant-inheriting scope);
- exactly one applicable, active option per defining feature per member (independent of the global Required flag);
- unique combinations among all members, Active, Disabled and Hidden alike.

Concurrency: every group operation, product save/lifecycle, feature change and category mutation already serializes on the Catalog advisory transaction lock (`2142513`), then re-validates and writes signatures before commit. `UNIQUE (group, signature)` and `UNIQUE (product)` back this up in PostgreSQL. Group and product `lock_version` checks reject stale submissions without merging. `VariationGroupConcurrencyTest` shows a competing addition waiting on the lock (`55P03`) and the retry failing with the conflicting product named.

## Operations (`VariationGroupAdministration`)

All authorize server-side with existing capabilities, take the Catalog lock, check versions, mutate, re-validate and roll back completely on failure.

| Operation | Rules |
| --- | --- |
| create | Complete standalone product + ≥1 eligible feature (+ optional name; default is the default-language product name, missing-translation fallback). Group, features and first membership commit together or not at all. |
| rename | Name only; never regenerated automatically. |
| configure | Changes the feature set. Every member is validated against the proposal; problems or collisions block. A valid change returns an HMAC-bound impact preview (members, added/removed features, resulting combinations) and applies only when resubmitted with that token. |
| reorder | Same set, new order; presentation only, signatures unchanged. |
| add | 1–50 products, all-or-nothing, including duplicates among candidates; per-product errors. Members of another group must be transferred. |
| remove | Membership only; product untouched. Last member → group auto-disbanded. |
| transfer | Membership only, validated against the target; deadlock-free lock order; emptied source auto-disbanded. |
| disband | Group and configuration removed; all members unchanged and standalone. |
| createVariation | Explicit unused combination validated (including Disabled/Hidden members) before the existing Phase 4 `duplicate()` runs; then the chosen options are written on the copy and an intent is recorded. The copy stays standalone (Disabled, SKU/EAN cleared as before). |

Create-variation join: each product save that leaves the product complete tries to join its intended group in a savepoint. Success removes the intent and audits `member_added`; failure keeps the saved standalone product and intent and explains why (`variation_notice` flash and the editor notice).

## Lifecycle guards

- Product save: type locked while grouped; defining-value, category and completeness changes re-validate the product's own membership (other members' pre-existing problems do not block an unrelated edit). Activation re-validates. Status changes never detach.
- Standard Duplicate never copies membership or intent. Trash detaches in the same transaction (auto-disbanding an emptied group) and removes intents; restore returns a standalone Disabled product.
- Features: purpose change away from `variations`, archive and delete are refused while any group uses the feature (groups named). Definition, scope, completeness and option archive/label changes re-validate every group using the feature. Switching away from Brand / Author Landing Pages to either other purpose keeps the existing confirmation. Selector style, colors and swatch images stay stored under other purposes. Changing style needs no extra confirmation.
- Categories: a participant registered before the product participant refuses deleting a category holding grouped products (before any Uncategorized recovery) and re-validates groups in a saved category's subtree (moves, status). Disabling a group's leaf is therefore refused. Leaf protection (no children under assigned categories) is existing behavior.
- Not blocked: changing the CMS default language can make members incomplete. Membership is kept; the group screen lists the problems.

## Administration

Products → Variation Groups (`products.view`): server search (group name, member names in all languages, SKU), filters (category, type, defining feature, single/multiple members), sort (ID/name/members), 25/50/100 pagination. Group screen: members (name, SKU, status, one column per defining feature, editor links, problems), readiness (total; Active and complete members; switcher eligible at ≥2), selector order, feature-set change with preview, Create variation with reserved-combination detection, add existing products (standalone, same category/type, with per-candidate problems), remove/transfer/disband with confirmations, pending variations. Products list: Variation group column and Grouped/Standalone filter. Product editor: membership link or pending target with outstanding problems. Feature editor: third purpose, selector style, missing-swatch warnings, color picker/hex field and swatch image upload.

Permissions: viewing `products.view`; all group configuration/membership `products.update`; Create variation also `products.create` (+ view); swatch/style `features.update`; swatch images served to `features.view|update`. No new capability.

UI text: English and Greek; other UI languages use the explicit English fallback (as Features).

## Future storefront contract (not implemented)

Selectable values come only from Active, publicly available members of the current group, in the shared option order; Disabled/Hidden members never make values selectable. Prefer the exact real product; if exactly one candidate has the chosen value, navigate to it and sync selectors; with several candidates keep the current URL/product until one is identified; with none, reject. Never invent a combination. Missing swatch data falls back to the option label. Every product keeps its own canonical URL and normal listing behavior.

## Verification — 2026-10-10

`make check` passed: Pint (360 files), PHPStan, 283 backend tests (26,943 assertions), Prettier, ESLint, TypeScript, 81 frontend tests (17 files) and the production build. `VariationGroupTest` (17 scenarios) covers purposes/styles/DB checks, swatch normalization and separate media, dormant data, guided atomic creation, membership/category/type/value/uniqueness rules, bulk all-or-nothing, product edits/activation/status, configure preview/confirm and reorder, Create variation and deferred join, remove/transfer/disband/trash/restore, feature/option guards, category deletion/status/move guards, admin listing/filters/readiness/permissions, constraints, audits and migration round trip. `VariationGroupConcurrencyTest` uses two PostgreSQL connections. Frontend tests cover the three group screens, feature purpose/style/swatches and the products-list column/filter. The additive migration is applied locally; no permission sync is required. Browser review was not performed in this session.
