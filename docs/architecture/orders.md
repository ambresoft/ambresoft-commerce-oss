# Orders, order statuses and customer emails — Phase 9

Admin-side order management behaviourally inspired by CS-Cart's single-store order workflows (order list, order details, quick status change, configurable statuses, status emails); no CS-Cart code or schema. Customer management is reused, not duplicated. No storefront checkout, shipping method or refund capability exists. Payment methods, payment records and payment status were added in Phase 10 ([payments](payments.md)): orders store the chosen method and its name through the `OrderPaymentMethods` port; payment state is separate from the order status.

## Ownership

| Layer | Types |
| --- | --- |
| Domain | `OrderStockRules` (none/decrease/restore targets), `TemplateTokens` (allow-listed `{{variable}}` replacement), `OrderTotals` (exact totals, exact tax allocation) |
| Application | `OrderMailTransport` (delivery boundary), `OutgoingMail`, `MailDelivery` |
| Infrastructure | `OrderAdministration` (the only write path), `OrderPricing`, `OrderStock`, `OrderNotifier`, `OrderEmailRenderer`, `OrderEmailTemplates`, `OrderStatuses`, `OrderQueries`, `OrderInput`, `OrderOrigin`, `PhpMailTransport`, `OrderDemoSeeder` |
| Presentation | `OrderController`, `OrderStatusController`, `OrderEmailTemplateController` (Admin), `cms:orders-demo`. Navigation: an Orders group (All orders, Email templates); Order statuses stay under Settings |

## Persistence (migration 2026_10_15_000100)

| Table | Purpose and integrity |
| --- | --- |
| order_statuses / order_status_translations | Stable ID and immutable code, colour, position, active, single initial (partial unique index, always active), final, system (seeded, undeletable), stock action; names per language |
| order_email_templates | One subject + sanitized HTML body per status and language; unique per pair; generated with the status |
| orders | `order_number` from `order_number_seq` (owned by the column; unique; gaps after rollbacks are never reused); optional customer link; contact snapshot; customer IP (`inet`, nullable); currency code/precision; subtotal, discount, tax (with the included part), shipping, total — a CHECK enforces `total = subtotal − discount + (tax − included) + shipping`; tax method/exemption; notes; `stock_held`; version |
| order_addresses | Billing and optional shipping snapshot |
| order_items | Product link (nullable after permanent deletion), name/SKU/variation snapshot, quantity, catalog and charged unit price, override flag, subtotal, tax, total (CHECK-reconciled) and `deducted_quantity ≤ quantity` |
| order_taxes | Order-time tax breakdown, no foreign key to taxes |
| order_status_history | Append-only (UPDATE trigger; only a removed staff actor may be nulled); from/to status, actor, source admin/system/checkout, note |
| order_notifications | One per history entry: status, template, recipient, subject, state pending/sending/sent/failed, attempts, short error code, times |
| order_comments | Internal or customer-visible staff comments |

Statuses referenced by orders or history cannot be deleted (restrictive keys); disabling never rewrites orders or templates. Order money columns use `numeric`; arithmetic is bcmath. The central audit ledger captures status, template, order and item fields but not names, emails, addresses, notes or IP.

The status list uses the same drag grip and numbered rows as payment methods. Dragging or pressing the up/down keys on a focused grip saves the full order through an authorized, audited transaction. The server rejects duplicate, missing or unknown status IDs; only positions that change increment editor versions. Reordering does not change stock actions, flags, orders or templates.


## Behaviour

- **Creation**: validated server-side (shared customer address/phone rules), priced and taxed through `TaxCalculationService::forLines` with the order addresses and the customer's exemption, written with items/taxes/addresses, stock applied for the chosen active status, then one history entry and one notification — all in one transaction. A failing line leaves no order.
- **Editing**: contact, addresses, notes and language always; lines, quantities and order-specific prices recalculate the order. Only explicit content or tax-relevant address changes recalculate; re-saving keeps every stored amount. Final statuses require a confirmation; an order whose currency is no longer the primary currency cannot be re-priced. Lines of permanently deleted products can only be kept or removed.
- **Status change**: one method for the list quick action, the detail page and internal callers. The same status is a no-op (no history, email or stock). Only active statuses can be assigned; orders already in an inactive status keep showing it.
- **Stock**: each line records units taken. `decrease` targets the quantity, `restore` targets zero, `none` keeps whatever the order holds. Only differences move stock; product rows are locked in ID order (no oversell, no deadlock). Without enough stock the transition is refused and nothing changes; with backorders only available units are taken (stock never goes negative). With inventory tracking off, statuses do not move stock. Changing a status's stock action affects only future transitions.
- **Emails**: sent after commit to the order email in the order's language (default-language template as fallback). Sender: Settings → General → Emails (`order_email_from`, optional sender name — default the company name — and reply-to; migration 2026_10_15_000200 with header-safe checks). Company contact addresses never affect delivery. A missing sender is a recorded failure. A retry updates the same record behind an atomic claim, so concurrent retries cannot double-send. `mail()` returning true only means the local mail system accepted the message.
- **Templates**: subject plus rich-text body (shared editor and sanitizer). Only the documented variables are accepted (unknown ones are rejected on save); values are HTML-escaped; block variables render the item table and addresses. IP, internal notes and comments are not available. Previews render unsaved content with fictitious data and never send.
- **IP**: `OrderOrigin::fromRequest` uses `Request::ip()` and the framework's trusted-proxy configuration (none trusted by default); submitted fields are ignored. Staff-created orders store none. Visible and filterable only with `orders.customer-ip`.

## Bulk status changes

`/admin/orders` offers row selection and select-all for the current page to staff with `orders.status`. The selection resets when the page, filters or returned order versions change. The toolbar offers only active statuses; a native confirmation dialog lists the selected order numbers and target status and explains stock/email effects. The same dialog now confirms single-row quick changes. Requests preserve the current list URL and show server errors without claiming partial success.

`PATCH /admin/orders/bulk-status` submits explicit `{id, version}` rows (1–100 distinct orders) and one target status. `OrderAdministration::bulkStatus` checks persisted `orders.status`, validates the selection, locks all selected orders in ascending ID order, and invokes `changeStatus` for every order inside one transaction. Missing/stale orders, inactive statuses and stock/reservation failures roll back the entire batch, including history and notification records. Unchanged statuses remain no-ops. Existing stock, coupon/promotion/product-sale rules, history and audit are reused; email delivery runs after the outer commit. Feedback gives the number actually changed and points to each order for email delivery results. No migration or new capability is needed.

## Ready for shipment

`ready_for_shipment` is a built-in, deletion-protected status, initially active and non-final, positioned immediately before Shipped. It means the order is packed and awaiting handover to the carrier. Like Processing and Shipped it uses `decrease`: only stock differences are applied, so an already-held order is never deducted twice. It has its own editable subject and email body in all six bundled languages, shared email header/footer, and the usual status-history/notification/audit behavior. It is available in single and bulk order actions. Like other system statuses, merchant names, colors, ordering and settings remain editable; it does not impose a new mandatory transition sequence.

Migration `2026_11_21_000200_add_ready_for_shipment_order_status` installs it for existing stores before Shipped while retaining the relative order and email content of existing statuses. Fresh installs seed it directly. Repeated seeding does not overwrite merchant templates. Rollback removes it only while no order or history references it.

## Permissions

`orders.view`, `orders.create`, `orders.update` (editing and comments), `orders.status`, `orders.notify` (retry), `orders.customer-ip`, `order-statuses.manage`, `order-emails.manage`. Every service method re-checks its capability; internal callers without an actor (future checkout, the local demo) are trusted code paths.

## Limitations and dependencies

The storefront [checkout](checkout.md) calls `OrderAdministration::create(null, …, 'checkout', OrderOrigin::fromRequest($request))` and `placed()` for repeated submissions. Shipping, coupons, promotions, gift-card settlement and SMTP settings are implemented (see their context documents). Refunds/RMA, configurable order-number formats and fiscal invoice issuance remain deferred. The current commercial invoice view is not a fiscal issuing system; see the [AADE addon plan and legal gaps](aade-invoicing-addon.md).

## Email layout, invoices and testing (migrations 2026_10_15_000300 and 000400)

- **Header and footer**: `order_email_layouts` holds, per language, an editable HTML header and footer wrapped around the status emails (the invoice email is a standalone document and never gets them), plus the invoice email's subject and body. The default header shows `{{store_logo}}`. All email HTML (header, footer, bodies) uses `EmailHtmlSanitizer`: layout tables, inline styles (declarations that could load or run anything are dropped), https images and links; never scripts, handlers, forms or embeds.
- **Logo**: `{{store_logo}}` renders the Company email logo, falling back to the desktop logo as an inline image (`cid:store-logo`, sent inside the message as a `multipart/related` part), so the private file never needs a public URL; without a logo the store name is shown.
- **Invoices**: statuses have `sends_invoice` (Completed by default; any status can be chosen). The first time an order enters such a status, a second notification of kind `invoice` is recorded and sent with the invoice email, whose editable full HTML renders the existing invoice design (shop details, order number/status/date, bill-to/ship-to, lines with codes, tax lines with registration numbers snapshotted in `order_taxes`, shipping and total). Once per order; a failed one is retried like any email. Admins can open a printable invoice at `/admin/orders/{id}/invoice` (`orders.view`, strict CSP). Invoice numbering separate from order numbers and PDF attachments are not implemented.
- **Editors**: Orders → Email templates lists the header & footer, the invoice email and each status email (edit icons). Editors use HTML code fields with insert-at-cursor variables and a live preview rendered by the server with sample data (`POST /admin/order-emails/preview`, JSON, nothing saved or sent). "Send a test email" (and `php artisan cms:order-emails-test RECIPIENT [--from=] [--locale=]`) sends unsaved or saved content with sample data; no order or notification record is involved.
- **Shipped**: a reference status between Processing and Completed (stock action decrease, so an order shipped straight from Open takes its stock once) with its own "on its way" email. No carrier or tracking integration exists.
- Generated bodies that were never edited were upgraded to the new designed defaults; edited bodies were kept.

## Mail delivery settings (migration 2026_10_15_000500)

Settings → General → Emails holds the sender (address, name, reply-to) and, CS-Cart-like, how mail leaves the server: **PHP mail()** (default), an **SMTP server** (host, optional port — 587 TLS / 465 SSL / 25 none by default —, encryption None/TLS(STARTTLS, required)/SSL(implicit), optional authentication with username and password) or the **sendmail program** (path). `ConfiguredMailTransport` reads the choice on every send and delegates to `PhpMailTransport` or `SymfonyMailTransport` (Symfony Mailer SMTP/sendmail; same header-safety checks; the logo is embedded by Content-ID).

- The SMTP password is write-only: encrypted with the application key (`Crypt`), never sent to the browser (`smtp_password_set` only), and an empty field keeps it; "remove" clears it. Rotating `APP_KEY` makes it unreadable (treated as missing; re-enter it). The username and password are excluded from the audit ledger.
- The sendmail path must be an absolute path to a sendmail-compatible program (`sendmail`, `msmtp`, `ssmtp`, `exim`, `exim4`, `esmtp`) and is run with `-bs` (SMTP over stdin), so no recipient reaches a command line. A free path would let a settings administrator execute any program.
- SMTP connection failures are recorded as `smtp_failed`/`sendmail_failed`; the server's reply goes only to the log. An SMTP choice missing its host or password is reported as `smtp_incomplete` without connecting.
- To check a configuration, use "Send a test email" in an email template editor; it uses the saved delivery method.

Navigation: the Orders menu holds All orders, Order statuses and Email templates.

## Dashboard overview

The admin dashboard (`App\Admin\Dashboard\DashboardOverview`) reads live data; no sample data remains. Each section is returned only to staff holding its capability (`orders.view`, `products.view`, `customers.view`); otherwise the prop is `null`. Sales are orders with `stock_held = true` (a status whose stock action is `decrease`, so cancelled and failed orders drop out) in the primary currency, bucketed per hour (24 h) or per day (7 and 30 days) in the store timezone and compared with the equal period before. "Still open" counts orders in the initial status; out-of-stock counts active, non-deleted physical products with quantity ≤ 0. Activity merges recent order placements/status changes, customer sign-ups and product updates the viewer may see.

## Payment method fees

Orders carry `payment_fee`, the fee of the payment method they are paid with (e.g. cash on delivery), included in
`total`; its VAT follows the order's products, split by value across their rates. See
[payment method fees](payments.md#payment-method-fees-migration-2026_11_08_000600).

## Shipping (Phase 11)

Orders gain `shipping_method_id`, `shipping_method_name` and a `shipping_details` snapshot; `shipping_total` holds the charge and its taxes join the order taxes. The order editor chooses the method, may enter the charge, and recalculates only when something affecting shipping changed. Carrier shipments, labels and tracking appear in the order's Shipping card. See [shipping](shipping.md).

## Customer cancellation (migration 2026_11_09_000300)

Each order status has **Customer can cancel** (`order_statuses.customer_can_cancel`, audited), on by default for *Open*
and *Processing* only; staff switch it per status in Orders → Statuses. While an order is in such a status and has no
captured, pending or processing payment (there are no refunds yet), the customer can cancel it:

- from the account's order page (`POST /account/orders/{order}/cancel`, own orders only, confirmation dialog);
- from the signed link that status e-mails carry (`{{cancel_link}}`, appended to templates that do not place it;
  empty for statuses that do not allow cancelling). `GET /orders/{order}/cancel` (signed, 30 days) only shows a
  confirmation page, so mail scanners that follow links cancel nothing; its form cancels.

`OrderCancellation` moves the order to the active `cancelled` status through `OrderAdministration::changeStatus()` with
source `customer` (added to the history CHECK), so stock, coupons, promotions and the status e-mail follow as when staff
cancel. Without an active `cancelled` status nothing can be cancelled online. Tests: `OrderCancellationTest`.

## Editable invoice document and customer download (migration 2026_11_10_000100)

The invoice editor exposes its full document HTML plus `invoice_item_html` and `invoice_total_html` repeating row templates. Defaults preserve the six-column design. Legacy `{{invoice}}` content expands on reading/rendering; existing merchant content is preserved. Row placeholders survive HTML table sanitization, and submitted HTML remains non-executable. All three templates are versioned and audited under `order-emails.manage`.

For invoice-requested orders with a saved checkout VAT check, `{{invoice_customer_details}}` renders the snapshotted business name (when supplied by the registry), VAT number and localized registration country. The default document places this plain section after the billing/shipping addresses and before products, using the existing typography without a separate card treatment. Custom full-HTML templates can insert the same block through the variable picker; saved merchant designs are never overwritten. Missing snapshots produce no section. Admin print, standalone email and customer download share the escaped renderer; provider names and verification metadata remain admin-only. The customer's account order detail shows the same saved identity in its existing panel style, only after the normal order-ownership check.

The display groups the customer identity alongside the saved billing address, following the [European Commission's invoice-content guidance](https://taxation-customs.ec.europa.eu/taxation/vat/vat-businesses/invoicing_en). The [AADE myDATA ERP specification v2.0.2, sections 5.1–5.1.1](https://www.aade.gr/sites/default/files/2026-09/myDATA%20API%20Documentation%20v2.0.2_official_erp_1.pdf) distinguishes party VAT/country/name/address from fiscal document identifiers and has country-specific submission rules. These are references for display and future integration, not a claim of fiscal issuance or a myDATA request payload.

`OrderLineVat` deducts allocated discounts before deriving line net/VAT values. New order pricing snapshots retain applied tax rates, including on fully discounted lines. Informational sale reductions and allocated coupon/promotion reductions display effective percentages and net amounts; captured gift-card and other payments appear separately after the sale total. No sale or payment is deducted twice.

`/account/orders/{id}/invoice` downloads printable HTML only for the verified account owner after the durable first invoice notification exists, even if delivery failed or the order later changed status. Links appear on the order list and detail. Admin print, email and customer download consume the same saved template. No PDF, immutable fiscal issuance or AADE submission is implemented. See the [future AADE REST addon](aade-invoicing-addon.md).

The present document is labelled **Order summary / Σύνοψη παραγγελίας** (2026_11_10_000200), including email/status labels, print and customer downloads. Canonical legacy headings and generated subjects are renamed once; merchant content/styles, historical sent-email records and existing `/invoice` routes/template IDs are retained. The future AADE addon will provide a separate official fiscal receipt/invoice.


## Admin order detail consistency and delivery snapshots (2026_11_10_000300)

The detail header shows the saved shipping and payment method names, with an explicit empty state when none was selected. The existing shipping/payment cards retain fulfillment and settlement actions. Reading an order never assigns a currently available shipping method retrospectively or reprices its snapshots.

The admin total presents allocated discounts as deductions and adds only `tax_total - tax_included`. Included tax and its rate breakdown appear below the grand total as information. Per-line allocated reductions are visible; coupon codes and promotion breakdowns identify their sources without suggesting that the whole discount belongs to one coupon. Payment/gift-card settlement stays separate from the sale total. Product links require product-view access.

Order addresses retain nullable floor (20), bell name (100) and delivery notes (500), using the Customer address validation rules. Checkout copies these from the selected customer address into the order; edits to the customer address do not rewrite order history. The admin editor uses the existing address controls, with the street number already combined into address line 1. Order input also accepts a separate street number and validates the combined line length. Admin, account, confirmation and order-summary email views use the saved delivery details.

An older caller omitting delivery fields during an unrelated edit preserves those fields for the same location. Explicit blank fields clear them; an address replacement without delivery details clears the old location's details. Blank optional relationship IDs normalize to null for internal callers as they do through HTTP. Address PII remains excluded from the audit ledger. Existing order addresses stay unchanged/null; current customer addresses are never used to fabricate missing historical details.

Account dates now use the store's configured IANA timezone, matching admin dates and email rendering rather than the visitor's browser timezone. Configuration is preserved (no automatic country-based timezone changes). Order history/comment actor lookup is bounded to actors on that order.
