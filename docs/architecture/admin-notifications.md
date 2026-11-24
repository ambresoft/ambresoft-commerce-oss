# Persistent admin notifications

New storefront orders now produce in-app notifications, separate from `order_notifications` (the existing customer order-email delivery ledger). The existing Laravel Notifiable trait serves account email notifications; no database-notification table existed. This implementation uses two PostgreSQL tables in the Notification infrastructure context rather than a second transport or generic event bus.

## Placement, recipients and durability

`OrderAdministration::create` records `order.placed` only for `source=checkout`, inside its existing order transaction, after the initial status transition. Checkout includes payment-pending orders: subsequent payment redirects/webhooks do not create another notification. The outer checkout transaction also covers gift-card redemption and cart updates. A failure or rollback removes the order, notification and inbox revision together. Persistence is atomic; **delivery only observes committed data** on a separate HTTP connection. There is no after-commit callback crash window, queue job, Redis publisher or email behavior change.

Recipients are active, verified staff who pass the existing `access-admin`, `orders.view` and `orders.update` gates, including the existing super-admin rules and staff-password requirement. Customer accounts and view-only/unrelated staff receive nothing. The app currently has one shop and no tenant/store authorization scope. If scopes are introduced, extend both recipient selection and inbox queries before enabling multi-store notifications. No recipients is a normal successful checkout.

`admin_notifications` stores recipient, type, order ID/number, creation/read/deletion timestamps. `(user_id,type,resource_id)` is unique, including deleted records, so retries cannot resurrect a deleted notification. Soft deletion affects only that recipient. Order identity is deliberately historical, without a cascading order foreign key. List responses resolve accessible order links in one bounded query; missing orders have no link. Translated titles/messages derive from the stored event type and order number, using the current admin UI language. No customer personal information is copied.

`admin_notification_inboxes` is a recipient revision row, not another notification store. Writers lock it and increment its revision in the same transaction as creation/read/delete. This prevents sequence-allocation/commit-order and equal-timestamp cursor races. The revision covers changes from other tabs too. List responses lock the same row briefly to return a consistent revision, page and authoritative unread count. Pages use descending numeric ID keysets (20 entries); unread counts cover the full undeleted inbox. Notifications are not backfilled for historical orders or staff granted permission later.

## HTTP and SSE

All routes are in the existing authenticated, session-authenticated, verified admin group, and independently enforce notification eligibility. POST actions retain normal Laravel CSRF protection. IDs and pagination cursors are bounded decimal strings. Other recipients' IDs return 404. Repeating read/read-all is harmless.

- `GET /admin/notifications?before=ID`: items, unread count, revision and next page cursor.
- `POST /admin/notifications/{id}` with `action=read|delete`.
- `POST /admin/notifications/read-all` with `action=read`.
- `GET /admin/notifications/stream`: same-origin native EventSource, no URL tokens.

The stream sends `retry`, stable recipient revision `id`, named `notifications.sync`, `notification.created`, `notifications.changed`, and comment heartbeats. Data contains **only the revision**. Each connection first reconciles even a current/future/old Last-Event-ID via a sync event; the browser retrieves the current durable state rather than replaying every past mutation. Changes during disconnection cannot be lost. The UI never increments counts based on events; repeated events and stale HTTP responses cannot double-count notifications. Selecting a notification marks it read before navigating. Native EventSource reconnects; there is no browser interval-polling fallback. Refresh, focus and opening the panel also reconcile. One stream belongs to the shared header, and is closed on unmount, logout or authorization failure.

Every database check re-reads staff eligibility, password and session existence; revoked access emits `stream.revoked` and stops. Expired/deleted sessions are not extended inside a stream. A subsequent rejected reconnect is reconciled through a JSON request and closes the client. Laravel's normal session middleware saves **before** the streamed body; no blocking session route is used. Existing session storage remains the application's authentication infrastructure; notification persistence/delivery itself uses only PostgreSQL.

## Hosting and limits

A blocking PHP stream occupies a web worker and database connection. The local Compose app now uses 12 PHP CLI server workers and `artisan serve --no-reload`; the SSE pool permits at most 4 simultaneous admitted streams across processes using PostgreSQL session advisory locks. Locks are released on exit or database disconnect. Extra streams receive a retry hint and `stream.busy`, close immediately, and retry natively. These are real long-lived SSE responses, not polling responses.

Defaults in `config/admin_notifications.php`:

| Environment variable | Default |
| --- | ---: |
| `ADMIN_SSE_CHECK_SECONDS` | 3 |
| `ADMIN_SSE_HEARTBEAT_SECONDS` | 10 |
| `ADMIN_SSE_LIFETIME_SECONDS` | 25 |
| `ADMIN_SSE_RETRY_MS` | 10000 |
| `ADMIN_SSE_MAX_CONNECTIONS` | 4 |

Checks have a one-second minimum, retry a three-second minimum, heartbeat a three-second minimum, and lifetime a 60-second maximum. Heartbeats require lifetime greater than heartbeat. Detection latency while connected is normally up to three seconds, with the native retry gap at connection rotation. No notification is lost during rotation or server restart. The maximum is a small-installation safety limit, **not a capacity guarantee for unlimited staff/tabs**. Increase worker/database capacity before increasing slots. Excess tabs receive eventual reconciliation while waiting for a slot.

Production: use a multi-worker PHP-FPM/web server with `pm.max_children` comfortably above `ADMIN_SSE_MAX_CONNECTIONS` (reserve at least several workers for ordinary requests), HTTP/2 where possible, proxy read timeouts beyond the configured lifetime, and disabled compression/buffering for the stream route. The response sets `X-Accel-Buffering: no` and `Cache-Control: no-cache, no-store, no-transform`; configure any upstream proxy that ignores these headers. Session-level advisory locks require direct/session-pooled PostgreSQL connections; transaction-pooled PgBouncer is not supported for this endpoint. Browser HTTP/1 per-origin connection limits apply across tabs. The existing local Docker server is not a production deployment.

Deploy with `php artisan migrate`, `pnpm build` and restart/recreate the app so worker settings take effect. No queue worker, broker, new service, credentials or permission grants are required. Other existing CMS features may still use Redis; this notification subsystem does not.
