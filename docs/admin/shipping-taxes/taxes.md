---
title: Taxes and rate areas
parent: Shipping & Taxes
grand_parent: Administration guide
nav_order: 2
---

# Taxes and rate areas
{: .no_toc }

**Shipping & Taxes → Taxes** (`/admin/taxes`) and **Shipping & Taxes → Rate areas** (`/admin/rate-areas`)

1. TOC
{:toc}

---

## Rate areas

A rate area is a geographic area used to choose a **tax rate** or a **shipping rate**.

- An area contains **whole countries**, or only some **regions** of a country (by region code, for example `ATT`).
- One area can be the **default** area: it has no countries and matches every address, including a missing one.
- Each area has a name per language and a status.

When several areas match an address, the most specific wins: **region** > **country** > **default**.

## Creating a tax

Click **Create tax** and fill in:

| Field | Notes |
| --- | --- |
| **Name** | Per language, for example *VAT* |
| **Registration number** | Optional tax identifier, printed on the order summary |
| **Priority** | Taxes with the same priority are calculated on the same amount; a higher number is calculated on the price plus the lower priorities (tax on tax) |
| **Price includes this tax** | Checked: your product prices already contain this tax and it is extracted. Unchecked: the tax is added on top |
| **Rates depend on** | The **Shipping address** or the **Billing address** of the customer |
| **Rates** | One rate per rate area: a **Percentage** or a **Fixed amount** |
| **Status** | Disabled taxes stay assigned but are never applied |

{: .important }
> An explicit rate of **0** means "no tax here". A **missing** rate is not treated as zero: the tax is reported as
> unresolved for that address. Add a 0 rate (or a default area) where you intend no tax.

## Assigning taxes to products

- In a product's **Pricing & inventory** tab, choose its taxes.
- On the Taxes list, **Assign to all products** adds active taxes to every product that does not have them yet (including
  products in the Trash). It asks for confirmation and needs both *Assign taxes to all products* and *Update products*.

Assigning or calculating taxes never changes the prices you entered.

## How taxes are calculated

1. Only active taxes assigned to the product apply, in order of priority.
2. **Included** taxes are extracted from your price to find the net amount.
3. **Added** taxes are calculated on the net amount (plus lower-priority taxes for higher priorities).
4. Each tax amount is rounded once to the currency's decimals.
5. **Tax-exempt** customers (a setting on the customer) pay no tax: added taxes are not charged and included taxes are
   removed from the price.

Before the customer enters an address, the store can **estimate** taxes with a default country set in **Settings →
General settings**; otherwise only a default-area rate applies.

## Permissions

*View taxes*, *Create taxes*, *Edit taxes, their rates and status*, *Delete taxes*, *Assign taxes to all products*,
*View rate areas*, *Manage rate areas*. The calculation settings need *Manage general and company settings*.
