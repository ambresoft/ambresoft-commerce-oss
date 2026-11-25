---
title: Menus
parent: Website
grand_parent: Administration guide
nav_order: 2
---

# Menus

**Website → Menus** (`/admin/menus`)

Menus are the navigation links of your storefront: the main menu, footer columns and so on. A menu is shown on the
storefront by a **Menu**, **Image menu** or **Link directory** block in [Layouts](../layouts/).

## Creating a menu

Click **Create** and enter the menu's **name** per language, its **purpose** (*Header*, *Footer* or *Other*, for your
own organization) and its status. Then choose its source:

| Source | Items |
| --- | --- |
| **Manual items** | Items you add yourself |
| **All categories** | The live category tree, including future categories (Uncategorized and disabled branches are left out) |

Switching from manual items to *All categories* asks for confirmation; your manual items are kept and come back if you
switch back.

## Menu items

Each manual item has:

| Field | Notes |
| --- | --- |
| **Name** | Per language; required in the default language |
| **Short description** | Optional plain text per language |
| **Target** | A **custom URL**, a **category**, or a **heading** without a link |
| **Parent** and **position** | Items can be nested to any depth |
| **Image** | Optional, with alternative text per language (for image menus) |
| **Open in a new window** | Optional |
| **Status** | Active or Disabled |

An item that targets a category can **generate its submenu** from that category's subcategories automatically. Such a
generated branch follows the catalog and cannot also contain manual children.

The editor shows one level at a time: open an item to manage its children. Deleting an item deletes everything below
it; deleting a menu deletes all its items. Both ask for confirmation.

## Permissions

*View website menus*, *Manage website menus*.
