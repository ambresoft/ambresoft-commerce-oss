---
title: Availability statuses
parent: Catalog
grand_parent: Administration guide
nav_order: 4
---

# Availability statuses

**Catalog → Products → Availability statuses** (`/admin/availability-statuses`)

Availability statuses are the **labels** customers see about delivery time, such as *Immediately available* or
*Available in 4–6 days*. They are text only: they do not change stock, prices or whether a product can be bought.

## How a product's label is chosen

Every product has two labels: one used while it is **in stock** and one used while it is **out of stock**. In the
product editor (**Pricing & inventory**) you can choose them per product; empty means "use the store default".

- With inventory tracking on, a product with a positive quantity shows its in-stock label, otherwise its out-of-stock
  label.
- With inventory tracking off, the in-stock label is always used.

## The labels you start with

*Immediately available*, *Awaiting supplier*, *Available in 1–3 days*, *Available in 4–6 days*, *Unavailable* and
*Pre-order*. The defaults are **Immediately available** (in stock) and **Unavailable** (out of stock).

## Managing labels

- **Add** a label with a name in **every active language** (up to 250 characters).
- **Rename** and **reorder** labels.
- Choose the **default** for in stock and for out of stock. There is always exactly one default for each; to change it,
  make another label the default. One label can be the default for both.
- **Delete**: if products use the label (or it is a default), choose a **replacement**; all products move to it at once.

## Permission

*Manage availability statuses* (`availability-statuses.manage`). Choosing labels on a product needs *Update products*.
