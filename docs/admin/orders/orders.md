---
title: All orders
parent: Orders
grand_parent: Administration guide
nav_order: 1
---

# All orders
{: .no_toc }

**Orders → All orders** (`/admin/orders`)

1. TOC
{:toc}

---

## Finding orders

The list shows each order's number, customer, status, number of items, total and dates.

- **Search** by order number, name, email or phone.
- **Filter** by status, and under **More filters** by invoice (*Invoice requested* / *No invoice*), creation and update
  dates, minimum and maximum total, and customer IP address (only for staff allowed to see IP addresses).
- **Sort** by date created, order number, customer, status, total or last updated.

## Changing the status

You can change the status in three places: directly in the list (status menu on the row), on the order page
(**Change status**), or for many orders at once (bulk).

What happens when an order enters a status:

- **Stock** moves according to the status's setting: *Take quantities from stock*, *Return quantities to stock* or
  *No change*. Only the difference is moved, so an order is never charged twice for the same items. If there is not
  enough stock, the change is refused and nothing happens.
- **The customer is emailed** with that status's template, in the order's language.
- A line is added to the **Status history** with who made the change. You can add a note for the history (not emailed).
- Choosing the status the order already has does nothing: no email, no stock movement.

**Bulk change:** tick orders on the current page (or select all on the page), choose the target status and confirm.
The confirmation lists the order numbers. Either **all** selected orders change, or none do: if one fails (for example
not enough stock), the whole batch is rolled back. Up to 100 orders per change.

{: .warning }
> Changing a status emails the customer. Statuses marked *final* (Completed, Cancelled, Failed) ask for confirmation
> before you edit such an order.

## The order page

Click an order number to open it. You find:

| Section | Contents |
| --- | --- |
| Header | Order number, status, dates, and the saved shipping and payment methods |
| Customer | Name, email, phone, linked customer account (or *guest*), customer IP (with permission) |
| Addresses | Billing and shipping address, including floor, bell name and delivery notes |
| Items | Products with SKU, variation, quantity, unit price, taxes and totals; how many units were taken from stock |
| Totals | Subtotal, discounts (coupons, promotions), taxes, shipping, payment fee and grand total; included tax shown below the total |
| Payment | Payment status, amount received, payment attempts; **Record payment**, **Customer payment link**, check status |
| Shipping | Shipping method and charge; carrier shipments, labels, tracking and pickup lists |
| Status history | Every status change with its source (*Staff*, *System*, *Checkout*, customer), note and email result |
| Comments | Internal comments (staff only) or comments visible to the customer |

### Order emails

Each status change creates an email record shown in the history: *Email pending*, *Email sending*, *Email sent* or
*Email failed* with the number of attempts. If an email failed, click **Retry email** (permission *Retry failed order
emails*). The sender and delivery method are set in **Settings → General settings → Emails**.

### Payments

- The **payment status** is calculated from the money actually received: *Not paid*, *Payment pending* (a provider
  is still confirming), *Partially paid* or *Paid*.
- **Record payment**: for money received outside an online provider, for example cash collected on delivery or a bank
  deposit that arrived. Choosing cash on delivery or bank deposit at checkout never marks an order paid by itself.
- **Customer payment link**: copy a private link and send it to the customer. It opens a page where they pay the
  outstanding amount with an online method (for example Stripe). The link is valid for 30 days.
- A payment method can set an **Order status after payment**, applied once when the order becomes fully paid.
- Refunds are not handled in the administration; make them in your payment provider's dashboard.

### Shipments (carrier add-ons)

When a carrier add-on is installed and configured in **Shipping & Taxes → Carriers**, the Shipping card lets staff
with the *Create and manage carrier shipments and pickup lists* permission:

- **create a shipment (voucher)**: fields are pre-filled from the order and can be adjusted; the order's address is
  never changed;
- **print the label** (PDF), **refresh tracking** and **delete** a voucher (if the carrier allows it);
- if the carrier did not answer clearly, the shipment waits until you check at the carrier and mark it as failed;
- carriers can move the order to configured statuses when a shipment is created, delivered or returned, but never
  overwrite a status a staff member changed.

No carrier is bundled with the platform; carriers are add-ons.

### Order summary and packing slip

- **Order summary** (the printable invoice-style document): open it from the order. It is emailed automatically when
  the order first enters a status with *Send the order summary* (Completed by default), and customers can download it
  from their account afterwards. It is a commercial document, not an official fiscal invoice.
- **Packing slip**: print it from the order and put it in the parcel. It has a cut-off delivery label, store and order
  details, both addresses and the products **without prices**. Edit its layout in [Files](../files/).

### Paying with a gift card

A gift card is a way of **paying**, not a discount. On the order page, staff with *Record payments* and *View gift
cards* enter the card's code: the platform takes the smaller of the card's balance and the amount due, records it as a
payment and lowers the card's balance. Cards in another currency, used or cancelled cards and orders with nothing due
are refused. Cancelling an order later does **not** restore the card's balance. See [Gift cards](../../marketing/gift-cards/).

## Creating an order

Staff with the *Create orders* permission can create an order for a customer (for example a phone order):

1. Click **Create order** (top right).
2. **Find a customer** to link an existing account, or create a **Guest order (no customer account)**. Enter the email,
   phone, names and addresses (tick **Ship to the billing address** if they are the same).
3. **Find products to add**: only priced products that are not in Trash can be added. Adjust quantities; you may enter
   an order-specific unit price.
4. Choose the **shipping method** and click calculate, choose the **payment method**, the status and the **Email
   language**.
5. Save. Prices, taxes, discounts and shipping are always calculated on the server.

Orders created by staff have no customer IP address and do not create the "new order" notification.

## Editing an order

You can always edit contact details, addresses, notes and the email language. Changing lines, quantities or prices
recalculates the order; re-saving without such changes keeps every stored amount. Shipping is recalculated only when
something that affects it changed, or when you tick the recalculate option. Lines of permanently deleted products can
only be kept or removed.

## Customer cancellations

Customers can cancel an order themselves while it is in a status with **Customer can cancel** (*Open* and *Processing*
by default) **and** no payment has been captured or is pending:

- from their account's order page, or
- from the cancel link included in status emails.

The order then moves to the *Cancelled* status exactly as if staff cancelled it: stock is returned, coupons and
promotions are released and the cancellation email is sent.

## Permissions

| Permission | Allows |
| --- | --- |
| *View orders* (`orders.view`) | See the list and orders, print documents |
| *Update orders* (`orders.update`) | Edit orders and add comments |
| *Create orders* (`orders.create`) | Create orders in the administration |
| *Change order statuses* (`orders.status`) | Change statuses, including bulk |
| *Retry failed order emails* (`orders.notify`) | Retry emails |
| *See customer IP addresses* (`orders.customer-ip`) | See and filter by customer IP |
| *Record payments and share customer payment links* (`orders.payments`) | Payment card actions |
| *Create and manage carrier shipments and pickup lists* (`orders.shipments`) | Shipping card actions |
