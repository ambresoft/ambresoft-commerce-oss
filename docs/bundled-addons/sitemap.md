---
title: Sitemap
parent: Bundled add-ons
nav_order: 3
---

# Sitemap (XML)

Publishes **XML sitemaps** (`/sitemap.xml`) that tell search engines which storefront pages exist and when they changed.
You review what is included before anything is published. (The visible store sitemap at `/sitemap` is separate and
always available.)

## Setting it up

1. **Add-ons**: install and enable **Sitemap**.
2. Grant the permissions to the right roles: *View XML sitemap*, *Manage sitemap settings and drafts*, *Publish XML
   sitemap*.
3. Restart the background services: `docker compose restart worker scheduler`.
4. Open **Website → XML Sitemap** (`/admin/sitemap`).

## Using it

1. **Overview**: switch each section (products, categories, pages and so on) on or off and choose how often it refreshes
   automatically (hour, day, week, month, year or never).
2. **Draft URLs**: the discovered addresses, 50 per page. For each one choose *Automatic*, *Include* or *Exclude*.
3. **Publish**. The first publication is always manual; `/sitemap.xml` exists only after it.
4. Submit `https://your-store/sitemap.xml` in Google Search Console and other search engines.

After that, scheduled refreshes keep each section up to date from the last published selection; they never publish your
unsaved manual draft changes.

## Excluding content

- Products and categories have **Exclude from XML sitemap** in their **Additional** tab. A category exclusion also
  covers its subcategories and their products.
- Only pages that visitors can open without signing in and that are indexable are discovered.

Large stores are split automatically into a sitemap index and parts (at most 50,000 addresses or 50 MB per file).
