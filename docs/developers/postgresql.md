---
title: PostgreSQL
parent: Developer guide
nav_order: 5
---

# PostgreSQL

PostgreSQL 18 is the **authoritative store** for all business data: catalog, prices, stock, customers, orders,
settings and the audit log. Redis never holds authoritative stock or prices, and the search index can always be
rebuilt from PostgreSQL.

## Databases and users

On a **new** Docker volume, `docker/postgres/init-testing.sql` creates two databases with separate, non-superuser
owners:

| Database | User | Used by |
| --- | --- | --- |
| `cms` | `cms` | The application (`DB_DATABASE=cms`, `DB_USERNAME=cms`) |
| `cms_test` | `cms_test` | The automated tests only |

Each user can connect only to its own database. The init script runs only when the volume is created; do not delete a
volume to "fix" it. The Compose `postgres` superuser account is local-only: never reuse these development passwords in
production. PostgreSQL is not published to your computer's network; reach it through the containers or the optional
pgAdmin service.

## Conventions

| Concern | Convention |
| --- | --- |
| Names | Plural `snake_case` tables, singular foreign keys ending in `_id` |
| Keys | `bigint` identifiers; sent to the browser as strings |
| Relationships | Explicit foreign keys. `RESTRICT` on delete by default; `CASCADE` only for children owned exclusively by the parent |
| Uniqueness | Business scope, case normalization and `NULL` behavior decided deliberately (for example case-insensitive SKUs, slugs unique per language) |
| Time | UTC `timestamptz` for moments, `date` for calendar dates |
| Deletion | No blanket soft deletes. Products use soft deletion (Trash) on purpose; financial history is preserved |
| Status | Readable strings with `CHECK` constraints, not native PostgreSQL enums |
| Translations | One translation table per entity with a row per language, never `name_en`/`name_el` columns or JSON |
| JSONB | Only bounded, validated metadata or add-on settings, never relational catalog or price data |

## Exact money

Money and rates are never floating point. Product prices use `numeric(20,6)` and are accepted at the primary currency's
configured decimals; PHP handles amounts as decimal strings through `App\Shared\Domain\Service\Decimal`, and the browser
receives them as strings.

## Integrity in the database

Rules that must hold no matter which code path writes are enforced by PostgreSQL itself:

- foreign keys, unique and `CHECK` constraints;
- **triggers** that queue products for the search index in the same transaction as the catalog change
  (`search_outbox`), so a committed change can never miss its search update;
- **triggers** that rotate storefront-cache revisions in the same transaction as content changes, so imports, bulk
  updates and cascading deletes invalidate the cache too;
- **advisory locks** that serialize sensitive multi-row operations such as staff access changes.

## Migrations

- Use Laravel migrations; once a migration has been shared, change the schema with a **new** migration.
- Review locks, data backfills, defaults and what a rollback would lose.
- Add-ons ship their own migrations, named `YYYY_MM_DD_HHMMSS_{vendor}_{name}_*.php`, and own tables prefixed with
  their vendor and name.

```sh
docker compose exec -T app php artisan migrate
docker compose exec -T app php artisan migrate:status
```

## Backups

Back up the `cms` database regularly, for example with `pg_dump`:

```sh
docker compose exec -T postgres pg_dump -U postgres -d cms | gzip > cms-$(date +%F).sql.gz
```

Uploaded files live in `storage/`; back them up together with the database. After restoring a database, clear the
storefront cache in the administration and rebuild the search index if needed.
