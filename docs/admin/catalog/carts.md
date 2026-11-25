---
title: Carts
parent: Catalog
grand_parent: Administration guide
nav_order: 9
---

# Carts

**Catalog → Carts** (`/admin/carts`)

See what visitors and customers have in their shopping carts right now. A cart appears as soon as a visitor adds the
first product.

## The list

- Each row shows the cart number, its **owner** (a customer, or *Guest*), the number of products and units, when it
  was created and its last change.
- **Search** by cart ID or customer name (and email, if you may view customers).
- **Filter** by owner type and by content (*With products* / *Empty*).
- **Sort** by last change, creation date or units.

## A cart's details

Open a cart to see its products with quantity, price and total, as the storefront shows them to that owner **right
now**. A cart stores only products and quantities; it is not a quote, and prices follow the catalog.

Products that the owner can no longer see (disabled, restricted or incomplete) stay in the cart but are listed
separately under **Not visible to this owner**; the storefront does not show them.

To email customers who left products in their cart, use the **Abandoned cart reminders** add-on (see
[Bundled add-ons](../../../bundled-addons/cart-reminders/)).

## Permission

*View carts* (`carts.view`).
