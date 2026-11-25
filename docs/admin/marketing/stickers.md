---
title: Stickers
parent: Marketing
grand_parent: Administration guide
nav_order: 6
---

# Stickers

**Marketing → Stickers** (`/admin/stickers`)

Stickers are badges shown on product pictures and pages, such as *New*, *-20%*, *Free shipping* or *Only a few left*.

## Two kinds of stickers

| Kind | How products get it |
| --- | --- |
| **Manual** | You choose the products (in the sticker, up to 500, or in each product's **Stickers** tab) |
| **Dynamic** | Products get it automatically while they match its **conditions** |

Dynamic conditions (up to 12, combined with AND or OR) can test:

- the selling price, the discount percentage or the discount amount of an active product sale;
- the category (optionally including subcategories);
- feature values (choices, number ranges, yes/no);
- stock: in or out of stock, a quantity range, whether the product can be bought.

Dynamic stickers never change prices, stock or promotions; they only display.

## Creating a sticker

Click **Create** and work through the tabs:

| Tab | Settings |
| --- | --- |
| **General** | Internal name, status, schedule and kind |
| **Appearance** | Text or image style; background, text and border colours, border width, radius, padding, font size, alignment, image width |
| **Display** | Position (on the image top or bottom, above or below the price), where it appears (product cards and/or product pages), on which devices (phone, tablet, desktop), priority (lower first) |
| **Product Assignment** / **Conditions** | The products of a manual sticker, or the conditions of a dynamic sticker |

Every active language needs the display text (or alternative text for image stickers); a short text for product cards
and a tooltip are optional.

## How many stickers are shown

By default up to **3** stickers on product cards and **5** on product pages, counted per device. Change these limits
(0–20) on the Stickers screen.

## Permissions

*View stickers*, *Manage stickers and assignments*. Assigning stickers in a product also needs *Update products*.
