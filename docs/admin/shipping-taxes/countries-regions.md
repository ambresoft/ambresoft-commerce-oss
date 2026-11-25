---
title: Countries and regions
parent: Shipping & Taxes
grand_parent: Administration guide
nav_order: 3
---

# Countries and regions

**Shipping & Taxes → Countries** (`/admin/countries`) and **Shipping & Taxes → Regions** (`/admin/regions`)

## Countries

Tick the countries your store **ships to**. Country names are shown in your administration language. Once at least one
country is ticked, the delivery country at checkout must be one of them, and address forms offer their active regions.

## Regions

Regions are the subdivisions customers pick in an address (for example the Greek *nomoi*: Attica, Thessaloniki…).

| Field | Notes |
| --- | --- |
| **Country** | A region can only be created for a country you ship to |
| **Code** | 1–10 letters or digits, unique per country (for example `ATT`). Rate areas match regions by this code |
| **Name** | Per language; required in the default language |
| **Status** | Active or Disabled (enable or disable several at once from the list) |

Regions of a country you stop shipping to are kept and come back when you enable the country again.

### Greece

The server operator can enable Greece and add all **51 nomoi** with Greek and English names in one step (safe to
repeat; edited regions are never overwritten):

```sh
docker compose exec -T app php artisan cms:geography-greece <staff-user-id>
```

### Importing and exporting regions

Use **Import regions** on the Regions screen (or **Imports / Exports**) to create or update regions from a CSV file. See
[Imports / Exports](../../system/imports-exports/).

## Permissions

*View shipping countries*, *Manage shipping countries*, *View regions (nomoi)*, *Manage regions (nomoi)*. Importing
regions needs *Manage regions*.
