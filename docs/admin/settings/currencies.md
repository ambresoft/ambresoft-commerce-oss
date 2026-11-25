---
title: Currencies
parent: Settings
grand_parent: Administration guide
nav_order: 5
---

# Currencies

**Settings → Currencies** (`/admin/settings/currencies`)

## The primary currency

All prices are entered and stored in **one primary currency** (EUR on a new store). Orders, reports, coupons, gift
cards and shipping rates use it.

{: .warning }
> Choose the primary currency **before** entering real prices. Making another currency primary later **converts every
> stored price** (products, sale prices, coupons, shipping rates…) with its exchange rate, in one step. It needs a separate
> permission and asks you to type the currency code to confirm.

## Adding a currency

Click **Add currency** and fill in:

| Field | Notes |
| --- | --- |
| **Code (ISO 4217)** | Three letters, for example `USD`. Cannot be changed later |
| **Name**, **Symbol** | For example *US Dollar*, `$` |
| **Symbol position** | Before or after the amount |
| **Thousands separator**, **Decimal separator** | Comma, period, space or none |
| **Decimal places** | 0–6. For the primary currency, this is also the precision of every price you enter |
| **Exchange rate** | How many units of this currency equal 1 unit of the primary currency. Example: 1.10 shows a 100.00 EUR product as 110.00 USD. The primary currency's rate is always 1 |
| **Status** | Active or Disabled |

Exchange rates are entered by hand; there is no automatic rate service. Secondary currencies only show converted
amounts in the administration (for example next to a product's price); prices are never stored in them, and the
storefront has no currency switcher. Gift cards keep the currency they were issued in and are never converted.

## Deleting

Secondary currencies can be deleted. The primary currency cannot.

## Permissions

*View currency settings*, *Create currencies*, *Edit currency names and formatting*, *Change exchange rates*, *Activate or
disable currencies*, *Change the primary currency (converts product prices)*, *Delete secondary currencies*.
