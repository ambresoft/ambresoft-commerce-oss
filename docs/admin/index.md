---
title: Administration guide
nav_order: 3
has_children: true
permalink: /admin/
---

# Administration guide

The administration is where you and your staff run the store. Open it at `/admin` on your store's address (for example
[localhost:8000/admin](http://localhost:8000/admin) on a local installation).

This guide follows the **menu on the left of the administration**, so you can find each screen where you see it.

| Menu | What you do there | Guide |
| --- | --- | --- |
| **Dashboard** | See sales, open orders, stock and recent activity at a glance | [Basics](basics/#the-dashboard) |
| **Orders** | All orders, call requests, order statuses, order email templates, invoice and packing-slip layouts | [Orders](orders/) |
| **Catalog** | Products, features, variation groups, availability statuses, product packages, categories, brands, reviews, carts | [Catalog](catalog/) |
| **Customers** | Customer accounts, customer groups, blacklist | [Customers](customers/) |
| **Users** | Staff users and their roles | [Staff users and roles](users-and-roles/) |
| **Reports** | Sales, orders, products, brands, categories, customers, inventory, promotions, popular searches | [Reports](reports/) |
| **Marketing** | Coupons, promotions, banners, gift cards, reels, stickers, newsletters, blog | [Marketing](marketing/) |
| **Website** | Layouts, menus, pages, FAQs, SEO rules, social login, code editor | [Website](website/) |
| **Payment methods** | Cash on delivery, bank deposit and payment add-ons | [Payment methods](payment-methods/) |
| **Shipping & Taxes** | Shipping methods, taxes, countries, regions, rate areas, carriers | [Shipping & Taxes](shipping-taxes/) |
| **Add-ons**, **Cache**, **Logs**, **Imports / Exports** | Extensions, the storefront cache, audit and AI logs, CSV files | [System](system/) |
| **Settings** | Company data, general settings, search, languages, translations, currencies | [Settings](settings/) |

{: .note }
> **You only see what you are allowed to use.** Each menu entry and each button depends on the permissions of your
> role. If something described here is missing for you, ask a Super Admin to add the permission to your role (see
> [Staff users and roles](users-and-roles/)).

## How the screens work

Most screens follow the same patterns, so once you know one you know them all.

- **Lists** show records in a table with a search box, filters and pages. Tick rows to use **bulk actions** (for
  example changing the status of many products at once). Click a row's name to open it.
- **Create** buttons (with a plus icon) are at the top right of a list.
- **Editors** split long forms into **tabs** (for example *General*, *SEO*, *Images*). Switching tabs never loses what you
  typed. Save once for all tabs.
- **Status** is a small coloured pill at the top of an editor (for example *Active* / *Disabled*). Click it to change
  the status.
- **Delete** and other rare actions are in the **three-dot menu** (⋮) at the top right of an editor. Deletions ask for
  confirmation.
- **Languages**: translatable editors show language codes (for example `EN` `EL`) in the tab bar. Click a code to edit
  that language. Your drafts in every language are kept until you save.
- **Required fields** are marked with a red asterisk (*).
- **Help**: a small question-mark icon next to a field or status opens an explanation.
- **Conflicts**: if someone else saved the same record while you were editing, you are told to reload instead of
  overwriting their changes.
- **History**: important changes are recorded in the [audit log](system/logs/) with who changed what and when.
