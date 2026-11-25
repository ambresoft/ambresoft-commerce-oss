---
title: Cart and checkout
parent: The storefront
nav_order: 2
---

# Cart and checkout
{: .no_toc }

1. TOC
{:toc}

---

## The cart

- Visitors add products from product cards and pages. Quantities follow each product's minimum, maximum and step
  and its stock rule.
- A guest's cart is kept in their browser for 30 days after the last change; a signed-in customer has one cart, and a
  guest cart merges into it on sign-in.
- `/cart` shows the lines with live prices, sales and taxes. A line that cannot be bought right now says why and is left
  out of the total.
- **Automatic promotions** apply without a code; each one is listed with the amount saved.
- One field, **Coupon or gift card code**, accepts a coupon and a gift card (codes starting with `GC-` are gift cards).
  It appears when **Enable coupons and gift cards in the cart** is on in General settings.
- The cart never reserves stock or redeems codes; that happens when the order is placed.

## Checkout

`/checkout` is a single page with these sections:

1. **Contact**: guests type an email (unless the store requires an account); customers order with their account email.
   A phone number is required for delivery.
2. **Delivery address**: customers choose a saved address or type a new one (and can save it); guests type one. The
   country must be one of the store's shipping countries.
3. **Billing**: *same as delivery* by default.
4. **Delivery method**: the available shipping methods with their price (when the store has shipping methods).
5. **Payment method**: the methods allowed for these products and this delivery method, with fees and descriptions.
6. **Order notes**.
7. **Summary**: products, discounts, shipping, payment fee, tax, total, gift card and the amount to pay, with the code
   field.

Every amount is calculated on the server and refreshed as the customer changes choices. **Place order** stays disabled
until everything required is filled in, and a note says what is missing. Submitting twice never creates two orders.

Carts that contain only **digital** products ask for a billing address only, without a delivery method.

### Requesting an invoice

When invoice requests are enabled (**General settings → Checkout**), business customers tick **Request invoice** and
enter their VAT registration country and number. The invoice request is accepted only after the number is verified
(VIES, AADE, checksum or VAT Sense, as configured). If verification fails or is unavailable, the customer can still order
with a receipt.

## After placing the order

- **Online payment** (for example Stripe): the customer is sent to the provider's page. The order is marked paid only
  after the server verified the payment.
- **Offline payment** (cash on delivery, bank deposit): the confirmation page shows the method's instructions, for
  example your IBAN.
- The **confirmation page** shows the order number, lines, totals, address, delivery method and payment state. It has a
  private address that works for 30 days. Unpaid online orders offer **Pay now**.
- The customer receives the email of the order's initial status, and staff who handle orders get a notification.

## Paying later

Staff can share a **customer payment link** from the order page. It opens a secure page where the customer pays the
outstanding amount online; the link is valid for 30 days.
