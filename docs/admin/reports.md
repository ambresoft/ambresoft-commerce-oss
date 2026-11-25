---
title: Reports
parent: Administration guide
nav_order: 6
---

# Reports
{: .no_toc }

**Reports** menu (`/admin/reports`)

Reports are calculated from the orders, payments, products and customers the store already stores. Nothing is
estimated: data the store does not keep (visitor tracking, product cost and profit, refunds) is shown as **not
available**, never as zero.

1. TOC
{:toc}

---

## The reports

| Report | What it shows |
| --- | --- |
| **Overview** | The key figures of the period at a glance |
| **Sales** | Gross and net merchandise sales, discounts, shipping, tax, total charged, average order value, units sold, over time |
| **Orders** | Every order once, by its current status, with order value, money collected and outstanding amount |
| **Products** | Top products by net sales, products with no sales in the period, never sold, and declining |
| **Brands** | Which brands sell best: units, orders, net sales, share of all sales and growth |
| **Categories** | Sales by the products' current category, directly or including subcategories |
| **Customers** | Registered customers and guests: repeat, returning, new, without orders, top spenders |
| **Inventory** | Physical products out of stock, low on stock (by the general low-stock threshold), highest and lowest quantities |
| **Promotions** | Coupon redemptions, discounts granted and the paid sales they produced |
| **Popular searches** | What shoppers search for in the storefront (see below) |

## Important definitions

- **Sale**: an order that is **paid** (the captured payments cover its total), not cancelled or failed, with a positive
  total. Sales are dated by the **payment date**, in the store's timezone.
- A cash-on-delivery order counts as a sale only once staff **record** the payment.
- **Gross merchandise sales** = quantity × unit price charged; **Net** = gross − discounts; **Total charged** = net
  + tax added + shipping. Taxes already included in prices are shown as a note.
- Orders that were paid and then cancelled are shown separately because the money needs staff action (refunds are not
  recorded by the store).
- The dashboard's *Sales* figure counts orders in statuses that take stock, so it can differ from these paid figures.

## Periods, filters and currency

- **Periods**: today, yesterday, last 7 or 30 days, this month, previous month, this year, or a custom range (up to five
  years). Each figure is compared with the equivalent previous period when it has data.
- **Filters** (where meaningful): status, payment method, shipping method, product, category, customer, currency and
  date basis (placed or paid). Filters are kept in the address, so you can bookmark a report.
- Filtering by product or category narrows the report to order lines, so shipping, tax and totals are not available
  then.
- **Currency**: each report shows one currency (the primary by default). Amounts in different currencies are never added
  together; choose the currency in the filter.

## Exporting

Report tables can be downloaded as CSV with the *Export report tables as CSV* permission.

## Popular searches

**Reports → Popular searches** (`/admin/search-terms`) lists what shoppers searched for (a normalized text, how often
and how many products the latest search found). No visitor or personal data is stored.

- Filter by results and visibility.
- The switch on a term **hides** it from the storefront's *Popular searches* block.
- Terms searched once, not hidden, not picked in a block and older than 90 days are deleted automatically.

This screen needs the *Manage product search* permission and a search add-on (Typesense).

## Permissions

| Permission | Allows |
| --- | --- |
| *View sales, order, product, category, inventory and promotion reports* | Most reports. The Orders report also needs *View orders* |
| *View customer reports (names and spending)* | The Customers report (also needs *View customers*) and filtering by customer |
| *Export report tables as CSV* | CSV downloads |
