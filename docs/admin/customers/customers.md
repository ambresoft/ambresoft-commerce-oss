---
title: All customers
parent: Customers
grand_parent: Administration guide
nav_order: 1
---

# All customers
{: .no_toc }

**Customers → All customers** (`/admin/customers`)

1. TOC
{:toc}

---

## Customer profiles and online accounts

A **customer profile** holds a customer's name, email, phone, addresses, groups and notes. A customer may also have an
**online account** to sign in to the storefront. The two are linked automatically:

- when someone registers on the storefront and verifies their email, a profile is created (or an existing profile with
  the same email, for example one you created, is claimed, keeping its addresses, groups and orders);
- you can create a profile yourself (for example for a phone order) and later **invite** the customer to create their
  online account.

The **Online account** card on a customer's page and the list filter show whether the profile is linked, invited or
has no account.

## The list

- **Search** by name, email or phone.
- **Filter** by status, customer group, online account and blacklist.
- **Sort** by created date, name or email.

## Creating a customer

Click **Create customer** and fill in:

| Field | Notes |
| --- | --- |
| **First name**, **Last name** | Required |
| **Email** | Required and unique; stored in lower case |
| **Phone** | Optional |
| **Customer groups** | Leave as proposed to join the default group, or choose groups (an empty choice means no group) |
| **Tax exempt** | Exempt customers pay no tax: added taxes are not charged and taxes included in prices are removed |
| **Internal notes** | Visible to staff only |
| **Add an address now** | Optional first address; it becomes the default billing and shipping address |

## Addresses

A customer can have any number of addresses. Each has an optional **Label** (for example *Home*), company, two address
lines, city, postal code, country, region, phone and delivery details (floor, bell name and notes).

- The first address becomes the default for billing and shipping. Use **Use for billing** / **Use for shipping** to
  change the defaults.
- Deleting a default address makes the oldest remaining address the default.
- The **Region** is used to match tax [rate areas](../../shipping-taxes/taxes/#rate-areas).

## Inviting a customer to an online account

On an active customer without an online account, use the invitation action. A confirmation explains which email
receives the invitation and that it gives access to a **storefront customer account**, never to the administration.
The link is valid for **7 days** and works once; the customer chooses a password (8 to 128 characters) and is signed in.

## Disabling and restoring

**Disable customer** keeps the record and addresses but stops the customer from signing in (open sessions end).
**Restore customer** makes it active again. Customers cannot be deleted by staff.

When customers delete their own account from the storefront, the profile is marked **Deleted** (and disabled); their
orders, addresses and reviews are kept and the email stays reserved. Activating the profile brings the account back.

## Blacklisting a customer

**Blacklist this customer** adds the customer's stored email and/or phone to the [Blacklist](../blacklist/) with a
required reason.

## Account security

Super Admins see the customer's sign-in history (IP addresses, browsers, failed passwords) through the account
security link. See [Basics](../../basics/#account-security-history-super-admins).

## Permissions

| Permission | Allows |
| --- | --- |
| *View customers* | See the list and profiles |
| *Create customers* | Create profiles |
| *Edit customers and their addresses* | Edit profiles, groups, tax exemption, notes, addresses; send invitations |
| *Disable and restore customers* | Change the status |
