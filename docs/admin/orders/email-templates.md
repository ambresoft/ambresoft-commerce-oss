---
title: Email templates
parent: Orders
grand_parent: Administration guide
nav_order: 4
---

# Order email templates

**Orders → Email templates** (`/admin/order-emails`)

Customers receive an email automatically when an order is created and every time its status changes. This screen lists:

- **Header & footer**: wrapped around every status email. The shop logo is in the header by default.
- **Order summary email**: the standalone document sent when an order first enters a status with *Send the order
  summary* (it does not use the header and footer). Its layout also defines the printable order summary and the
  customer's download.
- **One email per order status**, in every language.

## Editing an email

1. Click the edit icon of the email and choose the **language** to edit.
2. Edit the **Subject** and the **Message**. Use the variable buttons to insert order data at the cursor.
3. Click **Preview** to see the email rendered with sample data. Nothing is saved or sent.
4. Click **Send a test email** to send the email (with sample data) to an address of your choice. This uses the
   delivery method saved in **Settings → General settings → Emails**, so it is also a good test of your mail settings.
5. Save.

If an email in the order's language is missing, the default language's email is used.

## Variables

Variables are written in double curly braces, for example {% raw %}`{{order_number}}`{% endraw %}. Only the variables
offered by the editor are accepted; an unknown variable is refused when you save. Values are inserted safely (escaped).

| Kind | Variables |
| --- | --- |
| Order | `order_number`, `order_date`, `previous_status`, `current_status`, `status_changed_at`, `order_currency`, `subtotal`, `discount_total`, `tax_total`, `shipping_total`, `payment_fee`, `grand_total`, `payment_method`, `shipping_method`, `customer_note` |
| Customer | `customer_name`, `customer_first_name`, `customer_last_name`, `customer_email`, `customer_phone` |
| Store | `store_logo`, `store_name`, `store_email`, `store_phone`, `store_website`, `store_address`, `store_contact`, `store_legal_name`, `store_business_activity`, `store_tax_id`, `store_tax_office`, `store_gemi_number`, `store_details` |
| Blocks | `order_items` (product table), `billing_address`, `shipping_address`, `cancel_link`, and the order summary's blocks |

`cancel_link` adds a cancellation link for statuses that allow customer cancellation; it is added automatically to
templates that do not contain it, and is empty for other statuses. The customer's IP address, internal notes and
comments are never available to emails.

{: .note }
> For safety, email HTML may contain layout tables, inline styles, `https` images and links, but never scripts,
> forms or embedded content. Anything else is removed when you save.

## The order summary

The order summary email has a full HTML document plus two repeating row templates (one for product rows, one for
total, tax and payment rows). It shows the store details, order number, status and date, the billing and shipping
addresses, the products with codes, tax lines and totals. For orders where the customer requested an invoice with a
verified VAT number, {% raw %}`{{invoice_customer_details}}`{% endraw %} prints the business name, VAT number and
country.

{: .important }
> The order summary is a commercial document, **not an official fiscal invoice**. Official invoicing (for example
> Greek AADE myDATA) is not part of the platform.

## Permission

*Manage order email templates* (`order-emails.manage`). Email delivery (sender, SMTP) is configured in
[General settings](../../settings/general/).
