---
title: Brands
parent: Catalog
grand_parent: Administration guide
nav_order: 7
---

# Brands

**Catalog → Brands** (`/admin/brands`)

Brands identify who makes a product (for example *Apple* or *Nike*). Each active brand has its own page on the
storefront listing all its products, and brands can be shown in storefront blocks (logo carousels, best-selling
brands).

## The list

Search brands by name or code, filter by status, sort and switch a brand on or off directly in the list. The list shows
how many products each brand has.

## Creating a brand

Click **New brand** and fill in:

| Tab / field | Notes |
| --- | --- |
| **Name** | Required in every active language |
| **Description** | Rich text per language, shown on the brand page |
| **Code** | A unique, stable identifier (lowercase letters, numbers, hyphens or underscores). It is also the brand page's address, for example `/apple` |
| **Position** | Order in brand lists and carousels |
| **Image** | The brand logo with alternative text per language |
| **Landing page banner** | A wide image above the brand's filters and products, with an optional **Banner link** (a store path such as `/offers` or a full address) and **Banner alternative text** |
| **Attachments** | Size guides, manuals and brand documents, shown on every product of this brand |
| **Status** | Active or Disabled |

Changing the code changes the brand page's address.

## Assigning products

A product has at most one brand, chosen in the product editor's **General** tab. Every variation is a product and has
its own brand.

## The brand page

The brand page lists every visible product of the brand from all categories, with the same filters and sorting as a
category page (without the brand filter), under a heading with the brand's logo, name, description and banner. Its
layout is the **Catalog landing** layout in **Website → Layouts**.

{: .note }
> **Settings → General settings → Catalog → Brand landing pages** chooses where brand pages come from: dedicated brands
> (the default) or the values of a feature with the purpose *Brand / Author Landing Pages*. Changing it needs an extra
> permission because it changes page addresses.

## Deleting

A brand assigned to products cannot be deleted: remove the assignments first, or disable the brand.

## Importing

Create or update brands from a CSV file: **Imports / Exports → Import brands** (see
[Imports / Exports](../../system/imports-exports/)).

## Permissions

*View brands*, *Manage brands*, *Import brands by CSV*; *Change brand landing-page source* for the setting above.
