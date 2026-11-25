---
title: Key concepts
parent: Getting started
nav_order: 4
---

# Key concepts

A short tour of the ideas the rest of the documentation relies on. Every term is also in the
[Glossary](../../reference/glossary/).

## Administration and storefront

- The **storefront** is the public shop at your store's address.
- The **administration** (`/admin`) is where staff manage the store. Staff accounts and customer accounts are separate:
  a customer account never opens the administration.

## Products and how they are described

- A **product** is something you sell, with its own SKU, price, stock, images and page. It is **physical** or
  **digital**.
- A **category** organizes products in a tree. A product has one **main category** (which sets its address) and can be
  listed in **additional categories**.
- A **feature** is a characteristic shared by many products, such as *Colour* or *Storage*. Features are shown in
  specifications and can be used as filters.
- A feature of a choice type has predefined **options** (values), such as *Black*, *White* and *Blue* for *Colour*.
- A **variation group** links products that are versions of the same item. Each version is still a complete, separate
  product. The group's **defining features** distinguish the versions, and each version's set of option values (for
  example *Black + 256 GB*) is its **combination**, which must be unique in the group.

## Prices, discounts and taxes

- Prices are entered in the **primary currency**, with exact decimals (never rounded floating-point numbers).
- A **sale** (in the product) lowers a product's price automatically, optionally for a customer group, minimum quantity,
  period or limited stock.
- A **promotion** discounts orders automatically when conditions match; a **coupon** needs a code; a **gift card** is a
  way of paying, not a discount.
- **Taxes** have rates per **rate area** (a group of countries or regions) and are either included in your prices or
  added on top.

## Customers

- A **customer profile** holds a customer's details and addresses; it may be linked to an online **account**.
- **Customer groups** segment customers for visibility, prices and shipping.

## Orders

- An **order status** (Open, Processing, Shipped…) is the step an order is in. Each status decides what happens to
  stock and which email the customer receives.
- The **payment status** (Not paid, Partially paid, Paid…) is separate and comes from the payments actually received.

## Who can do what

- Staff have **roles**; roles have **permissions**. Every page and action is checked on the server.
- The **Super Admin** role has every permission.

## Extending the store

- **Add-ons** add features (payments, search, analytics…). They are installed disabled and switched on in **Add-ons**.
- **Layouts** build each storefront page from **grids** and **blocks**, without code.
