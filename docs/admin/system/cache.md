---
title: Cache
parent: System
grand_parent: Administration guide
nav_order: 2
---

# Cache

**Cache** (`/admin/cache`)

The storefront content cache keeps ready-made storefront content in **Redis** memory so pages load faster and the
database works less. It is **off** by default.

## What it caches, and what it never caches

| Cached | Never cached (always live) |
| --- | --- |
| Layouts, grids and blocks, menus for guests, banner designs | Prices, taxes, stock and availability |
| Product names, descriptions, galleries and feature values | Who may see what (customer groups, status, schedules) |
| Category names and addresses | Carts, checkout, accounts, wishlists, comparison |
| Brand content | Whole pages, cookies, security tokens |
| Image information | |

## Switching it on

1. Open **Cache**. The screen checks that Redis can be reached.
2. If the connection is healthy, switch the cache **on** and save.

## Keeping it fresh

You never need to clear the cache after normal edits: every change to products, categories, brands, layouts, menus,
banners, media, languages and settings refreshes the affected part **automatically and immediately**, including CSV
imports.

Use the **clear** actions only when told to (for example after restoring a database backup or a deployment): clear one
group (layouts and blocks, products, categories, brands, media) or everything. Clearing never touches sessions, carts or
background jobs.

If Redis has a problem, the storefront keeps working by reading from the database.

## Permission

*Manage storefront cache* (`cache.manage`).
