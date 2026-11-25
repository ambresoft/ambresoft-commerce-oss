---
title: Order statuses
parent: Orders
grand_parent: Administration guide
nav_order: 3
---

# Order statuses

**Orders → Order statuses** (`/admin/order-statuses`)

Statuses are the steps an order goes through. Each status decides what happens to **stock**, which **email** the
customer receives and whether the customer may **cancel**.

## The statuses you start with

| Status | Stock | Notes |
| --- | --- | --- |
| **Open** | No change | The **initial** status: new orders start here. Customer can cancel |
| **Processing** | Take from stock | Being prepared. Customer can cancel |
| **Ready for shipment** | Take from stock | Packed and waiting for the carrier |
| **Shipped** | Take from stock | On its way |
| **Completed** | Take from stock | **Final**. Sends the **order summary** email. Does not record a payment |
| **Cancelled** | Return to stock | **Final**. Used for customer cancellations |
| **Failed** | Return to stock | **Final**. The order could not be completed |
| **Incomplete** | No change | Started but not finished |

These built-in statuses can be renamed, recoloured, reordered and disabled, but not deleted.

## Settings of a status

| Setting | Meaning |
| --- | --- |
| **Name** | Per language; the default language is required |
| **Code** | A permanent technical identifier (lowercase letters, digits, `_`) |
| **Colour** | The colour of the status pill in lists |
| **Description (staff only)** | Internal explanation |
| **Stock when entering this status** | *No change*, *Take quantities from stock* or *Return quantities to stock* |
| **Initial status** | New orders start here. There is always exactly one initial status, and it must stay active |
| **Final status** | The order is finished; editing it asks for confirmation |
| **Send the order summary** | The first time an order enters this status, the order summary email is sent too |
| **Customer can cancel** | While an order is in this status (and not paid), the customer can cancel it |
| **Active** | Only active statuses can be chosen; orders already in a disabled status keep showing it |

Every status has a customer email in every language, created together with the status. Edit it with **Edit email** or
in [Email templates](../email-templates/).

## How stock works

- *Take quantities from stock*: the order holds its quantities.
- *Return quantities to stock*: everything the order took is returned.
- *No change*: the order keeps whatever it holds.

Only differences are applied, so moving an order between two "take" statuses never takes stock twice. If stock is
insufficient, the status change is refused. With backorders allowed, only the available units are taken (stock never
goes below zero). If inventory tracking is switched off in General settings, statuses do not move stock. Changing a
status's stock setting only affects future changes.

## Reordering

Drag the grip on a row (or focus it and use the up and down arrow keys) to change the order of statuses. This only
changes how they are listed.

## Adding and deleting

Click **Add status** to create your own. A status can be deleted only if no order and no order history ever used it;
otherwise, disable it.

## Permission

*Manage order statuses* (`order-statuses.manage`).
