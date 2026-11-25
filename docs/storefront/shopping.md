---
title: Browsing and finding products
parent: The storefront
nav_order: 1
---

# Browsing and finding products
{: .no_toc }

1. TOC
{:toc}

---

## The homepage and other pages

The homepage, header, menus and footer are exactly what you build in **Website → Layouts**. Built-in pages:

| Address | Page |
| --- | --- |
| `/` | Homepage |
| `/new`, `/bestsellers`, `/offers` | New products, best sellers, products on sale |
| `/brands` | All brands, alphabetically |
| `/sitemap` | The visible store sitemap: information pages and the category tree |
| `/{brand code}` | A brand's page, for example `/apple` |
| Category path | A category, for example `/phones/smartphones` |
| Category path + product | A product, for example `/laptops/business-laptops/nimbus-pro-16` |
| `/{page SEO name}` | A content page linked to a layout, for example `/privacy-policy` |
| `/blog/…` | Blogs and posts |

Shoppers only see products, categories and pages that are active and allowed for them (customer groups, availability
periods).

## Category pages

A category page lists every visible product of the category and its subcategories, with:

- **filters**: price range, brand, the features marked *Use in product filters*, shopping options (*New*, *On sale*,
  *Best sellers*), delivery time (availability label) and, if switched on in the filters block, rating and in stock.
  Several values of one filter combine with OR, different filters with AND, and every value shows how many products it
  would give;
- **sorting** (recommended, price, newest, rating) and pagination;
- the category's banner, description, FAQs and linked blog posts.

A category marked as **landing page** shows its subcategories as cards instead of products. An **age-restricted**
category first asks the visitor to confirm their age; the confirmation is remembered for a year.

## Search

When the [Typesense search add-on](../../bundled-addons/typesense/) is enabled, the header shows a search box:

- **suggestions** appear while typing (after the minimum number of characters), keyboard friendly;
- the results page (`/search?q=…`) has the same filters as categories and sorts by relevance, price, newest or rating;
- typos are tolerated (except in product codes) and every active language is searched.

## Product pages

A product page shows the gallery, name, price (with sale and list price), availability label, stock rules, the
quantity selector and **Add to cart**, and depending on the product:

- variation selectors (colours, sizes…) that switch between the versions of a [variation group](../../admin/catalog/variation-groups/);
- the specifications table (features), description, attachments (size guides, manuals) and brand documents;
- stickers, reviews and ratings, FAQs;
- **similar products**, **frequently bought together** with a live total, and a product package of accessories;
- round buttons beside the picture: wishlist, comparison and share.

When **Hide prices on the storefront** is on, prices and purchase buttons are replaced by your message. When the cart is
disabled, products are shown without purchase buttons.

## Comparison

Shoppers add products to the comparison from product pages and listing cards, then open `/comparison`: products as
columns with a feature table, switchable to show all rows, only the differences or only the similarities. The list is
kept for the visit (also for guests) and survives signing in. Limits and the on/off switch are in **General settings →
Catalog**.

## Wishlists

Signed-in customers save products with the heart icon: every saved product is in **All favourites**, and customers can
create up to 50 named lists. `/wishlist` shows them. Guests are asked to sign in. Wishlists can be switched off in
**General settings → Catalog** (lists are kept).

## Reviews and ratings

Signed-in customers can review products they are allowed to see; reviews appear after a staff member approves them (see
[Reviews](../../admin/catalog/reviews/)). Paid purchases get a *verified purchase* badge.

## Reels, banners and blog

Reels blocks open a video with the linked products beside it. Banners, blog posts, coupons and offers appear wherever you
placed their blocks.

## Call requests and newsletters

- If enabled in the header's identity block, a **phone icon** lets visitors ask to be called back (see
  [Call requests](../../admin/orders/call-requests/)).
- A **newsletter sign-up** block subscribes visitors to a mailing list, with a confirmation email when the list requires
  it.
