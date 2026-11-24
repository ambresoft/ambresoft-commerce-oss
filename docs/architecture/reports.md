# Analytics & Reports (A94)

Reports → Overview, Sales, Orders, Products, Categories, Customers, Inventory and Promotions: operational reports computed in PostgreSQL from the orders, payments, order-line snapshots, customers, products and coupon redemptions the CMS already stores. There is no visitor tracking, conversion, acquisition-cost or profit reporting (none of that data exists), and no second ledger: nothing here writes data.

The existing dashboard keeps its own quick view (orders currently holding stock). Reports use the stricter definition below, so the two sales figures can differ; the dashboard links to Reports for the paid figures.

## Source data and what the CMS does not store

| Used | From |
| --- | --- |
| Order amounts and currency | `orders` snapshot: `subtotal`, `discount_total`, `tax_total`, `tax_included`, `shipping_total`, `shipping_discount`, `total`, `currency_code`/`currency_decimals`. The database enforces `total = subtotal − discount_total + (tax_total − tax_included) + shipping_total` |
| Lines | `order_items` snapshot: name, SKU, quantity, charged unit price, `subtotal`, allocated `discount_total` |
| Payment state and date | `order_payments` in state `captured` (online providers, payments staff record and gift cards, so cash on delivery counts once the money is recorded; a gift card payment is money received, and issuing a card is not a sale) |
| Order meaning | `order_statuses`: *void* = final and restores stock (cancelled, failed or any custom status configured like them); names are the admin's own |
| Customers | `orders.customer_id` (registered) or the order e-mail (guest); `customers.created_at` |
| Coupons | `coupon_redemptions` (+ `orders.coupon_code`) |
| Stock | `products.quantity`, `products.type`, `deleted_at`, the general low-stock threshold |

**Not stored, therefore unavailable (shown as "not available", never as zero):** refunds, product cost (so no inventory value or profit), stock movement history, a product-level discount (sale prices are applied before the order and the order stores the price charged), historical exchange rates (orders keep their own currency), and a category snapshot on order lines.

## Definitions

- **Paid** — the captured payments of the order cover its total. **Payment date** — the `completed_at` of the payment that first covered it (later duplicate payments never move it). An unpaid cash-on-delivery order is *not* a sale and not "collected"; it counts when staff record the money.
- **Void** — current status is final and restores stock. **Sale** — a paid, non-void order with a positive total. Sales are dated by payment date, in the store timezone, in the order's own currency.
- **Gross merchandise sales** = Σ line quantity × unit price charged (`orders.subtotal`). **Discounts** = Σ discounts allocated to lines (`orders.discount_total`, coupons). **Refunds** — unavailable. **Net merchandise sales** = gross − discounts.
- **Shipping charged** = Σ `shipping_total` (already net of free-shipping coupons; `shipping_discount` is reported separately). **Tax collected** = Σ (`tax_total` − `tax_included`), the tax added on top; `tax_included` (already inside prices) is shown as a note.
- **Total charged** = net merchandise + tax added + shipping = Σ `orders.total`. Nothing is counted twice; the screen, the series and the CSV reconcile to this identity.
- **Average order value** = total charged ÷ paid orders. **Units sold** = Σ quantity of sale lines.
- Orders **placed**, **unpaid** (value = total − captured, for non-void orders), **cancelled/failed**, and **paid then cancelled** (money collected, no refund recorded: needs staff action) are shown beside the sales as context, never added to them.
- **Orders report**: every order once, by its *current* status; date basis *placed* or *paid* (the paid basis also lists orders cancelled afterwards). Order value, collected money and outstanding are separate columns; payment state (paid / partly paid / pending / not paid) is derived from payments, independent of status.
- **Products**: sale lines grouped by product (a permanently deleted product by its snapshot name and SKU); names, SKUs and amounts are the ones stored on the order. *No sales in period* and *never sold* use the same sale rule; *declining* = sold in the previous equivalent window and fewer units now.
- **Categories**: each line's own merchandise value is credited to its product's **current** category. *Direct* credits assigned products only; *with subcategories* a parent includes its children. An order with lines in several categories counts once per category; the total row is computed from the lines once (never summed from rows), so it cannot double count. Lines of deleted products are *unclassified*. Because orders keep no category snapshot, moving a product moves its past sales.
- **Customers**: registered customers by `customer_id`; guest orders by e-mail, **never merged** with registered customers (no reliable identity link exists). *Repeat* = at least two sales in the window; *returning* = a registered customer with a sale in the window and an earlier sale; *new* = customer profile created in the window; *without orders* = registered customers with no orders at all.
- **Inventory**: undeleted *physical* products (digital products are counted only). *Out of stock* = quantity ≤ 0; *low* = 0 < quantity ≤ the general threshold; rankings by lowest/highest quantity and above a chosen threshold. Quantities are live values, never inferred from sales.
- **Promotions**: by redemption date, active redemptions only (released ones are counted separately); discount and shipping discount granted come from the redemption; *paid orders/sales* are the redeeming orders that are paid sales today. One coupon per order, so attribution is unambiguous.

## Filters, periods and currency

Period presets (today, yesterday, last 7/30 days, this month, previous month, this year) and a custom range (at most five years) use the store timezone; the window is `[start, end)` at local midnight and boundaries are passed to PostgreSQL as UTC instants (a payment at 00:00 local time belongs to the new day). The comparison window is the equivalent one: month-to-date against the same number of days of the previous month, year-to-date against the same span of the previous year, otherwise the equal window just before. A change is shown only when the previous window has data. Series are grouped by hour, day, week (Monday) or month by window length and zero-filled.

Filters (status, payment method, shipping method, product, category, customer, currency, date basis) are shown only where meaningful and live in the URL, so a report can be bookmarked and reloaded. Product or category filters narrow the sales report to **order lines**: shipping, tax, total charged and average order value are then unavailable (they cannot be attributed to a product). Filtering by customer requires the customer-report permissions.

**Currency**: every report is for one currency (default: the primary); amounts of different currencies are never added, converted or compared. Orders in other currencies are listed in a note and reachable through the currency filter. Orders store no exchange rate, so no consolidated total is offered.

## Permissions, privacy and export

`reports.view` (money, order, product, category, inventory and promotion reports), `reports.customers` (customer report; also needs `customers.view`) and `reports.export` (CSV). The orders report also needs `orders.view` because it names customers; narrowing by customer needs both customer permissions. Sales detail tables show order numbers only. New installations give the Store Admin role all three (existing roles via `cms:permissions-sync --grant-commerce-defaults`); others are granted through Roles. All parameters are validated (identifiers must exist, sorts and views are allow-lists, per page 25/50/100, periods rejected when invalid).

CSV (`/admin/reports/{report}/export`, same filters and ordering as the screen, UTF-8 with BOM, spreadsheet formulas neutralised) streams from a database cursor; amounts are plain decimals with a `currency` column, and personal data appears only in the customer export.

## Implementation

`Reporting/Domain/Model/ReportPeriod` (pure period/bucket/comparison rules), `Reporting/Application/Command/ReportFilters`, `Reporting/Infrastructure/ReportOrders` (the single sale definition used by every report), `SalesReport`, `OrdersReport`, `ProductReport`, `CategoryReport`, `CustomerReport`, `InventoryReport`, `PromotionReport`, `ReportViews` (page data), `ReportExport`; `Admin/ReportController` and `ReportRequest`; React screens under `pages/Admin/Reports` with small SVG chart components (no chart library). Reports read only; a failing report cannot affect ordering, payment or catalog work.

## Performance

All figures are grouped aggregates in PostgreSQL; tables paginate in the database and exports stream, so memory does not grow with history. The paid-order lookup examines only orders with a captured payment inside the window; migration `2026_10_23_000100_add_report_payment_index` adds the partial index `order_payments (completed_at, order_id) WHERE state = 'captured'` for it. Measured on 200,000 paid orders (a two-day window): 14.9 ms with the index, 24.8 ms without (the remaining cost is a scan of the captured payments, linear in payment history). Nothing is cached, so figures are always current; if history grows into the millions a pre-aggregated daily table is the next step, not needed now.

## Tests

`ReportPeriodTest` (windows, timezone, comparison, buckets), `ReportsTest` (hand-calculated sales, payment-date, boundary, currency, snapshot, product, category, customer, inventory, order-status, filter, breakdown, promotion and empty-store cases), `ReportsHttpTest` (screens, permissions, validation, pagination/sorting, query counts, deleted/missing data, CSV consistency and safety, a 2,400-order history) and `Reports.test.tsx`.

## Not included / next phase

Refund-aware figures (needs recorded refunds), inventory value and profit (needs product cost), stock-movement history, consolidated multi-currency totals (needs stored exchange rates), a category snapshot on order lines, visitor/conversion analytics, scheduled report e-mails, saved report views and a pre-aggregated reporting table for very large histories.
