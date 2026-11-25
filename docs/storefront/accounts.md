---
title: Customer accounts
parent: The storefront
nav_order: 3
---

# Customer accounts
{: .no_toc }

1. TOC
{:toc}

---

## Registering and signing in

- **Register** at `/register` with first name, last name, email and a password of at least 8 characters, then confirm
  the email address with the link sent by email.
- **Sign in** at `/login`. **Forgot password?** sends a reset link valid for 60 minutes.
- **Continue with Google** appears when Google sign-in is configured in **Website → Social login**.
- Customers you created in the administration can receive an **invitation** to set their password (see
  [All customers](../../admin/customers/customers/#inviting-a-customer-to-an-online-account)).

Disabled customers cannot sign in. Staff sign in separately at `/admin/login`.

## The account pages

Signed-in customers reach their account at `/account`:

| Page | What customers can do |
| --- | --- |
| **Details** (`/account`) | Change their name and phone (the email is read-only), and delete their account |
| **Orders** (`/account/orders`) | See their orders, open an order, download the **order summary** once it was sent, and cancel an order when allowed |
| **Addresses** (`/account/addresses`) | Add, edit and delete addresses, choose default billing and shipping addresses, and add delivery details (floor, bell name, notes) |
| **Reviews** (`/account/reviews`) | See their reviews (published, awaiting approval, not published) and review products they bought |
| **Newsletters** (`/account/newsletters`) | See and leave their mailing lists |
| **Password** (`/account/password`) | Change the password (current password required; other sessions are signed out) |

The account's look is the **Account** layout in **Website → Layouts**.

### Cancelling an order

A customer can cancel an order from the order page, or from the link in status emails, while the order is in a status
with **Customer can cancel** (*Open* and *Processing* by default) and nothing has been paid. The order then moves to
*Cancelled*, stock is returned and the cancellation email is sent.

### Deleting the account

The customer types their email address to confirm. The account is closed and they are signed out; their orders,
addresses and reviews are kept and staff see the profile as *Deleted*. Staff can bring it back by activating the
profile.

## Order tracking

`/order-status` lets anyone look up an order by its number:

- a **guest order** shows its number, date, current status and status history;
- a **customer's order** asks the visitor to sign in first, and shows only to its owner.

Names, addresses, products and amounts never appear on this page.
