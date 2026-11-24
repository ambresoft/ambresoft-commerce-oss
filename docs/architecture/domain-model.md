# Ecommerce domain model

**Phase 4 update:** [Products](products.md) now implements Product-owned SKU/basic price, normalized translations, one leaf category, shared gallery and separately tracked private files. The earlier default-variation ownership below remains historical design context, superseded for the current phase by A44. Do not scaffold variations or inventory. Categories use their existing tree and transactional deletion participant to recover products to Uncategorized/Disabled.

Phase 0 design with implemented Identity/Authorization/Localization foundations and the Phase 3 Category contract below. Other commerce fields remain candidates, not implemented models/migrations. All essential concepts below belong to Core; optional specialized behavior belongs to modules. Each future schema must apply [database conventions](database-principles.md). Single-store installation ownership is accepted; no tenant/vendor model.

## Four different meanings

| Concept | Answers | Example | Identity / stock |
| --- | --- | --- | --- |
| Feature | What describes this merchandise? | Material = Cotton | Typed descriptive fact; may be designated a variation axis |
| Option | How does the customer customize this purchase? | Wrapping, engraving | Selection/input attached to purchase, not automatically a SKU |
| Variation | Which sellable merchandise is selected? | Blue / Medium shirt | Dedicated ProductVariation, unique SKU and authoritative inventory/base price |
| Option combination | Are these customizations compatible? | Premium wrap + a particular gift box | Relational choice tuple/rule; no separate stock identity in v1 |

Product owns merchandising. A simple product has one default ProductVariation. A variable product has multiple actual sellable variations identified by allowed feature-value tuples. Do not generate every Cartesian tuple or universal inheritance machinery by default. A dropdown can select either a variation or an option; visual controls do not determine semantics.

Current CS-Cart variations group products distinguished by feature values; we preserve that benefit but choose dedicated variation records. Its old stock-bearing option combinations were removed in 4.12.1; current exceptions constrain allowed/forbidden option choices. Do not replicate legacy inventory ownership. [Current variations](https://docs.cs-cart.com/latest/user_guide/manage_products/products/product_variations.html), [historical removal notice](https://docs.cs-cart.com/latest/user_guide/manage_products/options/option_combinations.html), [current exceptions](https://docs.cs-cart.com/latest/user_guide/manage_products/options/exceptions.html).

## Identity and store

### Store

- Purpose/fields: installation trading identity, name, contact/business address, default locale/currency/timezone and configuration references.
- Relationships/ownership/lifecycle: one installation; configuration initially, eventual singleton merchant-editable record when required. Core, not a tenant or parent FK on every entity.
- Constraints/extensions: single-store v1; do not equate commercial distribution with hosted tenancy. Modules may add typed store settings. Future multi-store requires deliberate ownership/scoping migration, not a flag.

### User

- Purpose/fields: authenticated person identity, name, normalized unique email, password hash and chosen verification/security lifecycle fields.
- Relationships/ownership/lifecycle: Core identity; explicit staff grants/roles, optional Customer profile. Provision → active → suspended/revoked under selected Phase 1 rules.
- Constraints/extensions: being a customer is never an admin grant; no credentials in props/logs, no shared default password. Modules declare abilities, not grants. Enrollment, recovery and MFA remain open.

### Customer

> **Phase 9 (A71):** implemented as a profile separate from staff identities, without the optional `user_id` until customer sign-in exists. See [customers.md](customers.md).

- Purpose/fields: purchaser profile, optional user_id, contact preferences and lawful customer data.
- Relationships/ownership/lifecycle: Core; at most one profile per linked User, purchase/address relationships. Profile lifecycle distinct from User; guest-checkout linkage and account merging decided before checkout.
- Constraints/extensions: do not require an account merely because User exists; preserve financial history/privacy obligations. Modules can add consent/loyalty integrations through explicit extension tables, not arbitrary core columns.

## Merchandising and classification

### Product

- Purpose/fields: merchandise catalog identity, name, description, publication state, slug/SEO metadata, optional brand and primary-category membership.
- Relationships/ownership/lifecycle: Core catalog; owns variations, category memberships, feature assignments, applicable options and image associations. Draft → published/hidden/disabled/archived vocabulary to finalize.
- Constraints/extensions: publication is not stock availability or authorization. One default variation for simple products; identifiers stable, no duplicate product per category. Modules add validated detail sections/metadata using owned relationships.

### Category

- Purpose/fields: implemented core Catalog classification. categories owns parent_id, positive sibling position, active/disabled status, optional private image reference, protected system_key, optimistic lock_version and UTC timestamps. category_translations owns locale-linked name, plain-text description, SEO title/description, slug and image alt text.
- Relationships/ownership/lifecycle: parent-child adjacency with no depth cap; create → independently edit translations/reparent → hard-delete an unreferenced leaf. Children block deletion through both application rules and restrictive FKs; only exclusively owned translation rows cascade. No blanket soft deletes. Published history/redirect retention must precede storefront publication.
- Paths/order: indexed PostgreSQL recursive CTEs resolve root-through-self and descendant IDs without stored paths or product duplication. A move changes one parent edge. Positions are unique per sibling set, roots included; collisions shift later siblings atomically, gaps survive deletion. Serialized mutations plus version checks prevent invalid moves/stale edits. See [category invariants and tradeoffs](categories.md).
- Future products: normal assignments must use normal **leaf** categories. Membership cardinality/primary-category policy remains a Product-phase choice; no Product class/table/join exists. Products resolve ancestors from the tree for breadcrumbs/aggregation. A synchronous transaction participant must reassign affected last/primary memberships to immutable system Uncategorized before leaf deletion; before-parent-use participation protects assigned leaves from gaining children. Product writes must coordinate on the same category transaction lock and use restrictive FKs.
- System/visibility: system_key=uncategorized identifies the immutable, disabled, parentless recovery category, never its UI-translated name. It cannot be normally edited/deleted/parented/assigned or exposed in storefront navigation. Future navigational visibility requires a normal active category and active normal ancestors. Disabling is not deletion and does not alter descendants.
- Extensions: CategoryCreated/Updated/Moved/Deleted are typed after-commit facts. Transaction participants can veto or perform same-connection SQL before deletion/parent use; queued listeners cannot enforce reassignment. Feature applicability and disabled-category assignment policy remain open.
- Requested category follow-up adds own age_verification/age_limit and localized age_warning_message/meta_keywords. Existing seo_title/seo_description/slug provide Page title/Meta description/SEO name without duplicate truth. Parent age values derive from the selected parent's ancestor path; the highest enabled minimum wins and child disabling cannot weaken it. Customer age-proof/access enforcement is not implemented. [Exact semantics and field mapping](categories.md#requested-metadata-and-age-settings-follow-up), A40.

### Brand

- Purpose/fields: first-class controlled manufacturer/brand identity, name, slug, description and optional image.
- Relationships/ownership/lifecycle: Core catalog; optional Product association; create → rename/merge/archive with explicit reference handling.
- Constraints/extensions: do not store both an unrelated brand feature and brand_id as competing truth. Brand pages/localization/uniqueness and richer manufacturer data remain open; modules may extend relationships. CS-Cart's brand-feature packaging is not our schema.

### Product images

- Purpose/fields: relational media metadata (storage disk/key, MIME, size/dimensions, safe original filename) plus product/variation association, role, position and alt text.
- Relationships/ownership/lifecycle: Core media capability; explicit product/variation joins with enforceable FKs. Upload → validated private original → approved derivative/delivery → detached → retention-aware cleanup.
- Constraints/extensions: no public executable uploads, path supplied by client or duplicate main image per scope; sharing versus copying/variation overrides decided before catalog media. Storage adapters/derivatives may be extended. No media package or public gallery now.

## Descriptive features

### Feature group

- Purpose/fields: merchant organization of definitions, name and position.
- Relationships/ownership/lifecycle: Core catalog; proposed one optional group per Feature, not a variation group. Create → reorganize → archive/remove.
- Constraints/extensions: deleting a group must not silently delete product facts. Exact group membership/applicability remains open; modules may supply specialized editors/validation.

### Feature

- Purpose/fields: descriptive definition, stable code, label, explicit value type, unit where relevant, filterability and variation-axis eligibility.
- Relationships/ownership/lifecycle: Core catalog; optional group, controlled FeatureValues and typed assignments. Define → use → evolve compatibly → retire.
- Constraints/extensions: changing type after assignments needs migration/validation; do not interpret customer text customization as a feature. Supported types, multi-values and category applicability open. Modules may add deliberate typed feature capabilities, not universal JSON attributes.

### Feature value

- Purpose/fields: predefined value belonging to one Feature, code/label, position and lifecycle state.
- Relationships/ownership/lifecycle: Core catalog; reusable across Product/Variation assignments; define → select → merge/retire.
- Constraints/extensions: value must belong to selected feature, unique normalized code within feature; protect referenced variation axes/history. Scalar dates/numbers/text belong to typed assignments where appropriate, not fake lookup values. Localization/merging remain open.

### Product feature (assignment)

- Purpose/fields: a Product's descriptive fact: product_id, feature_id, typed scalar or references to controlled values.
- Relationships/ownership/lifecycle: Core catalog; created/changed through product operations; variation-specific axis assignments have a distinct owner/scope.
- Constraints/extensions: enforce feature/value/type consistency and chosen cardinality; no ambiguous multiple typed columns or JSON bag without constraints. Category changes must not silently erase facts. Modules participate through validated assignment operations.

## Customer customizations and sellable identity

### Option

- Purpose/fields: customer input definition, code, label, input type, requiredness and validation contract.
- Relationships/ownership/lifecycle: Core catalog; owns OptionVariants, applied via ProductOption; define → apply → retire.
- Constraints/extensions: required choices checked on server even if UI hides them; text/file input is not a predefined variant. Supported input types and reusable/global versus local definitions remain open. File input requires separate upload review before support.

### Option variant

- Purpose/fields: predefined allowed choice, option_id, code/label, position, state and optional typed price/weight modifier.
- Relationships/ownership/lifecycle: Core; belongs to exactly one Option, referenced by selections/compatibility rules. Define → active → retire.
- Constraints/extensions: not ProductVariation or FeatureValue. Exact modifier precedence/rounding open; purchase snapshots survive retirement. Modules may supply validated adjustment strategies, never client-authoritative money.

### Product option

- Purpose/fields: explicit application of an Option to a Product, product_id/option_id, position and deliberately supported overrides.
- Relationships/ownership/lifecycle: Core; unique product-option pair, local applicability and validated selections. Attach → configure → detach.
- Constraints/extensions: no accidental copying versus sharing or silent global edits; override fields/requiredness policy need specification. Future UI contributions invoke the same application service.

### Option combination

- Purpose/fields: applicable product, allow/deny rule semantics and relational members (option/variant choices), optional canonical tuple identifier.
- Relationships/ownership/lifecycle: Core compatibility; references applicable ProductOptions/OptionVariants. Create/regenerate → evaluate → retire when choices change.
- Constraints/extensions: each member belongs to applicable option; no duplicate single-choice member or duplicate canonical tuple. Not a second stock/price identity. Allowlist/denylist defaults, wildcards and text-input participation remain open; modules may extend validation through typed contracts.

### Product variation

- Purpose/fields: sellable ID, product_id, normalized SKU, default flag, state, distinguishing feature values and explicit permitted overrides.
- Relationships/ownership/lifecycle: Core catalog; many to one Product, owns inventory and base prices; one default for simple merchandise. Create actual tuple → activate → discontinue/archive.
- Constraints/extensions: installation-wide SKU uniqueness for assigned SKUs, unique axis tuple within Product, values valid for axes, no tuple ambiguity. SKU requiredness/case policy, partial tuples, override fields and listing visibility remain open. Modules can add external identifiers without replacing core identity.

## Inventory and monetary policy

### Inventory

> **Superseded (Phase 7, A67):** the warehousing/complex-inventory design below is archived and not implemented. Current scope is one global integer `quantity` per product record plus `min_qty`, `max_qty`, `qty_step` and `out_of_stock_actions` on Product; see [products.md](products.md#simple-product-stock-phase-7). The text below remains historical context only.

- Purpose/fields: stock balance and changes for ProductVariation, tracking policy, quantity and eventual location/reservation context if authorized.
- Relationships/ownership/lifecycle: Core inventory; receive/adjust/reserve/release/consume through explicit operations, not model observers.
- Constraints/extensions: database atomicity prevents lost updates/overselling according to chosen policy. Negative stock, fractional units, locations, reservations/backorders/history design open. Modules may integrate warehouses via operations; no warehouse/ledger scaffold now.

### Price

- Purpose/fields: ProductVariation owner, minor-unit amount, currency, meaning/applicability and optional effective interval/quantity/customer scope when requested.
- Relationships/ownership/lifecycle: Core pricing; base and applicable sale/list meanings distinct from restricted cost. Define → schedule/apply → expire.
- Constraints/extensions: no float; explicit currency and deterministic precedence; no assumed live currency conversion or nullable polymorphic owner. Supported currencies/tier rules/tax basis/overlap policy open. Modules may add typed rules, not mutate global price arrays.

### Tax class

> **Superseded by Phase 8 (A69):** taxes are merchant-defined definitions with per-area rates and product assignments, not a class code. The notes below are retained for history; see [taxes.md](taxes.md).

- Purpose/fields: merchant classification of taxable merchandise, stable code/name and future jurisdiction/rate applicability.
- Relationships/ownership/lifecycle: Core tax contract; assigned to appropriate merchandise; effective rules versioned/snapshotted in purchase calculations. Define → apply → supersede/retire.
- Constraints/extensions: rates exact decimals, computed tax integer minor units; do not hardcode legal rates or universal rounding. Jurisdiction/location/inclusive policy open. Optional tax provider modules implement explicit calculation contracts; core retains totals/snapshots.

### Discount

- Purpose/fields: fixed amount/currency or exact percentage, eligibility, validity interval, optional code and usage policy.
- Relationships/ownership/lifecycle: Core pricing/order adjustment semantics; advanced campaigns optional modules. Draft → active → exhausted/expired/disabled.
- Constraints/extensions: explicit stacking/order, bounds, eligibility and atomic usage enforcement; snapshot allocated results. Do not implement campaign engines now. Modules may add predicates/strategies under invariant-preserving contracts.

## Purchase lifecycle (future)

### Cart

- Purpose/fields: purchase intent, identity/session/customer association, currency, lines with variation/quantity and option selections.
- Relationships/ownership/lifecycle: Core checkout; mutable intent → revalidated quote → converted/abandoned/expired.
- Constraints/extensions: server rechecks price/stock/option validity and authorization; cart is not a historical invoice or guaranteed reservation. Implemented (2026-11): public uuid per cart, one cart per customer, guest carts by encrypted cookie merged on sign-in (see [storefront architecture](storefront-architecture.md#cart)); guest expiration is still open. Modules add validated adjustments/delivery choices.

### Order

- Purpose/fields: durable purchase record, order number, customer/contact/address snapshots, currency, line/discount/tax/total snapshots and explicit workflow state.
- Relationships/ownership/lifecycle: Core orders; owns lines, references multiple Payments/Shipments. Created → processed/completed/cancelled under later state design.
- Constraints/extensions: totals reconcile exactly; later catalog edits must not rewrite history. Financial/payment/shipping states not one overloaded status. Numbering, legal retention, transitions and edit policy open. Modules react to committed OrderCreated.

### Payment

- Purpose/fields: obligation/attempt/transaction context, order_id, provider reference, currency, authorized/captured/refunded amounts, idempotency keys and state.
- Relationships/ownership/lifecycle: Core accounting of payment facts; optional provider integrations are modules. Initiated → authorized/captured/failed → refund/void as appropriate.
- Constraints/extensions: no raw card credentials; verify signed callbacks, prevent duplicate capture and persist immutable references. Attempt versus transaction tables, reconciliation and refund workflows open. PaymentCaptured only after verified durable fact, not frontend success.

### Shipment

- Purpose/fields: fulfillment identity, order_id, shipped line quantities, carrier/service/tracking and shipping timestamps/state.
- Relationships/ownership/lifecycle: Core fulfillment; order may have multiple shipments; carrier integrations optional modules. Prepared → dispatched → delivered/exception/cancelled.
- Constraints/extensions: reconcile shipped quantities with order/returns; tracking is not proof of payment. Partial shipment/location/returns policies open. Modules add carrier adapters without replacing order history.

## Cross-cutting catalog operations

Statuses express publication separately from stock. Filters read explicit feature/commerce facts, starting with PostgreSQL. Bulk edits, cloning and import/export reuse the same validation, policies and business operations; cloning must reset unique IDs/history. SEO uses stable resource slugs and redirect history; metadata is not a layout model.

For researched CS-Cart concept/problem/behavior → keep/modernize/reject mappings and unresolved questions, read the existing skill references: [catalog](../../.agents/skills/ecommerce-domain/references/cs-cart-catalog.md), [features/options](../../.agents/skills/ecommerce-domain/references/cs-cart-features-options.md), [operations](../../.agents/skills/ecommerce-domain/references/cs-cart-operations.md), [extensions/themes](../../.agents/skills/ecommerce-domain/references/cs-cart-extensions-themes.md). They are conceptual research, never a source/schema specification. Current project decisions supersede older proposed equivalents; no proprietary source was copied.
