---
title: Categories
parent: Catalog
grand_parent: Administration guide
nav_order: 6
---

# Categories
{: .no_toc }

**Catalog → Categories** (`/admin/categories`)

Categories organize your catalog into a tree, for example *Phones → Smartphones*. Each category has its own page on the
storefront, and products live in categories.

1. TOC
{:toc}

---

## The category tree

- Subcategories appear under their parent; use the arrow to open or close a branch.
- **Position** controls the order among siblings. Entering an occupied position shifts the later siblings; gaps are kept.
- Search finds names in your current administration language.
- Products are placed only in **leaf** categories (categories without subcategories). A category that holds products
  cannot get subcategories until its products are moved.
- *Uncategorized* is a protected system category: products whose category is deleted are moved there and disabled.

## Creating a category

Click **Add category** (or **Add subcategory** on a row) and fill in the tabs:

### General

| Field | Notes |
| --- | --- |
| **Parent category** | Leave empty for a root category |
| **Position** | Order among siblings |
| **Status** | Active or Disabled. Disabling a category disables the active products in it |
| **Landing page** | Shows this category's subcategories as cards instead of products. Use it for parents such as *Technology*. A landing-page category cannot hold products |
| **Exclude from search and highlights** | Products in this category (and its subcategories) are hidden from site search and from product carousels such as *Best sellers*. The category and product pages still work |
| **Customer groups** | Leave only *All* ticked to show the category to everyone, or tick the groups allowed to see it. Restrictions apply to subcategories too |

### Content and SEO (per language)

Name, description (rich text), **Page title**, **Meta description**, **SEO name (slug)** and **Meta keywords**.

{: .important }
> When two or more languages are active, the name, page title, meta description, slug, image alternative text and
> meta keywords are **required in every active language**. Nothing is copied between languages automatically. A blank
> slug is generated from the name.

### Image

| Image | Used for |
| --- | --- |
| **Category image** | The main image (with alternative text per language) |
| **Menu icon** | Next to the category in storefront menus (falls back to the category image) |
| **Block image** | On category tiles in storefront category blocks (falls back to the category image) |
| **Banner image** and **Banner link** | Across the category page under its title; the link can be a store path such as `/gaming/consoles` or a full `https://` address |

JPEG, PNG or WebP, up to 5 MB and 6000 × 6000 pixels.

### Age restrictions

Turn on **Age verification** with an **Age limit (years)** and an **Age warning message** per language. Customers must
confirm their age (*I am over …*) before seeing the category. Subcategories inherit the highest limit of their parents and
cannot lower it. Disabling verification on a subcategory does not remove a limit inherited from a parent.

### Additional

**Exclude from XML sitemap** (Sitemap add-on): applies to the category, all subcategories and their products.

## Moving and deleting

- To move a category, edit it and choose another **Parent category**. A category cannot move under its own subcategories.
- A parent cannot be deleted until its subcategories are moved or deleted.
- Tick visible leaf subcategories (up to 40) to **Delete selected** at once.
- Deleting a category moves its products to *Uncategorized* and disables them. A category used by a feature restriction
  or holding products in a variation group cannot be deleted until that is resolved.

## Importing

Create or update many categories at once with a CSV file: **Imports / Exports → Import categories** (see
[Imports / Exports](../../system/imports-exports/)).

## Permissions

*View categories*, *Create categories*, *Update categories*, *Delete categories*, *Import categories from CSV*.
