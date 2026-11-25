---
title: Coupons
parent: Marketing
grand_parent: Administration guide
nav_order: 1
---

# Coupons

**Marketing → Coupons** (`/admin/coupons`)

A coupon is a **code** customers enter in the cart (or staff enter on an order) to get a discount or free shipping.

## Creating a coupon

Click **Create coupon**. On the **General** tab:

| Field | Notes |
| --- | --- |
| **Coupon name** | Internal name for your team. Customers only see and enter the code |
| **Code** | Unique and **case-sensitive**: 3–20 letters, numbers, hyphens or underscores. Deleted codes stay reserved |
| **Discount type** and **Discount** | **Percentage** (0–100) or **Fixed amount** in the primary currency. A zero discount requires free shipping |
| **Minimum subtotal** | Minimum merchandise subtotal before tax and shipping, after product sale prices. Blank or 0 for no minimum |
| **Registered customers only** | Only signed-in customers can use it |
| **Free shipping** | Removes the shipping charge and its taxes |
| **Eligible products** | **All products**, or **Selected products and categories** (a category includes its subcategories) |
| **Start date**, **End date** | Optional; both include the whole day in the store's timezone |
| **Uses per coupon**, **Uses per customer** | Blank or 0 for unlimited |
| **Status** | Enabled or Disabled |

## How coupons are applied

- **One coupon per order.** It applies after product sale prices. With *Allow only one coupon per order* on (in
  General settings), a second code is refused until the first is removed; otherwise the new code replaces the first.
- Percentage and fixed discounts apply to the eligible products' prices; taxes are then recalculated. A fixed discount
  never exceeds the value of the eligible products.
- With restricted products, a coupon that matches no product in the cart cannot be used.
- **Usage** is counted when an order is saved, not when it is paid. Moving an order to a status that returns stock
  (Cancelled, Failed) releases the use.
- **Per-customer limits** are tracked by email address, also for guests.

## Usage history

The **Usage history** tab shows each order that used the coupon, the customer, the discount granted, the date and
whether the use was released.

## Deleting

Tick coupons in the list and delete them. Deleted coupons can no longer be applied, but existing orders and their usage
history are kept.

## Coupons in promotions

A [promotion](../promotions/) can wrap an existing coupon to add conditions (for example a customer group) and a
priority. The coupon still defines the discount, scope, dates and limits.

## Permissions

*View coupons and usage history*, *Create and edit coupons*, *Delete coupons*.
