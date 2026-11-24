# Storefront content cache

Redis caching is opt-in, initially **disabled**, under **Cache** in the main admin menu (`/admin/cache`). The persisted `cache.manage` capability controls the page, enabling/disabling and all clearing actions. Super administrators have access; other staff need a role grant. Settings changes use optimistic locking; changes and clears are audited. This switch controls storefront content caching, not Laravel sessions, queues, throttles or other existing infrastructure caches.

## Content boundaries

| Group            | Cached data                                                                                                                                |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Layouts & blocks | Layout/grids/placements/settings; public HTML, topbar and category blocks; guest navigation trees; banner composition texts/device layouts |
| Products         | Translations/descriptions, galleries, feature values                                                                                       |
| Categories       | Translations and hierarchical URL paths                                                                                                    |
| Brands           | Brand cards, product-page brand information and brand/feature landing content                                                              |
| Media            | Public image metadata and presence checks                                                                                                  |

Plain arrays/scalars only; Redis object deserialization remains disabled. Locale, input IDs and relevant display arguments are part of keys. The shared infrastructure service is request-scoped. Customer-specific menu/block rendering bypasses shared output caching. Public content fragments can be reused across visitors, but their callers retain live eligibility checks.

Never cache an Inertia/HTML response, cookie, CSRF token, identity header, cart, checkout, account, comparison or wishlist. Product prices, taxes, stock, active/public eligibility, customer groups, recommendations and publication schedules remain live. Banner _composition_ is cached after live eligibility selection; product-bound banner content still checks visibility. Scheduled/promotional/addon resolvers are not blindly wrapped. Checkout remains authoritative in PostgreSQL.

## Invalidation and concurrency

`storefront_cache_state` is a singleton with a disabled-by-default setting, optimistic settings version and five UUID revisions. Each request reads this small row once; revisions form Redis key namespaces. Revisions must not themselves be cached in Redis. A missing/evicted Redis entry is a miss, never a resurrection of an older namespace. Restore/deploy procedures should clear storefront cache after the database is ready.

PostgreSQL statement triggers change revisions in the same transaction as mutations to consumed catalog, layout, menu, banner, media, locale and general settings tables. Imports, bulk SQL and cascading deletes are covered. Rollbacks roll back revisions. Read/write transactions bypass shared cache entirely to avoid publishing uncommitted data. Inventory and price writes do not invalidate static product content because those values are never cached.

Manual partial clears rotate the selected group's revision. Dependencies also refresh: blocks depend on all groups; product content on categories/media; category paths/content on media; brand landing content on categories/products/layouts/media. Full clear rotates all five. Clearing never uses Redis `FLUSHDB`, `FLUSHALL`, `KEYS`, or session/queue deletion. Already-running requests may finish on their original revision; subsequent requests read the new one. Old keys are immediately unreachable and physically expire within 330 seconds.

Cold misses use atomic locks (30-second lease, up to 2-second wait), recheck after acquiring, and expire after 300–330 seconds with jitter. On lock timeout or Redis failure, reads fall back to the original source and skip further cache calls for that request. Source/database exceptions propagate normally, and exceptions are not cached. This preserves availability during Redis trouble; it does not promise the database will sustain peak traffic without Redis.

New tables/resolvers require an explicit cache-safety review and a migration adding any necessary invalidation trigger. The installation migration discovers only its listed core table families; future tables do not automatically gain triggers. Changes to the compiled payload shape must bump the `storefront-content:v1` key version or clear before rollout. Do not cache future addon output without its own dependency, audience and schedule contract.

## Operations and deployment

1. Deploy code and run `php artisan migrate` and `php artisan cms:permissions-sync`.
2. Build assets (`pnpm build`) and restart long-lived application workers as usual.
3. Open Cache in the main admin menu. It checks a short-lived Redis read/write key. Enable and save only after the connection is healthy. Keep caching disabled until deployment validation is complete.
4. Warm representative URLs through normal visits if desired. Rebuilds are lazy; there is no full-catalog preload job.

`STOREFRONT_CACHE_STORE` defaults to the `storefront` Redis cache store; test suites can select `array`. Its separate connection uses one-second connect/read timeouts and no retries. By default it uses the existing Redis host and cache database. `STOREFRONT_REDIS_URL` or `STOREFRONT_REDIS_HOST`, `PORT`, `USERNAME`, `PASSWORD`, `DB` can direct this connection to a dedicated cache instance (the latter names have the same `STOREFRONT_REDIS_` prefix). Deployments using only `REDIS_URL` must also set `STOREFRONT_REDIS_URL`. Its backend must support atomic locks. Never set an eviction policy on a Redis instance shared with durable queues or sessions without assessing those data-loss semantics. Production should isolate disposable cache memory, configure memory limits/timeouts, monitor Redis latency/memory/evictions and database load, and load-test realistic anonymous/authenticated traffic. Redis does not replace PHP capacity, database indexes, a production web server, CDN/static-media delivery or traffic protection.

The admin connection status is a connectivity check, not a traffic-capacity claim. No visitor data is sent to an external service. Tests cover disabled mode, cache hits/expiry, Redis serialization, partial/full clearing, rollback safety, bulk-update invalidation, live prices/visibility, permissions and failure fallback. Warm layout reads use one revision query instead of the layout's constituent table queries. No production capacity target is claimed without a representative load test.
