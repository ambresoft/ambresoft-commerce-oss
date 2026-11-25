---
title: Variation groups
parent: Catalog
grand_parent: Administration guide
nav_order: 3
---

# Variation groups
{: .no_toc }

**Catalog → Products → Variation Groups** (`/admin/variation-groups`)

A **variation group** links products that are versions of the same item, such as a phone in black, white and blue,
or a shoe in sizes 40–45. Customers pick the version on the product page.

1. TOC
{:toc}

---

## How variations work

- **Every variation is a complete, separate product** with its own SKU, price, stock, images, SEO and URL. Nothing is
  copied or synchronized between them automatically.
- The group records which **defining features** distinguish the products (for example *Colour* and *Storage*). These
  features must use the purpose **Variations as separate products** and be Single choice.
- Each product in the group must have exactly one value for every defining feature, and **no two products may have the
  same combination**.
- All products in a group share the same category and product type.
- A product belongs to at most one group.

## Creating a group

1. Make sure the defining features exist with the purpose **Variations as separate products** (see [Features](../features/)).
2. Click **Create**, choose a complete product and the defining features, and optionally a name (internal only).
3. Add more products to the group (up to 50 at a time), or open the group and use **create variation** for an unused
   combination: it duplicates the product with the chosen values. The copy starts Disabled without SKU; complete it and
   it joins the group when you save it.

## Managing a group

| Action | Effect |
| --- | --- |
| Rename | Changes the internal name only |
| Change features | Validated against every member; you see a preview of the effect and confirm |
| Reorder features | Changes the order of the selectors on the product page |
| Remove a product | The product stays, but becomes standalone. Removing the last product disbands the group |
| Transfer a product | Moves it to another group (validated there) |
| Disband | Removes the group; all products stay as standalone products |

Moving a product to the Trash removes it from its group.

## Show as one product

By default every variation appears separately in lists. **Show as one product** (off by default) shows the group as a
single card:

- separate switches for **Carousels**, **Product lists** and **Search**;
- the card shows the **cheapest purchasable** member;
- optional **storefront names** per language (empty uses the selected product's title);
- **Card action** settings: show selected defining features (for example RAM and storage), optional labels for them,
  colour summaries as **Text** or **Color swatches**, **Clickable color swatches** that open the cheapest version in that
  colour, and replacing quick add with a view-product icon.

Filters still work on every version's values, and each group is counted once per value.

On the product page, choices that only exist in another colour are shown but disabled until that colour is selected.

## Permissions

The screen uses the product permissions: *View products* to see groups and *Update products* to change them.
