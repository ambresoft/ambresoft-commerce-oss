---
title: Artisan commands
parent: Reference
nav_order: 3
---

# Artisan commands

Run every command inside the `app` container:

```sh
docker compose exec -T app php artisan <command>
```

## Installation, upgrades and access

| Command | What it does |
| --- | --- |
| `cms:permissions-sync [--grant-commerce-defaults]` | Registers enabled capabilities and creates missing reference roles. Existing grants are preserved. `--grant-commerce-defaults` adds the reference commerce grants to the existing reference roles without removing anything |
| `cms:addons-sync` | Installs new bundled add-ons (disabled) and applies upgrades of bundled add-ons shipped with this version. Enabled states never change |
| `cms:addon-validate <path>` | Checks an add-on folder or ZIP the way the administration installer does, without installing or running it |
| `cms:admin-setup <email> [--name=]` | Emails a private, one-time setup link for the first Super Admin |
| `cms:admin-grant <email>` | Operator recovery: grants the Super Admin role to an existing, verified, active user. Never creates accounts or changes passwords |
| `cms:geography-greece <actor>` | Enables Greece for shipping and adds its 51 regions (nomoi) with Greek and English names; safe to repeat |

## Search

| Command | What it does |
| --- | --- |
| `cms:search-sync` | Indexes queued catalog changes now |
| `cms:search-reindex [--now]` | Rebuilds the product search index without interrupting search (`--now` builds in this process instead of the queue) |
| `cms:search-reconcile` | Compares the index with the catalog and queues missing or stale products |
| `cms:search-cleanup` | Deletes search collections that are neither live nor the previous one |

## Maintenance

| Command | What it does | Scheduled |
| --- | --- | --- |
| `cms:product-files-cleanup` | Dispatches committed private-file cleanup requests to the queue | every minute |
| `cms:newsletters-dispatch` | Queues waiting, retryable and interrupted newsletter deliveries and closes finished newsletters | every minute |
| `cms:category-import-prune` | Removes expired private category CSV uploads | hourly |
| `cms:product-import-prune` | Removes expired private product CSV uploads | hourly |
| `cms:brand-import-prune` | Removes expired private brand CSV uploads | hourly |
| `cms:region-import-prune` | Removes expired private region CSV uploads | hourly |
| `cms:audit-prune` | Prunes audit logs, only when a retention period is explicitly configured | never by default |

## Email testing

| Command | What it does |
| --- | --- |
| `cms:order-emails-test <recipient> [--from=] [--locale=]` | Sends each order status email and the invoice email with sample data to one address |

## Demo data (local and testing environments)

`<actor>` is the numeric ID of an existing staff user allowed to create that data.

| Command | What it does |
| --- | --- |
| `cms:demo-store [--with-admin] [--email=] [--force]` | Creates the demo electronics store: categories, products with photos, menus and a full homepage. `--with-admin` (local only) also creates a demo Super Admin and fills the whole store. `--force` allows a client demo install outside local/testing, never with `--with-admin` |
| `cms:features-demo <actor>` | Ten-product features demo |
| `cms:brands-demo <actor>` | Assigns catalog products to sample brands without creating products or orders |
| `cms:banners-demo <actor>` | Two sample hero banners |
| `cms:blog-demo <actor>` | Two sample blogs with eight posts |
| `cms:faqs-demo <actor>` | Four sample FAQs in the active languages |
| `cms:pages-demo <actor>` | Privacy policy, returns and exchanges, and a contact page with a form |
| `cms:customers-demo <actor>` | Fictitious customers with Greek addresses |
| `cms:orders-demo <actor>` | One fictitious order per order status |
| `cms:newsletters-demo <actor>` | Mailing lists, templates, a campaign, newsletters and subscribers (nothing is sent) |
| `cms:taxes-demo <actor>` | Sample Greek rate areas and taxes (illustrative figures) |

## Useful Laravel commands

| Command | What it does |
| --- | --- |
| `migrate`, `migrate:status` | Apply and inspect database migrations |
| `queue:failed`, `queue:retry <id>` | Inspect and retry failed background jobs (diagnose first) |
| `schedule:list` | Show scheduled tasks |
| `route:list --except-vendor` | Show all routes |
| `optimize:clear` | Clear cached configuration, routes and views |
