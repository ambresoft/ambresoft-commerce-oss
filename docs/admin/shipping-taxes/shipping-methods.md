---
title: Shipping methods
parent: Shipping & Taxes
grand_parent: Administration guide
nav_order: 1
---

# Shipping methods

**Shipping & Taxes → Shipping methods** (`/admin/shipping-methods`)

Shipping methods are the delivery options offered at checkout, such as *Courier*, *Store pickup* or *Express*.

## Creating a method

Click **New shipping method** and fill in:

| Field | Notes |
| --- | --- |
| **Name**, **Description**, **Delivery time** | Per language. The delivery time is shown with the method, for example "1–2 business days" |
| **Image** | Optional logo |
| **Calculation** | **Manual rates** (your own prices) or **Real-time carrier rates** (a carrier add-on prices each order) |
| **Enabled** | A method needs rates (manual) or a usable carrier account (carrier) before it can be enabled |

### Manual rates

Add rates for each **Delivery area** (a [rate area](../taxes/#rate-areas)) and weight range:

> Price = **Base** + **Per weight unit** × (weight − range start)

- Ranges include their start and exclude their end; an empty end means no limit.
- For an order, the **most specific** matching area is used (a region beats a country, a country beats the default
  area). If no range of that area fits the weight, the method is not offered: it never falls back to a less specific
  area.

### Real-time carrier rates

Choose the **Carrier** and **Carrier account**, then **Charge the customer** the carrier price **including** or
**excluding VAT**, plus an optional **Surcharge** (for example for packaging). Optionally restrict the method to some
**Delivery areas**. If the carrier cannot price an order, the method is not offered for it. Prices are cached for 15
minutes per identical request.

A manual method can still use a carrier for vouchers: **Carrier for vouchers (optional)**.

### Conditions and taxes

| Field | Notes |
| --- | --- |
| **Minimum / maximum order weight** | In the store's weight unit |
| **Respect products with free shipping** | Products marked *Free shipping* add neither weight nor their own shipping cost; an order of only such products ships free |
| **Taxes on the shipping charge** | Calculated with the order's address and tax method |
| **Allowed payment methods** | Leave all unchecked to allow every payment method |
| **Customer groups** | Leave all unchecked to offer the method to everyone, including guests |

## How the shipping charge is calculated

1. The method must be enabled, allowed for the customer's groups, and the order's weight must be within its limits.
2. The charge comes from the manual rate or the carrier price (plus surcharge).
3. Each product's own **shipping cost** × quantity is added.
4. The selected taxes are calculated on the charge.

Orders keep the method, its name and the calculated charge, so later changes to methods or rates never change existing
orders. Free shipping from coupons and promotions is applied on top.

## Ordering and deleting

Drag the grip to change the order in which methods are offered. Switch methods on or off in the list. A method used by
orders shows how many; deleting asks for confirmation.

## Permissions

*View shipping methods*, *Manage shipping methods*.
