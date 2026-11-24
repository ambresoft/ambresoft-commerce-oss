# PostgreSQL and ecommerce persistence conventions

The normalized relational direction is accepted. Naming and implementation choices below are proposed conventions; adopt concrete choices for the relevant slice and record consequential ones. No schema is specified here.

## Names and relationships

Prefer Laravel-friendly snake_case table/column names, consistent foreign-key naming and explicit relationship methods. Choose primary key types once their scope is known; do not mix bigint, UUID and ULID incidentally. Name unusual constraints/indexes predictably for diagnosis and migration changes.

For each relationship, identify the owning records, cardinality, optionality and deletion behavior. Use join tables with uniqueness over the actual relationship; add a surrogate ID only if the relationship needs its own identity. Ensure a foreign-key target has compatible types and the appropriate primary/unique key. Avoid polymorphic references where they would unnecessarily sacrifice enforceable integrity.

Do not use sentinel identifiers such as zero for missing relationships. Use nullable relationships only when absence has a business meaning. Choose cascade, restrict or nullification deliberately; soft deletion is not a global requirement and does not automatically solve uniqueness/retention.

## Constraints and indexes

Use NOT NULL, foreign keys, unique constraints and row-local checks where appropriate. PostgreSQL CHECK permits a null result, so required values also need NOT NULL. Nullable unique keys permit multiple nulls by default; choose null semantics explicitly. Cross-row checks need suitable relational constraints or transactional application logic, not a CHECK that queries changing tables. Primary/unique constraints create indexes; referencing foreign-key columns do not get indexes automatically. [PostgreSQL constraints](https://www.postgresql.org/docs/current/ddl-constraints.html).

Review indexes against joins, filters, sorting and foreign-key maintenance. Composite index order should reflect access patterns. Avoid redundant indexes already covered by keys; consider selective partial indexes only for a real predicate. Check representative query plans and data volumes when performance matters rather than adding an index to every column.

## Ecommerce integrity checks

These are modeling questions, not approved tables or rules:

- Product/category memberships: prevent duplicate membership; if a primary category is adopted, keep it a valid membership. Define tree cycle and removal handling explicitly.
- Feature assignments: ensure a selected value belongs to the assigned feature; express ownership through appropriate keys where possible. Separate typed scalars and predefined choices rather than bypassing constraints in JSON.
- Options: ensure a variant belongs to the option and is applicable to the product. Define canonical tuple identity and at most one selection per applicable single-choice option when modeling combinations.
- Variations: define group/axis membership and tuple uniqueness once the sellable model is chosen. Do not assign both a combination and a variation independent stock truth without a deliberate reconciliation rule.
- Inventory: define units, tracked owner and negative-stock policy before constraints. Prevent lost updates using suitable atomic updates, row locks or version checks; Redis locks alone do not establish database integrity.
- Prices: define exact representation, currency, scale, rounding and applicability. Avoid binary floating point for authoritative monetary calculations. Do not assume all currencies have two fractional digits or every price must be strictly positive.

## Transactions and effects

Choose an application operation as the transaction owner. Consider conflicting concurrent edits, not just sequential validation. Keep transactions short enough for the operation and use consistent locking order if multiple records are locked. Define retry behavior only for safely retryable work.

Avoid irreversible external calls inside an assumed database rollback boundary. Dispatch external reactions/jobs with deliberate commit timing. An outbox or ledger is not a default requirement; add one only when delivery/history guarantees justify it. Invalidate derived caches after the authoritative change succeeds.

## Migrations and storage

Review migration order, existing data, backfills, defaults, index/constraint creation and rollback consequences. Once migrations have been applied to shared environments, use new migrations instead of rewriting history. Validate on PostgreSQL when using its semantics; SQLite is not proof of equivalent behavior.

For deployed tables, plan lock impact and staged changes where necessary; do not impose elaborate zero-downtime machinery on an empty initial schema. Concurrent PostgreSQL index creation cannot run inside a transaction block, so plan its migration/recovery separately when needed. [CREATE INDEX](https://www.postgresql.org/docs/current/sql-createindex.html).

JSONB can be appropriate for bounded external metadata or validated module settings with a clear reason. Keep relationships, searchable typed commerce facts and constraints relational. Files may live in a file/object store with relational metadata; this does not make Redis or arbitrary blobs the commerce system of record. Presentation layout remains in theme/component code.

