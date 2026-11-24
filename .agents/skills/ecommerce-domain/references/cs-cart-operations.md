# CS-Cart comparison: commerce and catalog operations

Researched 2026-10-08 against public current 4.21.x documentation. The behavior bullets summarize CS-Cart; proposed equivalents and dispositions are design candidates for this CMS. They do not authorize implementation or adopt upstream defaults.

## Inventory

- CS-Cart concept/problem: track merchandise quantity and determine availability.
- Behavior: stock quantity and tracking are configurable; negative inventory can be allowed, and out-of-stock actions include advance purchase or notification. [Inventory/availability settings](https://docs.cs-cart.com/latest/user_guide/manage_products/products/product_attributes.html).
- Proposed equivalent: core inventory operations with an explicit tracked identity, quantity policy and atomic updates.
- Disposition: keep stock/availability distinctions; modernize concurrency and change provenance; reject treating status, a cache or removed inventory combinations as stock truth.
- Open questions: locations, quantities/units, reservations, backorders, adjustment history, concurrency rules and stock ownership for variations/combinations. Do not invent a warehouse or ledger subsystem now.

## Pricing

- CS-Cart concept/problem: express ordinary and conditional selling amounts.
- Behavior: products have base and list prices. Quantity discounts can set a per-item price or percentage discount at a threshold, optionally limited to a user group. [Base/list price](https://docs.cs-cart.com/latest/user_guide/manage_products/products/product_attributes.html), [wholesale pricing](https://docs.cs-cart.com/latest/user_guide/manage_products/products/wholesale.html).
- Proposed equivalent: core price semantics with currency, applicability, exact arithmetic and deliberate precedence; add advanced rules only when scoped.
- Disposition: keep separate price meanings and useful conditional rules; modernize calculation contracts; reject implicit overlapping modifiers or frontend-owned price computation.
- Open questions: money storage, tax basis, rounding, currencies, tiers/group scope, modifier precedence and variation inheritance.

## Bulk editing

- CS-Cart concept/problem: reduce repeated merchant edits across a catalog.
- Behavior: merchants select products and fields to edit together, can apply a shared value and have a global price/stock update workflow. [Editing multiple products](https://docs.cs-cart.com/latest/user_guide/manage_products/products/add_product.html).
- Proposed equivalent: admin bulk commands that reuse normal validation, authorization and domain operations.
- Disposition: keep efficient explicit field updates; modernize selection scope, conflict feedback and failure semantics; reject bypassing business rules with unrestricted mass assignment.
- Open questions: selected-page versus all-results targeting, limits/queues, per-row versus batch atomicity, concurrent edits and progress/reporting.

## Cloning

- CS-Cart concept/problem: create similar merchandise without repeated data entry.
- Behavior: cloning copies product properties, excludes reviews and creates disabled clones through the list workflow. [Cloning a product](https://docs.cs-cart.com/latest/user_guide/manage_products/products/add_product.html).
- Proposed equivalent: a deliberate product-copy operation with documented copied, shared and reset fields.
- Disposition: keep merchant productivity; modernize identity and relationship handling; reject blind copying of unique identifiers or transactional history.
- Open questions: SKU/slug generation, initial status, media ownership, feature/option copying, variation membership and inventory/price reset rules. Do not assume every upstream copy behavior is known from this summary.

## Import/export

- CS-Cart concept/problem: exchange catalog data and update it at scale.
- Behavior: Advanced Products Import provides reusable CSV/XML field mappings and create/update strategies keyed by product code. Its “test import” writes a small subset, rather than guaranteeing a no-write preview. Export selects records/fields with language, delimiter and file settings. [Current import](https://docs.cs-cart.com/latest/user_guide/manage_products/import_export/advanced_product_import.html), [export](https://docs.cs-cart.com/latest/user_guide/manage_products/import_export/product_export.html).
- Proposed equivalent: explicit exchange contracts and identity matching that invoke the same business rules as admin edits; consider a genuine validation-only preview if requested.
- Disposition: keep mappings and repeatable exchange; modernize error reporting, retry/idempotency and protected file handling; reject copying proprietary field encodings or calling a writing sample a dry run.
- Open questions: first formats, identifiers, null/omitted/clear semantics, duplicate rows, chunk atomicity, media fetching, extension fields and basic core capability versus optional adapters. No XML support or import scheduler is adopted by this research.

## SEO

- CS-Cart concept/problem: give catalog pages readable addresses and search metadata.
- Behavior: product/category metadata is editable; the SEO addon configures clean URL patterns and language handling. [Category metadata](https://docs.cs-cart.com/latest/user_guide/manage_products/categories/category_attributes.html), [SEO settings](https://docs.cs-cart.com/latest/user_guide/addons/seo/seo_settings.html).
- Proposed equivalent: explicit catalog metadata and a storefront URL contract when storefront work is scoped.
- Disposition: keep addressability and metadata; modernize stable identifiers, collision rules and theme rendering; reject copying legacy dispatch URLs or a schema merely for compatibility.
- Open questions: basic SEO core versus optional advanced tools, slug uniqueness scope, localization, canonical product paths with multiple categories, variation URLs and redirect history. Do not assume old metadata fields are current search requirements.

