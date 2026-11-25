---
title: Going to production
parent: Getting started
nav_order: 6
---

# Going to production

The Docker Compose setup in the repository is a **local development environment**: it binds to `127.0.0.1`, serves
plain HTTP, uses disposable development passwords and mounts the source code into the containers. A public store needs a
production deployment prepared by someone comfortable running Linux servers. This page lists what that deployment must
provide; it is a checklist, not a step-by-step hosting recipe.

{: .warning }
> Never expose the development setup to the internet as it is, and never reuse the passwords from `.env.example`.

## Application settings

- [ ] `APP_ENV=production` and **`APP_DEBUG=false`**.
- [ ] A unique `APP_KEY`, generated once and kept safe. Losing or changing it makes encrypted secrets (payment keys, SMTP
  password, AI key) and sessions unreadable.
- [ ] `APP_URL` set to the exact public **HTTPS** address. Emails, signed links, passkeys and Google sign-in depend on it.
- [ ] `SESSION_SECURE_COOKIE=true` (cookies only over HTTPS).
- [ ] Unique, strong passwords for PostgreSQL, Redis and Typesense (`TYPESENSE_API_KEY`).
- [ ] Secrets kept out of version control.

## Services

- [ ] **HTTPS** in front of the application.
- [ ] **PostgreSQL** and **Redis** on a private network, not reachable from the internet.
- [ ] **Queue workers** (`php artisan queue:work redis --queue=default,maintenance …`) under a process manager that
  restarts them automatically (systemd, Supervisor or a container restart policy).
- [ ] The **scheduler** running continuously (`php artisan schedule:work`, or `schedule:run` every minute from cron).
- [ ] **Typesense** (if you use search) on a private network with persistent storage; monitor its memory.
- [ ] **Email delivery** configured: SMTP or a sendmail-compatible program, in **Settings → General settings → Emails**
  and/or `MAIL_*` in `.env`. Mailpit is for development only.
- [ ] Redis: never configure an eviction policy on the instance that holds sessions and queues; consider a separate
  instance for the storefront cache.
- [ ] Behind a proxy: allow long enough read timeouts and disable buffering for the admin notifications stream, and
  review Laravel's trusted-proxy configuration so client IP addresses are correct.

## Deploying a version

```sh
composer install --no-interaction --prefer-dist
php artisan migrate --force
php artisan cms:permissions-sync
php artisan cms:addons-sync
pnpm install --frozen-lockfile && pnpm build
# then restart the queue workers and the scheduler
```

If you use Laravel's configuration or route caches, rebuild them after every deployment **and** after enabling or
disabling an add-on: add-ons register their routes only while enabled.

Afterwards, clear the storefront cache if it is enabled and check **Settings → Search**. See [Upgrading](../upgrading/).

## Before opening

- [ ] **Settings → General settings**: open the storefront and switch **Discourage search engine indexing** off.
- [ ] Do not turn a demo installation into your live store: there is no command that removes demo data. Start the
  real store from an empty database with `cms:admin-setup`, and never create the local demo administrator there.
- [ ] Switch payment providers (Stripe) to **live** keys and live webhooks.
- [ ] Update the Google sign-in redirect URI to the live address.
- [ ] Place a real test order with each payment method.

## Backups

- [ ] Back up the PostgreSQL database regularly and test restoring it.
- [ ] Back up `storage/` (uploaded images and files, uploaded add-ons in `storage/addons`).
- [ ] Keep `.env` (with `APP_KEY`) in a safe place.

The search index does not need a backup: it can always be rebuilt from the database.

## Security

- Install only add-ons you trust; consider `ADDON_UPLOADS=false` so add-on code arrives only through deployment.
- Give staff only the permissions they need; Super Admin for a few people only.
- Keep the server, PHP image and dependencies updated; the project's CI scans dependencies and the container image.
- Report vulnerabilities privately as described in the
  [security policy](https://github.com/ambresoft/ambresoft-commerce-oss/blob/master/SECURITY.md).
