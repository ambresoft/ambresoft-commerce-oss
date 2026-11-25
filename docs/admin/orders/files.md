---
title: Files (packing slip)
parent: Orders
grand_parent: Administration guide
nav_order: 5
---

# Files: the packing slip

**Orders → Files** (`/admin/orders/files`)

The packing slip is the sheet you print from an order and put in the parcel. By default it contains a cut-off
delivery label, the store and order details, both addresses and the list of products **without prices**, plus the
customer's note.

## Editing the layout

1. Open **Orders → Files** and click **Edit packing slip**.
2. Choose the language and edit the HTML. The **product row** template is repeated for every product.
3. Insert order data with the variable buttons (for example the order barcode, packing details, the payment and
   shipping methods and the customer note).
4. Save.

To print a packing slip, open an order and use **Print packing slip**.

The layout of the order summary (the printable invoice-style document) is edited in
[Email templates](../email-templates/#the-order-summary).

## Permission

*Manage order email templates* (`order-emails.manage`).
