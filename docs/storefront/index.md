---
title: The storefront
nav_order: 4
has_children: true
permalink: /storefront/
---

# The storefront

The storefront is the public shop your customers use. This section explains what customers can do and which
administration screen controls each part, so you can answer customer questions and set the store up the way you want.

| Page | Covers |
| --- | --- |
| [Browsing and finding products](shopping/) | Homepage, categories and filters, brands, search, product pages, comparison, wishlists, reviews, reels |
| [Cart and checkout](cart-checkout/) | The cart, coupons and gift cards, checkout, payment, invoices, the confirmation page |
| [Customer accounts](accounts/) | Registration and sign-in, the account pages, order tracking, cancellations |

The storefront's look is built in **Website → Layouts** (see [Layouts](../admin/website/layouts/)), its colours in
**Settings → General settings → Storefront colors**, and its logo in **Settings → Company data**.

## Before you open the store

- While you build the store, close it in **Settings → General settings → Basic settings** (**Open storefront** off).
  Visitors see your closed-store message; you, signed in as staff, can still preview everything.
- Leave **Discourage search engine indexing (development)** on until launch, then **switch it off**.

## Languages on the storefront

Shoppers see the store in its default language and can switch to any active language with the language switcher. Each
product, category and page uses its translation in that language, or the default language when a translation is
missing.

## Speed and search engines

- Every page has its own title, description, canonical address and Open Graph data for social sharing; product pages
  publish price, availability and rating data that search engines can show in results.
- Private pages (cart, checkout, account, search results) are never indexed.
- The storefront content cache (see [Cache](../admin/system/cache/)) keeps pages fast without ever showing stale prices
  or stock.
