---
title: Blacklist
parent: Customers
grand_parent: Administration guide
nav_order: 3
---

# Blacklist

**Customers → Blacklist** (`/admin/customer-blacklist`)

The blacklist records email addresses and phone numbers of buyers you want to flag or restrict, for example after
refused cash-on-delivery parcels.

## Adding an entry

Click **Add entry** (or use **Blacklist this customer** on a customer's page) and choose:

| Field | Notes |
| --- | --- |
| **Type** | Email or Phone |
| **Value** | The email or phone number |
| **Reason** | Required, 3–1000 characters, visible to staff only |
| **Restriction** | **Full block** or **Disable payment methods only** |

With **Disable payment methods only**, you pick payment methods (for example cash on delivery): the buyer can still
order, but checkout no longer offers those methods to that email or phone.

## How matching works

- Emails are compared without regard to upper/lower case and surrounding spaces.
- Phones are compared by digits; an international `00` prefix is ignored. National and international forms (for example
  `2101234567` and `302101234567`) are **different values**; add both if needed.
- At checkout, the buyer's email and phone numbers are checked against active *Disable payment methods only* entries.
- Customers whose email, profile phone or any address phone matches an active entry are flagged with a **Blacklisted**
  badge in the list and a banner on their page. Being blacklisted does not disable the customer.

{: .important }
> In the current version, a **Full block** entry flags the customer throughout the administration but does **not**
> stop checkout by itself. To keep a buyer away from a risky payment method such as cash on delivery, use **Disable
> payment methods only**, and disable the customer's account if needed.

## Removing an entry

**Remove from blacklist** lifts the entry. It is kept as history with its reason, who added it and who removed it; the
same value can be added again later.

## Permissions

*View the customer blacklist*, *Manage the customer blacklist*. Blacklisting from a customer's page also needs *View
customers*.
