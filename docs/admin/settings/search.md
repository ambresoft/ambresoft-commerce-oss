---
title: Search
parent: Settings
grand_parent: Administration guide
nav_order: 3
---

# Product search

**Settings → Search** (`/admin/settings/search`)

The storefront's search box, instant suggestions and search results page are powered by a **search add-on**: the bundled
**Typesense search** add-on. This screen shows whether search is healthy and lets you build and repair the search index.

## Turning search on

1. Make sure the server runs the Typesense service (on a local installation: `docker compose up -d typesense`).
2. In **Add-ons**, install and **Enable** *Typesense search*.
3. Ask the operator to restart the background services: `docker compose restart worker scheduler`.
4. Open **Settings → Search** and click **Build index**. The search box appears in the storefront header.

Until the first build completes, shoppers see search as unavailable.

## What the screen shows

- Whether the search engine is reachable, its version and the live index with its number of products.
- How many products can be published, and how many changes are **waiting**, **hidden** or **refused**, with the oldest
  waiting time.
- When the last change was indexed, the last complete build and the last check.
- Recent errors and the progress of rebuilds.

## Actions

| Button | What it does |
| --- | --- |
| **Build index** / **Rebuild index** | Builds a new index from the whole catalog in the background. The current index keeps serving searches and is replaced only when the new one is complete and verified |
| **Index waiting changes now** | Indexes queued catalog changes immediately instead of waiting for the next run (every few seconds) |
| **Retry refused changes** | Queues refused changes again |
| **Check index against catalog** | Finds products missing from the index or still in it after they stopped being publishable. Also runs every night |
| **Repair search** | Retries all waiting or failed updates and rebuilds an outdated index when needed. Large catalogs may need several passes |

You never need to rebuild after normal catalog edits: changes are indexed automatically within seconds. Rebuild after
**adding a language** (the screen says when a rebuild is needed).

{: .note }
> If a warning says products are temporarily excluded from search, use **Repair search**. If it returns, the background
> workers may not be running; contact your hosting support. Your catalog is never at risk.

## Search settings

| Setting | Notes |
| --- | --- |
| **Typo tolerance** | *Normal (up to 2 per word)*, *Low (1 per word)* or *Off (exact words only)*. Codes (SKU, MPN, EAN) always match exactly |
| **Minimum characters** | How many characters a search needs before results or suggestions appear (default 2). Empty or 0: no minimum |
| **Synonyms** | One group per line. `laptop, notebook` makes the words equivalent; `phone => smartphone, mobile` also finds smartphones and mobiles when someone searches for *phone* (up to 200 lines) |

The number of suggestions shown in the header (0–10, default 6) is a setting of the header's identity block in
**Website → Layouts**.

## What shoppers get

- Suggestions while typing, results with filters and sorting (relevance, price, newest, rating).
- Matches in every active language, product codes (SKU, MPN, EAN, ISBN, UPC), brand, category and descriptions.
- Shoppers only ever see products they are allowed to see: visibility, customer groups and categories marked *Exclude
  from search and highlights* are always respected.

Popular search terms are listed in **Reports → Popular searches**.

## Permission

*Manage product search* (`search.manage`).
