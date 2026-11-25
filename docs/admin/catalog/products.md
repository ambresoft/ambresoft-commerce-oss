---
title: Products
parent: Catalog
grand_parent: Administration guide
nav_order: 1
---

# Products
{: .no_toc }

**Catalog → Products → Products** (`/admin/products`)

1. TOC
{:toc}

---

## The product list

- **Search** by SKU, EAN or any translated name.
- **Filter** by type, status, category, completeness, variation group (*Grouped* / *Standalone*).
- **Sort** by the columns, including stock. A list price higher than the selling price is shown struck through next to
  the price; a stock of 0 is marked out of stock.
- Show 25, 50 or 100 products per page.
- **Bulk actions**: tick products on the current page (up to 100) and **Activate**, **Disable** or **Hide** them. Each
  product is checked individually; the result tells you which ones changed and why others did not.
- **Trash**: deleted products go to the Trash first. From there they can be restored or permanently deleted.

## Product statuses

| Status | Meaning |
| --- | --- |
| **Active** | For sale on the storefront (when it is complete and its category is visible) |
| **Disabled** | Not on the storefront. New products start Disabled |
| **Hidden** | Not shown on the storefront either |

A product is **complete** when it has a name in the default language, a SKU, a price (0 is allowed) and an active
leaf category. You can save incomplete drafts, and Disabled or Hidden products may stay incomplete, but only complete
products can be **Active**. Activating also requires the default-language URL slug, values for required features and,
for digital products, at least one downloadable file.

## Creating a product

1. Click **Create** (top right). An empty **Disabled** draft is created immediately, so nothing is lost if you leave.
2. Fill in at least the name, SKU, price and category (see the tabs below).
3. Save, then set the status pill to **Active**.

**Duplicate** (in the editor) creates a Disabled copy with the same translations (new URL slugs), price, measurements,
category and images, but without SKU, EAN, UPC, stock or downloadable files.

## The editor tabs

The editor groups fields into tabs. **Sale** and **Price history** are in the overflow menu (…) of the tab bar.

### General

| Field | Notes |
| --- | --- |
| **Name** | Per language; required in the default language |
| **Short description**, **Description** | Per language, rich text (Visual and HTML modes). Unsafe HTML is removed when you save |
| **SKU** | Your own unique product code (unique without regard to upper/lower case, also across the Trash) |
| **EAN** | Optional EAN-8 or EAN-13 barcode, check digit verified |
| **MPN** | Optional manufacturer part number |
| **ISBN**, **UPC** | Optional; ISBN-10/13 and 12-digit UPC-A, check digits verified |
| **Type** | **Physical** or **Digital**. Locked while the product is in a variation group |
| **Brand** | Optional; see [Brands](../brands/) |
| **Returnable** | A setting stored for your own information |

### Pricing & inventory

| Field | Notes |
| --- | --- |
| **Price** | The selling price in the primary currency, at its decimals (see **Settings → Currencies**) |
| **List price** | Optional "was" price, shown struck through when higher than the price |
| **Taxes** | The taxes that apply to this product (see [Taxes](../../shipping-taxes/taxes/)) |
| **Stock quantity** | Units in stock |
| **Minimum / maximum order quantity**, **Quantity step** | Optional ordering limits; for example a step of 6 sells in packs of six |
| **When out of stock** | *Use the general setting*, *Do not allow purchase* or *Allow backorders (buy in advance)* |
| **Availability labels** | The in-stock and out-of-stock labels customers see; empty uses the store defaults (see [Availability statuses](../availability-statuses/)) |
| **Available from** | Release date: before it, the product shows as upcoming and cannot be bought |
| **Customer groups** | Leave empty to show the product to everyone, or choose the groups allowed to see it |

Reaching zero stock never changes the product's status.

### Features

Values for the [features](../features/) that apply to the product's category: text, numbers, yes/no, single or
multiple choices. Required features must be filled before the product can be activated.

### Variations

Shows the product's [variation group](../variation-groups/) and lets you create a new variation (a copy with different
values for the defining features).

### Shipping

**Free shipping**, a **product shipping cost** added to the order's shipping, and **box packaging** (minimum and
maximum items per box and box dimensions). The product's weight uses the weight unit chosen in **Settings → General
settings**.

### Categories

| Field | Notes |
| --- | --- |
| **Main category** | An active leaf category (not a landing page). It sets the product's address and breadcrumb |
| **Additional categories** | The product is also listed in these categories, including parent categories. Coupons, promotions, stickers and payment restrictions for a category apply to it too |

### Images

Upload JPEG, PNG or WebP images, drag to reorder and choose the **primary** image. Images belong to this product only.

### SEO

Per language: **SEO name (URL slug)**, page title, meta description and meta keywords. A blank slug is generated from
the name. Changing an existing slug is an explicit action, because it changes the product's address.

### Attachments

- **Attachments**: documents shown on the product page (size charts, manuals, samples). PDF, ZIP, TXT and images.
- **Downloads for buyers** (digital products only): files delivered to customers who buy the product. Never shown
  publicly.

Files are stored privately; at most 10 MB per file by default.

### Additional

**Allow reviews and ratings** for this product, **Exclude from XML sitemap** (Sitemap add-on) and the Facebook product
type for feeds.

### Similar products

Similar products are chosen **automatically** from the same category, ranked by shared features and type, and stay up to
date as the catalog changes (at most 12). Switch it off for this product if you do not want the section.

### Frequently bought together

Choose up to **three** **Individual products** to show with this one as a set, with a live total of the items that can
be bought now. The same tab lets you assign a reusable [product package](../product-packages/) (**Choose a package**);
a package assignment is applied immediately, while individual products are saved with the product.

### FAQs and Stickers

Link reusable [FAQs](../../website/faqs/) to the product and assign [stickers](../../marketing/stickers/) (badges such as
*New* or *-20%*).

### Sale

Scheduled sale prices: each rule has an optional customer group, minimum quantity, priority, a **fixed sale price** or a
**percentage off**, an optional **sale stock** (how many units may be sold at the sale price) and optional start and
end dates. When several rules qualify, the highest qualifying minimum quantity wins, then the lowest priority, then the
lowest price. Rules never stack and never raise the price.

### Price history

A chart and table of every price and list-price change (1, 3, 6 months or all).

## Deleting products

1. In the editor's three-dot menu, choose **Trash** and confirm. The Trash keeps the product's data, images and reserved SKU and slugs.
2. In the **Trash** you can **Restore** a product (it returns Disabled) or delete it **permanently**. Permanent deletion
   asks you to type the product's exact name.

## Permissions

| Permission | Allows |
| --- | --- |
| *View products* | See the list and editors |
| *Create products* | Create drafts and duplicates |
| *Update products* | Save changes, images and files |
| *Change product status / bulk status* | Activate, disable, hide |
| *Delete products* | Move to Trash |
| *View product Trash*, *Restore products*, *Permanently delete products* | Work with the Trash |
| *Import products by CSV* | [Import products](../../system/imports-exports/) |
