---
title: Call requests
parent: Orders
grand_parent: Administration guide
nav_order: 2
---

# Call requests

**Orders → Call requests** (`/admin/call-requests`)

A call request is a customer asking to be called back, for example before ordering. Requests arrive from the
storefront's phone icon, or staff enter them by hand (for example after a missed call).

## Statuses

| Status | Meaning |
| --- | --- |
| **New** | Not handled yet. Requests from the storefront always start here, unassigned |
| **In progress** | Someone is working on it |
| **Completed** | Done |
| **No answer** | The customer could not be reached |

## The list

- Search by request ID, name or phone number.
- Filter by status, responsible staff member and requested date.
- Change the **status** and the **responsible** staff member directly in the list.
- Notes show as a short preview; open the request to read them in full.
- Tick rows to delete several requests at once.

## Creating and editing a request

Click **Create** and fill in:

| Field | Notes |
| --- | --- |
| Phone | **Required**. Checked against the phone rules in **Settings → General settings** |
| Name | Optional |
| Preferred call time | Optional |
| Notes | Optional, up to 10,000 characters |
| Responsible | Optional: an active staff member who can manage call requests |
| Order number | Optional: links the request to an existing order and shows its current status |
| Products | Optional: products the customer asked about (name and SKU are kept even if the product is deleted later) |

Linking an order or products never changes the order, prices or stock and sends no email. Delete a request with the
trash icon next to **Save**; deletion is permanent.

## Requests from the storefront

The call-request phone icon is switched on per layout: in **Website → Layouts**, edit the header's identity block and
turn on **Show call request icon** (off by default). Customers then see a phone icon next to the account icon. The form
asks for an optional name, a **required phone**, an optional order number and an optional preferred time range on the
same day (initially 09:00–20:00).

The form is protected against spam and limited to six attempts per minute per visitor. Customers never see other
requests or whether an order number exists. The store does not call or notify anyone automatically: staff follow up
from this list.

## Permissions

*View call requests*, *Create and manage call requests* and *Delete call requests* are separate permissions.
