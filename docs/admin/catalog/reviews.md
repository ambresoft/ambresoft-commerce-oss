---
title: Reviews
parent: Catalog
grand_parent: Administration guide
nav_order: 8
---

# Reviews

**Catalog → Reviews** (`/admin/reviews`)

Signed-in customers can rate products (1 to 5 stars) and write a review. Every new review waits for moderation:
nothing appears on the storefront until a staff member approves it.

## Review statuses

| Status | Meaning |
| --- | --- |
| **Pending** | New, waiting for moderation |
| **Approved** | Published: shown on the product page and counted in its rating |
| **Rejected** | Not published |
| **Spam** | Not published, marked as spam |

Allowed changes: Pending → Approved, Rejected or Spam; Approved → Rejected or Spam; Rejected → Spam or Pending;
Spam → Pending.

## Moderating

- Open a review to read it, change its status and keep an **internal note** (never shown publicly).
- Correct a review's rating, title or text if needed (for example to remove personal data). The correction is recorded.
- **Bulk**: tick reviews on the current page (up to 100) and Approve, Reject or mark them as Spam. Each review is checked
  on its own; the result shows how many changed and why others did not.
- Delete a review permanently (with confirmation).

## Verified purchase

A review gets the **verified purchase** badge when the customer has a **paid** order, not cancelled or failed, that
contains exactly this product. The check runs when the review is written and again when it is approved. Placing an
unpaid order, a guest order or a cancelled order does not count.

## Rules for customers

- One review per customer per product.
- Rating 1–5, optional title (up to 150 characters), text required (up to 5,000 characters, plain text).
- On the storefront, the author is shown as first name and initial (for example "Maria E."), never the full name or
  email.
- Reviews can be switched off per product in its **Additional** tab (**Allow reviews and ratings**) or for the whole
  store.

## Permissions

*View product reviews*, *Moderate product reviews*, *Correct review ratings and text*, *Delete product reviews*. The
customer's email is shown only to staff who can view customers.
