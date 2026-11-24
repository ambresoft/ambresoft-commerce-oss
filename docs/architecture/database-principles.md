# PostgreSQL database strategy

**Phase 4 exception/current contract:** Product basic prices use `numeric(20,6)` with configured input precision (initially two digits), never floats; this supersedes the earlier proposed minor-unit representation for this field. Products use soft deletion, a global case-insensitive assigned-SKU index and per-locale slug uniqueness including trash. File cleanup intents and product audits are now real consumed tables. See [Product integrity and transaction rules](products.md); Category hard deletion is unchanged.

PostgreSQL 18 is authoritative business storage. Use Eloquent and explicit relational joins/constraints, not a mandatory repository layer. Redis holds infrastructure state, never authoritative stock or prices.

A29 requires context-first DDD: needed repository interfaces belong in each context's Domain/Repository; Eloquent records, mappers and implementations belong in Infrastructure/Persistence. Domain and Application are framework-free. UserRecord explicitly retains the users table; UserMapper returns the plain Domain User, never credential hashes. The registration adapter translates database uniqueness failures to a Domain exception and uses a transaction/savepoint to preserve caller transaction usability. No generic CRUD abstraction or schema change is implied. [Layer conventions](layered-ddd.md).

## Conventions

| Concern       | Convention                                                                                                                                                                                  |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Names         | Plural snake_case tables, singular snake_case foreign keys ending _id; explicit descriptive constraint/index names when defaults are ambiguous                                              |
| Keys          | bigint auto-increment/identity-compatible Laravel IDs; matching bigint foreign keys. Serialize IDs to browser strings                                                                       |
| Relationships | Explicit foreign keys. RESTRICT deletion by default; CASCADE only for exclusively owned disposable children/joins; SET NULL only with a defined missing-owner meaning                       |
| Joins         | Unique composite membership keys; surrogate IDs only when assignments have a lifecycle/identity                                                                                             |
| Indexes       | Index referencing foreign keys where maintenance/queries require it; composite indexes follow access paths and stable sort. Do not duplicate indexes supplied by primary/unique constraints |
| Uniqueness    | Enforce business scope, case normalization and nullable semantics deliberately. PostgreSQL normally allows multiple NULLs in unique constraints                                             |
| Null          | Meaningful absence only, not empty-string/zero sentinels. Combine CHECK with NOT NULL when required                                                                                         |
| Time          | UTC timestamptz for instants; date for calendar dates. created_at/updated_at on mutable records; explicit occurred_at/effective_at where business meaning differs                           |
| Deletion      | No blanket soft deletes. Archive catalog records when retention requires it; preserve referenced financial history. Soft deletion requires a deliberate active-uniqueness policy            |
| Status        | Readable strings, application backed enums and database CHECK constraints for stable states. Avoid native PostgreSQL enums for evolving workflows                                           |
| Position      | Nonnegative integer scoped to parent/association; sort by position then ID; gaps allowed, no global resequencing on every edit                                                              |
| Slugs         | Normalize lowercase; unique by resource type and locale within installation. IDs remain identity; product URLs independent of category moves. Retain redirects when published slugs change  |
| JSONB         | Bounded validated external metadata or module settings only. Not relational feature/option/category/price data, arbitrary entity payloads or layout trees                                   |

Do not infer that a FK guarantees business applicability: selected feature values must belong to their feature; option variants must belong to their option. Use composite uniqueness/FKs where useful. Tree cycles and primary-category membership require deliberate transactional validation.

PostgreSQL does not automatically index referencing FK columns. CHECK cannot enforce arbitrary cross-row business invariants. Review access paths and representative EXPLAIN plans before denormalizing. [PostgreSQL constraints](https://www.postgresql.org/docs/current/ddl-constraints.html).

## Exact money and quantities

Persist monetary amounts as signed bigint minor units plus uppercase three-letter currency code referencing currency definitions once pricing exists. Currency definitions specify supported code and minor-unit exponent; do not assume two decimals (EUR 12.34 → 1234; JPY 12 → 12). Cross-currency arithmetic is prohibited without an explicit conversion contract.

| Meaning              | Representation                                                                                                                                        |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Base/sale/list price | Distinct monetary meanings, integer amount + currency. Sale price is an applicable price/rule, not a floating subtraction or mandatory product column |
| Cost                 | Separate restricted-access integer amount + currency; NULL means unknown, not zero                                                                    |
| Tax                  | Exact numeric(12,8) fractional rate (0.24 means 24%) and integer calculated amount snapshots                                                          |
| Discount             | Fixed monetary amount or exact numeric(12,8) fraction with explicit type; calculated allocations saved in minor units                                 |
| Quantity             | Proposed numeric(20,6) where fractional goods are supported; enforce integer quantities for indivisible items. Units/fractional support remain open   |
| Browser payload      | Integer amounts/decimal quantities/rates as strings; display formatting is not authoritative arithmetic                                               |

Use integer arithmetic or an arbitrary-precision decimal implementation for intermediate calculations. Never cast authoritative amounts/rates to PHP float or JavaScript number. Validate overflow, currency exponent, permissible negative values and rate bounds by business operation. Round only at documented boundaries; distribute allocation remainders deterministically so line totals reconcile. Tax inclusion, jurisdiction-specific rounding mode, unit-versus-line rounding and discount stacking must be decided before pricing/checkout. No money library or calculation engine is implemented in Phase 0.

## Ownership and atomicity

ProductVariation is the sole v1 SKU/stock/base-price owner, including one default variation for a simple product. Product owns merchandising/classification; option combinations express compatibility, not competing stock records. Preserve typed feature assignments and relational variation axes/value tuples; do not rely solely on a JSON tuple hash for integrity.

Services own substantial multi-record transactions. Prevent lost stock updates using database atomic conditions/row locks/version checks as appropriate; Redis locks are not database integrity. Keep a consistent lock order. Run external effects and queued reactions after commit. Do not assume remote calls roll back with SQL or add an outbox without delivery requirements.

## Migrations, history and tests

Use Laravel tracked migrations; once shared/applied, evolve with new migrations. Review ownership, data backfill, locks, defaults and rollback loss. Concurrent PostgreSQL index creation needs a nontransactional migration and recovery plan. No elaborate zero-downtime machinery for empty tables.

Application logs are not audit history. Future sensitive admin changes require explicit actor/action/target/outcome records with redaction; order/payment amounts and addresses become historical snapshots, not joins to mutable current values. No blanket event sourcing.

Phase 0 creates only failed_jobs infrastructure; Redis avoids database jobs/cache/session tables. See [initial schema](initial-schema.md) for proposed future tables, not migrations. Test database cms_test is isolated from cms; integration tests run against PostgreSQL, never use SQLite as proof of PostgreSQL semantics.

Phase 1 adds users/password_reset_tokens. Phase 2 adds roles/permissions, composite membership joins and locales, plus users.is_active/preferred_locale_id. Locale defaults use a partial unique index and an active-default CHECK; user preferences use an indexed restrictive foreign key. Supported access mutations serialize cross-row safety checks in PostgreSQL transactions. Entity translations use entity/locale uniqueness and locale-specific slug constraints, not language columns or JSON. See [authorization](authorization.md) and [localization](localization.md).

## Phase 3 category persistence

Only categories, category_translations and shared media_files are added. No product/order/membership tables. Categories use a nullable restrictive self-FK, indexed (parent_id, position, id), plus (status, id). The unique nullable image FK also indexes image ownership. CHECK constraints enforce active/disabled, non-self parent, positive versions and positive normal positions; a stable system key has one immutable disabled root at position zero. PostgreSQL triggers reject cycles, system parents, system mutation/deletion and merchant translations of system names.

`UNIQUE NULLS NOT DISTINCT(parent_id, position) DEFERRABLE INITIALLY DEFERRED` covers root siblings as well as children. Mutations explicitly defer it for atomic collision shifts and validate the final set before returning. Translation uniqueness is (category_id, locale_id) and (locale_id, slug); these indexes support both FK access paths, row fallback lookup and localized slug resolution. Translation owner deletion cascades; locale deletion restricts. ASCII normalized slugs and nonblank names also have CHECK constraints. No stored path or generic translation blob.

Supported tree mutations take PostgreSQL transaction advisory lock **2142513 before reading**; future Product membership writes must share it. This deliberately serializes category writes for correctness at current scale. Optimistic lock_version additionally protects stale browser edits, including shifted siblings. Direct maintenance must use the same lock/order, not treat triggers as a substitute for the application protocol. Recursive CTEs resolve ancestors/descendants; bounded tree pages eager-load requested/default/en rows and child counts without N+1 queries. Bound ILIKE search is sufficient initially; no unmeasured trigram/search infrastructure.

Leaf hard deletion removes only category/owned translations; children restrict it. Synchronous participants run before deletion in the same connection/transaction; future Product reassignment must finish there. Events run after the outermost commit. Private filesystem and SQL are not one atomic resource: failed new uploads are compensated, detached originals are retained, and operational cleanup remains a documented media retention task. The new migration is round-tripped only in cms_test; its owned functions use CREATE OR REPLACE so table-reset test setup remains repeatable. [Detailed contract](categories.md).

Category follow-up migration 000400 adds age_verification (false default), nullable integer age_limit (positive when present, required when enabled), and localized meta_keywords/age_warning_message (empty defaults). CHECK constraints protect age consistency, system-category defaults and warning length; existing immutable system triggers remain in force. Parent age fields are derived recursive reads, not persisted duplicates. Existing SEO/media columns are reused. No DOB/customer/visitor table or age index is added: there is no age-filtering access path. Base-migration test round trips unwind/reapply this dependent migration in order, only in cms_test.

Language administration migration 000500 adds four inactive locale reference rows without changing existing activation/defaults. UI wording overrides use a dedicated relational table keyed uniquely by `(locale_id, namespace, group, key)`, with restrictive locale ownership and nullable modifying-user attribution. They are not merchant content translations. Language activation/default writes share the category advisory lock so final completeness checks and CSV fingerprints use a consistent active set. Migration rollback removes only the override table; locale rows remain because preferences/content can reference them. [Current language policy](language-management.md).
