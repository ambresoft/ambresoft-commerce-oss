---
title: Promotions
parent: Marketing
grand_parent: Administration guide
nav_order: 2
---

# Promotions
{: .no_toc }

**Marketing → Promotions** (`/admin/promotions`)

Promotions are **automatic** discounts: when an order matches the conditions, the discount or free shipping is applied
without any code. A promotion can also wrap an existing [coupon](../coupons/) to add conditions to it.

1. TOC
{:toc}

---

## Creating a promotion

Click **Create** and fill in:

- **Name** and **description** per language (the name is required in every active language). Customers see the name
  next to their savings in the cart.
- **Application mode**: **Automatic**, or linked to one existing coupon.
- **Status**, optional **start** and **end** date and time (the end is not included), and a **Priority (lower first)**.
- **Conditions** and **benefits** (see below).

The list shows whether each promotion is scheduled, active, expired or disabled, and how often it was used.

## Conditions

Up to six conditions, at most one of each type, combined with **AND** (all must match) or **OR** (any may match). No
conditions means every order qualifies.

| Condition | Matches when |
| --- | --- |
| Subtotal | The merchandise subtotal (after product sales, before coupons, shipping and added tax) is at least, at most or between amounts |
| Products | Any or all of the selected products are in the order |
| Categories | A product from the selected categories (or their subcategories) is in the order |
| Quantity | The total quantity, or the quantity of selected products/categories, reaches a minimum |
| Customer groups | The customer belongs to one of the selected groups. Guests never match a group condition |
| Shipping method | The selected shipping method is chosen and available |

## Benefits (automatic promotions)

- **Percentage** (up to 100%) or **fixed amount** off, applied to the **order** or to **product lines**: all products or
  selected products and categories, with optional exclusions and an option to skip products already on sale.
- **Free shipping**: waives the quoted shipping charge of an available method (product shipping costs and carrier
  surcharges remain).

A coupon-linked promotion takes its benefit from the coupon; edit the discount in **Coupons**.

## How several promotions combine

1. A standalone coupon is applied first, then promotions in order of **priority** (lower number first; equal priority
   in creation order).
2. Order-level percentages **compound** on what remains: two 20% promotions on €100 give €36, not €40.
3. A product-line promotion is granted only if it gives a **better price** than the product's active sale price; it
   never discounts a sale price again.
4. **Stop lower priority promotions after granting a benefit** stops all promotions with a strictly lower priority, but
   only once this promotion actually granted something.
5. Each promotion applies at most once per order. Taxes are recalculated at the end.

## Usage

Promotions are recorded on the order when it is saved. Cancelling an order (a status that returns stock) releases the
usage; the order keeps the amounts it was charged.

## Permissions

*View promotions*, *Manage promotions*.
