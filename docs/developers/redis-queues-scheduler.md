---
title: Redis, queues and the scheduler
parent: Developer guide
nav_order: 6
---

# Redis, queues and the scheduler
{: .no_toc }

1. TOC
{:toc}

---

## What Redis is used for

| Use | Configuration |
| --- | --- |
| Sessions | `SESSION_DRIVER=redis`, encrypted (`SESSION_ENCRYPT=true`), cookie `cms_session`, HttpOnly, SameSite=Lax |
| Application cache | `CACHE_STORE=redis`, prefix `cms_cache_`, Redis database `REDIS_CACHE_DB` (1) |
| Job queues | `QUEUE_CONNECTION=redis`, queues `default` and `maintenance` |
| Storefront content cache | The `storefront` cache store (`STOREFRONT_CACHE_STORE`), switched on in the administration |

The client is `phpredis` (`REDIS_CLIENT=phpredis`), and the keys share the `cms_` prefix (`REDIS_PREFIX`). Redis runs
with append-only persistence (`--appendonly yes`) in Compose and is not published outside Docker.

{: .warning }
> Sessions and queued jobs are **durable** data. Never configure an eviction policy on a Redis instance that also holds
> sessions and queues without assessing the data loss. In production, consider a separate Redis instance for the
> disposable storefront cache. Never use `FLUSHDB`/`FLUSHALL` on a shared instance.

## The storefront content cache

Disabled by default. Staff with the `cache.manage` permission switch it on under **Cache** in the administration
(`/admin/cache`), which first checks that Redis answers. See [Cache](../../admin/system/cache/) for the screen.

**What is cached:** layouts, grids, blocks and their HTML, guest navigation menus, banner composition, product
translations, galleries and feature values, category translations and URL paths, brand content and public image
metadata.

**What is never cached:** full HTML/Inertia responses, cookies, CSRF tokens, carts, checkout, accounts, wishlists,
comparison, and live values: prices, taxes, stock, visibility, customer groups and publication schedules.

**How it stays fresh:** a singleton row `storefront_cache_state` holds a revision per content group (layouts and blocks,
products, categories, brands, media). PostgreSQL triggers rotate the matching revision **in the same transaction** as any
change to those tables, including imports and bulk SQL; the revision is part of every cache key, so old entries simply
stop being read and expire within 330 seconds. Rollbacks roll back the revision too.

**When Redis fails:** cold misses use short atomic locks; on a lock timeout or Redis error the request reads from
PostgreSQL directly and skips the cache for the rest of that request. The store keeps working; the database takes the
load.

Optional dedicated connection: `STOREFRONT_REDIS_URL`, or `STOREFRONT_REDIS_HOST`, `STOREFRONT_REDIS_PORT`,
`STOREFRONT_REDIS_USERNAME`, `STOREFRONT_REDIS_PASSWORD` and `STOREFRONT_REDIS_DB`. Deployments that configure Redis only
through `REDIS_URL` must also set `STOREFRONT_REDIS_URL`.

{: .important }
> A new table or resolver whose data is cached needs its own invalidation trigger (a migration) and a cache-safety
> review. New tables do not automatically invalidate the cache.

## Queue worker

The `worker` service runs:

```sh
php artisan queue:work redis --queue=default,maintenance --tries=5 --timeout=60
```

with `restart: unless-stopped`. Jobs are dispatched **after the database transaction commits** (`after_commit=true`),
and `retry_after` is 90 seconds (`REDIS_QUEUE_RETRY_AFTER`), above the worker timeout.

Queued jobs in core: search indexing (`ProcessSearchOutbox`), newsletter deliveries (`SendNewsletterDelivery`), and on
the `maintenance` queue search rebuilds and reconciliation (`RebuildSearchIndex`, `ReconcileSearchIndex`) and private
file cleanup (`CleanupProductFile`). Bundled add-ons add jobs such as sitemap generation and Google Analytics purchase
events. Order emails are sent right after the order transaction commits, not through the queue.

```sh
docker compose logs -f worker                         # follow the worker
docker compose exec -T app php artisan queue:failed   # list failed jobs
docker compose restart worker                         # after deploying code or enabling/disabling an add-on
```

{: .warning }
> Diagnose a failed job before retrying it. Never blindly replay work with external effects such as payments.

In production, run workers under a process manager (systemd, Supervisor or a container restart policy) that restarts
them automatically.

## Scheduler

The `scheduler` service runs `php artisan schedule:work`. Core tasks:

| Task | Frequency |
| --- | --- |
| `search-sync`: queue indexing of pending catalog changes | every 5 seconds |
| `cms:product-files-cleanup`: dispatch committed private-file cleanup | every minute |
| `cms:newsletters-dispatch`: queue waiting and retryable newsletter deliveries | every minute |
| `cms:category-import-prune`, `cms:product-import-prune`, `cms:brand-import-prune`, `cms:region-import-prune`: delete expired private CSV uploads | hourly |
| `search-reconcile`: compare the search index with the catalog | daily at 03:40 |
| `search-terms-prune`: delete old one-off popular-search terms | daily at 03:50 |

Enabled add-ons add their own tasks (sitemap publication, product feeds, cart reminders). See them with:

```sh
docker compose exec -T app php artisan schedule:list
```

{: .important }
> The scheduler loads the enabled add-ons when it starts. **Restart it after enabling or disabling an add-on**:
> `docker compose restart scheduler worker`.

## Email locally: Mailpit

Every email the local store sends is caught by Mailpit and shown at [localhost:8025](http://localhost:8025). The
application sends SMTP to `mailpit:1025`; PHP `mail()` in the containers goes there too through a sendmail shim.
Mailpit can optionally relay selected recipients to a real SMTP server with the `MAILPIT_RELAY_*` variables in `.env`,
limited to addresses matching `MAILPIT_RELAY_MATCHING`.
