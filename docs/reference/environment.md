---
title: Environment variables
parent: Reference
nav_order: 4
---

# Environment variables

Settings that belong to the server, not to the store, live in the `.env` file at the project root. Start from
`.env.example`. Everything a merchant configures (store name, logos, languages, taxes, payment credentials, AI keys…)
is set in the administration and stored in the database instead.

{: .warning }
> `.env` contains secrets. Never commit it, and never reuse the local development passwords from `.env.example` in
> production.

## Application

| Variable | Example / default | Meaning |
| --- | --- | --- |
| `APP_NAME` | `"Ambresoft Commerce"` | Application name (used by Laravel, for example in the email sender name) |
| `APP_ENV` | `local` | `local`, `testing` or `production`. Demo commands refuse to run outside `local`/`testing` |
| `APP_KEY` | generated | Encryption key for sessions, signed URLs and stored secrets. Generate once; **never** replace it on an existing store |
| `APP_DEBUG` | `true` | Detailed error pages. **Must be `false` in production** |
| `APP_URL` | `http://localhost:8000` | The exact address of the store as typed in the browser (HTTPS in production). Used in emails, signed links and passkeys |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | `en` | Laravel defaults; the store's languages are managed in **Settings → Languages** |
| `APP_PORT` | `8000` | Local port for the web application (Docker Compose) |
| `LOG_CHANNEL`, `LOG_LEVEL` | `stderr`, `debug` | Where logs go and how detailed they are |

## Database

| Variable | Default | Meaning |
| --- | --- | --- |
| `DB_CONNECTION` | `pgsql` | PostgreSQL |
| `DB_HOST`, `DB_PORT` | `postgres`, `5432` | Database server |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `cms`, `cms`, `local-only-cms` | The store database and its user (local values) |

## Sessions, cache and queues (Redis)

| Variable | Default | Meaning |
| --- | --- | --- |
| `SESSION_DRIVER` | `redis` | Sessions stored in Redis |
| `SESSION_LIFETIME` | `120` | Minutes of inactivity before a session expires |
| `SESSION_ENCRYPT` | `true` | Session data is encrypted |
| `SESSION_SECURE_COOKIE` | `false` | Set `true` in production (HTTPS only) |
| `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE` | `true`, `lax` | Cookie protection |
| `SESSION_COOKIE` | `cms_session` | Session cookie name |
| `CACHE_STORE`, `CACHE_PREFIX` | `redis`, `cms_cache_` | Application cache |
| `QUEUE_CONNECTION` | `redis` | Background jobs |
| `REDIS_CLIENT` | `phpredis` | Redis client |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | `redis`, `6379`, `null` | Redis server |
| `REDIS_DB`, `REDIS_CACHE_DB` | `0`, `1` | Redis databases for sessions/queues and for the cache |
| `REDIS_PREFIX` | `cms_` | Key prefix; use a unique prefix per installation sharing a Redis server |
| `REDIS_QUEUE_RETRY_AFTER` | `90` | Seconds before an unfinished job is retried; keep it above the worker timeout (60) |
| `STOREFRONT_CACHE_STORE` | `storefront` | Cache store used by the storefront content cache |
| `STOREFRONT_REDIS_URL` or `STOREFRONT_REDIS_HOST`, `_PORT`, `_USERNAME`, `_PASSWORD`, `_DB` | — | Optional dedicated Redis for the storefront content cache |

## Email

| Variable | Default | Meaning |
| --- | --- | --- |
| `MAIL_MAILER` | `smtp` | Mail transport |
| `MAIL_HOST`, `MAIL_PORT` | `mailpit`, `1025` | SMTP server (locally: Mailpit) |
| `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME` | — | SMTP credentials and scheme for a real mail server |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `hello@cms.test`, `${APP_NAME}` | Default sender |
| `MAIL_LOCAL_INBOX_URL` | `http://localhost:8025` | Local only: link to the Mailpit inbox shown next to test emails in the administration |
| `MAILPIT_PORT` | `8025` | Local port of the Mailpit inbox |
| `MAILPIT_RELAY_HOST`, `_PORT`, `_STARTTLS`, `_AUTH`, `_USERNAME`, `_PASSWORD`, `_MATCHING`, `_OVERRIDE_FROM` | — | Optional local relay of selected recipients (matching `MAILPIT_RELAY_MATCHING`) to a real SMTP server |

The store's sender name and address and the delivery method can also be set in **Settings → General settings**.

## Search (Typesense add-on)

| Variable | Default | Meaning |
| --- | --- | --- |
| `TYPESENSE_NODES` | — | Comma-separated cluster URLs; overrides the single-node settings |
| `TYPESENSE_PROTOCOL`, `TYPESENSE_HOST`, `TYPESENSE_PORT`, `TYPESENSE_PATH` | `http`, `typesense`, `8108`, empty | A single node |
| `TYPESENSE_API_KEY` | `local-only-typesense` | Admin API key. Use a long random key outside local development |
| `TYPESENSE_COLLECTION` | `products` | Alias used for searches |
| `TYPESENSE_TIMEOUT`, `TYPESENSE_CONNECT_TIMEOUT`, `TYPESENSE_IMPORT_TIMEOUT` | `3`, `2`, `60` | Seconds |
| `TYPESENSE_RETRIES`, `TYPESENSE_RETRY_MS` | `2`, `200` | Retries after connection errors, 429 or 5xx |

## Add-ons

| Variable | Default | Meaning |
| --- | --- | --- |
| `ADDON_UPLOADS` | `true` | `false` turns off uploading add-on ZIP files in the administration |
| `ADDON_UPLOAD_MAX_KB` | `20480` | Maximum ZIP size (20 MB) |
| `ADDONS_PATH` | `storage/addons` | Where uploaded add-ons are extracted. Keep it persistent and outside the web root |

## Live admin notifications

| Variable | Default | Meaning |
| --- | --- | --- |
| `ADMIN_SSE_MAX_CONNECTIONS` | `4` | Open notification streams allowed at once; keep well below the PHP thread count |
| `ADMIN_SSE_LIFETIME_SECONDS` | `25` | Lifetime of one stream before reconnecting (maximum 60) |
| `ADMIN_SSE_CHECK_SECONDS` | `3` | How often a stream checks for news |
| `ADMIN_SSE_HEARTBEAT_SECONDS` | `10` | Keep-alive interval |
| `ADMIN_SSE_RETRY_MS` | `10000` | Reconnect hint for the browser |

## Audit log and security

| Variable | Default | Meaning |
| --- | --- | --- |
| `AUDIT_ACTOR_EMAIL` | `true` | Store the acting staff member's email with each audit entry |
| `AUDIT_IP_ADDRESS` | `true` | Store the IP address with each audit entry |
| `AUDIT_USER_AGENT` | `false` | Store the browser's user agent with each audit entry |
| `AUDIT_RETENTION_DAYS` | not set | Retention period used by `cms:audit-prune`. Not set means audit logs are never pruned |
| `SECURITY_GEOIP_DATABASE` | `storage/app/private/GeoLite2-City.mmdb` | Optional local GeoLite2/GeoIP2 City database used to show approximate locations in account security. No IP address is sent to a third party |

## Local tools

| Variable | Default | Meaning |
| --- | --- | --- |
| `VITE_PORT` | `5173` | Vite development server port (keep 5173 unless you also change `vite.config.ts`) |
| `PGADMIN_EMAIL`, `PGADMIN_PASSWORD`, `PGADMIN_PORT` | `admin@example.com`, empty, `5050` | Optional pgAdmin service |
| `COMPOSE_PROJECT_NAME` | — | Optional Docker Compose project name. Keep it unchanged on an existing installation: volumes are named after it |
