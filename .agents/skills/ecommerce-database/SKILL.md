---
name: ecommerce-database
description: Design or review PostgreSQL relational persistence for this CMS, including ecommerce relationships, constraints, indexes, transactions and migration plans. Use for schema and Eloquent persistence changes; obtain business semantics from ecommerce-domain rather than inferring them from tables.
---

# Ecommerce database

Read [database-principles.md](../../../docs/architecture/database-principles.md) and the relevant sections of [conventions.md](references/conventions.md).

- Identify ownership, cardinality, optionality and uniqueness before selecting columns. Use explicit foreign keys and join tables for relational concepts.
- Separate features, feature values, product assignments, options, option variants, combinations and variations. Avoid one generic attributes table for all concepts.
- Enforce invariants in PostgreSQL where expressible; use application validation for helpful feedback and cross-row business rules. Account for concurrent writes.
- Choose transaction boundaries around business operations. Schedule external effects and asynchronous work with explicit commit timing.
- Use Redis for suitable infrastructure, not as the authoritative catalog, price or stock database.
- Use indexes based on actual access paths and constraint needs. Check referencing foreign-key columns; PostgreSQL does not automatically index them.
- Use JSONB only for bounded data with a reason; do not use it to replace category membership, feature assignments, price structure or storefront layout ownership.
- Define migration ordering, backfill and failure recovery before changing persisted data. Keep module migrations owned and tracked; disabling a module must not roll them back.
- Follow [context-first DDD](../../../docs/architecture/layered-ddd.md): put interfaces in {Context}/Domain/Repository and records/mappers/adapters in Infrastructure/Persistence. Return plain Domain objects, not Eloquent types, across those contracts. Preserve the explicit UserRecord users table mapping and existing hashes/IDs. Translate storage failures and preserve transaction usability after handled PostgreSQL errors with an appropriate rollback/savepoint; add no generic mapping framework.

Record identifier strategy, money representation, localization and sellable identity decisions before relying on them. Do not copy CS-Cart schemas, create speculative migrations, add search infrastructure early or wrap Eloquent in an unnecessary abstraction layer.
