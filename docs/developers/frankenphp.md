---
title: FrankenPHP
parent: Developer guide
nav_order: 4
---

# FrankenPHP application server

The application is served by [FrankenPHP](https://frankenphp.dev): a PHP application server built on the
[Caddy](https://caddyserver.com) web server, with PHP embedded directly in it. There is no separate PHP-FPM process and
no extra hop between the web server and PHP, and static files are served by Caddy itself.

## The image

`docker/php/Dockerfile`:

- based on `dunglas/frankenphp:1.14.0-php8.5-trixie` (FrankenPHP 1.14.0, PHP 8.5, Debian 13 "trixie");
- applies Debian security updates at build time;
- adds the PHP extensions `pdo_pgsql`, `intl`, `zip`, `bcmath`, `pcntl` and `redis`;
- copies Composer 2.8 and the Caddyfile;
- has a health check that calls Laravel's `/up` endpoint.

The same image runs three services in `compose.yaml`: `app` (the web server), `worker` (`php artisan queue:work …`)
and `scheduler` (`php artisan schedule:work`). The worker and scheduler disable the web health check.

{: .note }
> PHP 8.6 is not used yet: the project waits for a phpredis release that supports it. CI also checks that the code and
> locked dependencies still install on PHP 8.3 and 8.4.

## The Caddyfile

`docker/php/Caddyfile`:

```text
{
	admin off
	auto_https off
	frankenphp {
		num_threads 12
	}
}

http://:8000 {
	root * /var/www/html/public
	php_server {
		try_files {path} index.php
	}
}
```

| Setting | Meaning |
| --- | --- |
| `admin off` | The Caddy administration API is disabled |
| `auto_https off` | No automatic certificates: this setup serves plain HTTP on loopback for local development |
| `num_threads 12` | Twelve PHP threads answer requests in parallel |
| `root … public` | Only the `public/` folder is served; application code is never reachable by URL |
| `try_files {path} index.php` | Existing static files are served directly, everything else goes to Laravel |

The Caddyfile is **copied into the image**: rebuild after editing it
(`docker compose build app worker scheduler && docker compose up -d --no-deps app worker scheduler`).

## Classic mode

FrankenPHP runs in **classic mode**: Laravel boots for every request, exactly as under PHP-FPM. This keeps the
request-local lifecycle the application relies on (per-request services, locale and authorization state).
**Laravel Octane and persistent worker mode are not enabled.** Do not keep request state in static properties or
singletons expecting it to survive between requests.

## PHP settings

Mounted into the containers:

| File | Settings |
| --- | --- |
| `docker/php/uploads.ini` | `upload_max_filesize = 64M`, `post_max_size = 72M`, `max_execution_time = 180` |
| `docker/php/mail.ini` | `sendmail_path` points at a small shim that hands PHP `mail()` messages to Mailpit (local only) |

## Live notifications and threads

The administration's live notifications use a server-sent events (SSE) stream (`/admin/notifications/stream`), which
keeps a PHP thread and a database connection busy while it is open. The `.env` settings bound that:

| Variable | Default | Meaning |
| --- | --- | --- |
| `ADMIN_SSE_MAX_CONNECTIONS` | `4` | Streams allowed at once across all processes; extra tabs are told to retry later |
| `ADMIN_SSE_LIFETIME_SECONDS` | `25` | How long one stream stays open before the browser reconnects (maximum 60) |
| `ADMIN_SSE_CHECK_SECONDS` | `3` | How often the stream checks for new notifications |
| `ADMIN_SSE_HEARTBEAT_SECONDS` | `10` | Keep-alive interval |
| `ADMIN_SSE_RETRY_MS` | `10000` | Reconnect hint sent to the browser |

Keep `ADMIN_SSE_MAX_CONNECTIONS` **well below the PHP thread pool size** (12) so ordinary requests always have threads
left. Behind a proxy, allow read timeouts longer than the stream lifetime and disable response buffering for the stream
route. The stream uses PostgreSQL session advisory locks, so a transaction-pooled PgBouncer is not supported for it.

## Production

The Compose setup binds to `127.0.0.1` without HTTPS: it is a development environment. In production you need HTTPS
(Caddy can obtain certificates when `auto_https` is enabled and a real domain is configured), `APP_DEBUG=false`, secure
cookies, a process manager for workers and a hardened host. See [Going to production](../../getting-started/production/).
