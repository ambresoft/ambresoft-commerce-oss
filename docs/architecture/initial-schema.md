# Initial schema boundary

Implemented foundation entities are listed explicitly below; future entity names remain documentation, not generated models.

## Phase 0: actual infrastructure

- Laravel migrations repository: framework tracking of applied migration names/batches.
- failed_jobs: id, unique UUID, connection, queue, payload, exception, failed_at; supports Redis queue failure inspection. Restrict log/payload access and retention.
- Redis supplies sessions, queues and cache; no database session/cache/jobs tables.

Phase 0 created no user, customer, store, catalog or commerce tables.

## Phase 1: implemented identity foundation

- users: bigint ID, name, normalized unique email with a PostgreSQL CHECK, password hash, nullable email_verified_at, remember_token and timezone-aware timestamps.
- password_reset_tokens: email primary key, hashed broker token and nullable created_at, following Laravel's supported recovery mechanism. Sessions remain Redis.
- Phase 2 now adds roles, permissions, explicit membership joins and locales, plus users.is_active/preferred_locale_id. No customer fields, ecommerce tables or seeded credentials. See [authorization](authorization.md) and [localization](localization.md).

See [authentication](authentication.md). Persisted staff grants and explicit operator recovery are implemented in Phase 2. Invitation UX, MFA/recent-password requirements and production recovery procedures remain release decisions.

## Future entities — do not migrate now

| Domain            | Candidate entities / relationships                                                                                                 |
| ----------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Store             | Installation configuration first; eventual singleton store trading identity/settings if merchant editing requires it. Not a tenant |
| Catalog           | products, product_variations, categories, category_product, brands, media and explicit product/variation image associations        |
| Features          | feature_groups, features, feature_values and typed product/variation assignments/axes                                              |
| Options           | options, option_variants, product_options, compatibility combinations and relational selections                                    |
| Inventory/pricing | inventory balances/operations, prices, supported currencies, tax classes/rates, discounts and applicability joins                  |
| Customer/purchase | customers, addresses, carts/lines/selections, orders/lines/snapshots, payments/attempts, shipments/items                           |
| Operations        | Audit history and module installation/upgrade state when the corresponding lifecycle is implemented                                |

Do not add a universal store_id, polymorphic Sellable, generic attributes table or future-feature scaffolding. Merchant module state must eventually become one clearly defined authority; do not create competing database flags and configuration flags.

See [domain ownership](domain-model.md), [conventions](database-principles.md) and [decisions](decisions.md) before final schema design.
