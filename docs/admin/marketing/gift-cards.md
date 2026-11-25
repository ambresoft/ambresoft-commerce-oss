---
title: Gift cards
parent: Marketing
grand_parent: Administration guide
nav_order: 4
---

# Gift cards

**Marketing → Gift cards** (`/admin/gift-cards`)

A gift card is a prepaid card with a balance that a customer uses to **pay** for an order. It is not a discount: the
order's prices and taxes stay the same, and the card's value is recorded as a payment.

## Statuses

| Status | Meaning | Can be used |
| --- | --- | --- |
| **Pending** | Issued but not released yet | No |
| **Active** | The code works | Yes |
| **Cancelled** | Withdrawn by staff | No |
| **Used** | The balance reached zero (set automatically) | No |

You can move a card between Pending, Active and Cancelled. A used card is final.

## Issuing a card

1. Click **Create** and enter the amount (in the primary currency), the recipient and the email language.
2. A unique code like `GC-XXXX-XXXX-XXXX` is generated.
3. Tick **Notify customer** to email the card with its code when it is created active or becomes active.
4. Save.

**Send email** resends an active card. Cancelling with *Notify customer* sends a notice showing only the last part of
the code. Emails use the same sender and header/footer as order emails. The **History** tab shows every use and every
email, including failed ones.

## Using a card

- **Customers** enter the card code in the cart.
- **Staff** enter the code on an order (with the *Record payments* permission).

The smaller of the card's balance and the amount due is taken. Cards are never converted between currencies and cannot
pay an order in another currency. Cancelling an order later does **not** give the balance back.

## Editing and deleting

The amount can be changed only while the card has never been used. Deleting archives the card: it can no longer be used,
but its payments and history stay readable, and its code stays reserved.

## Permissions

*View gift cards and their history*, *Issue and edit gift cards*, *Delete gift cards*. Applying a card on an order also
needs *Record payments and share customer payment links*.
