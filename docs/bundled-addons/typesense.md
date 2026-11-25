---
title: Typesense search
parent: Bundled add-ons
nav_order: 2
---

# Typesense search

Powers the storefront's search box, instant suggestions and search results page with
[Typesense](https://typesense.org), a dedicated search engine: typo-tolerant, multilingual (including Greek accents and
final sigma) and fast as the catalog grows. Without a search add-on, the storefront has no search box.

## Setting it up

1. The Typesense server must be running and configured in the server's `.env` (`TYPESENSE_HOST`,
   `TYPESENSE_API_KEY`…). On a local Docker installation, `docker compose up -d typesense` is enough.
2. **Add-ons**: install and enable **Typesense search**.
3. Restart the background services: `docker compose restart worker scheduler`.
4. **Settings → Search**: click **Build index**.

The demo store (`cms:demo-store`) does all of this for you.

## Using it

- Daily use and settings (typo tolerance, minimum characters, synonyms): see [Settings → Search](../../admin/settings/search/).
- What shoppers search for: **Reports → Popular searches**.
- Technical details (indexing, security, production): see [Search with Typesense](../../developers/typesense/).

The search index is a copy of the catalog: it can always be rebuilt, and losing it loses no store data.
