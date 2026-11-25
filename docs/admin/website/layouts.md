---
title: Layouts
parent: Website
grand_parent: Administration guide
nav_order: 1
---

# Layouts
{: .no_toc }

**Website → Layouts** (`/admin/layouts`)

Layouts define how every storefront page is built, without code.

1. TOC
{:toc}

---

## How a page is built: layout → grid → block

- A **layout** is one page type, for example the homepage, category pages or the cart.
- A layout is divided into **grids** on a **16-column** system. A row of grids can be, for example, 8 + 8 (two halves)
  or 4 + 12 (a sidebar and a main area). Grids can contain rows of smaller grids (up to eight levels).
- Each **block** fills the grid it is placed in: a banner carousel, a product carousel, a menu, some HTML…
- On **desktop** (1024 px and wider) the columns stay side by side. On **tablet** (768–1023 px) and **phone** (below
  768 px) grids stack vertically. Each placement can be shown or hidden per device.

## The Default layout and the other layouts

The **Default** layout holds the parts shared by every page: the **top bar**, **header**, **navigation**, the areas
**before and after the content** and the **footer**. Every other layout uses them and only defines its own **content**
area. The **Homepage** can additionally switch off a shared block or give it a different configuration for the homepage
only (reset to go back to the shared one).

Built-in layouts:

| Layout | Page |
| --- | --- |
| Default | Shared header, navigation and footer |
| Homepage | `/` |
| Product listing categories | Category pages that list products |
| Category landing pages | Categories marked as landing pages (subcategory cards) |
| Brand / feature landing pages | Brand pages such as `/apple` |
| Products | Product pages |
| Search results | `/search` |
| New products page, Best sellers page, Offers page | `/new`, `/bestsellers`, `/offers` |
| All brands | `/brands` |
| Sitemap | `/sitemap` (the visible store sitemap) |
| Blog, Blog post | Blog pages and posts |
| Cart, Checkout | `/cart`, `/checkout` |
| Account | The customer account pages |
| Wishlist, Comparison | `/wishlist`, `/comparison` |
| Order tracking | `/order-status` |

Add your own layout with **Add layout page…** (the plus next to the tabs). A merchant-added layout works like the
homepage and can be **linked to a page** from **Website → Pages**; the page is then served at its SEO name (for example
`/privacy-policy`).

## Editing a layout

1. Open the layout's tab.
2. **Add a grid**: choose its width in columns and its row. Grids in a row can use up to the width of their parent
   (16 at the top).
3. **Add a block** to a grid: the dialog offers **Create new** (choose the block type and template, such as a single item
   or a carousel) or **Use existing** (copy a block configured elsewhere).
4. Configure the block in its tabs (content and general settings), then **Apply**.
5. Reorder grids and blocks by dragging the grip, or focus the grip and use the arrow keys. Switch blocks on or off and
   choose the devices they appear on.
6. Click **Save layout**. Nothing is published until you save; if someone else saved the layout meanwhile, you are asked
   to reload.

Protected blocks (such as the store identity in the header, the main content of a page, the product filters of
category pages) cannot be deleted; some can be moved or switched off.

## Block types

| Block | Shows |
| --- | --- |
| **Banners** | Chosen banners, the latest banners or a banner group; single banner or carousel (1–4 banners side by side on desktop and tablet, always one on phones) |
| **Products** | Chosen products, newest, best sellers or products on sale (optionally only from some categories); single product or carousel |
| **Products by brand** | Products of one brand with the same sources |
| **Categories** | Chosen categories; single category or carousel |
| **Brands** | Chosen brands, best-selling brands or all brands alphabetically |
| **HTML content** | Your own rich text per language |
| **Menu**, **Image menu**, **Link directory** | Navigation menus, menus with pictures, columns of links |
| **Top bar**, **Footer** | The thin bar above the header, the store footer with links, social links, apps and payment icons |
| **Newsletter sign-up** | A subscription form for a mailing list |
| **Coupons**, **Offers** | Carousels of chosen coupons and offers (offers are automatic promotions); only currently available items appear |
| **Blog posts** | The latest posts (of every blog or chosen blogs); single post or carousel, optionally with date and excerpt |
| **Reels** | Store reels, chosen or by newest / most viewed |
| **Popular searches** | Pills linking to frequent or chosen searches (needs a search add-on) |
| **Store identity** | The store name and logo in the header (protected) |
| **Main content**, **Page heading**, **Product filters**, **Checkout header** | Protected blocks of specific page types |

## Layout page settings

The gear next to the active layout tab opens the **Layout page settings**:

- **Page title**, **meta description** and **meta keywords** per language. A page's own SEO (a product, category or
  page) always wins; these are the fallback.
- **Custom HTML** placed in the page `<head>` (for example a verification tag). Trusted markup: only staff who can manage
  layouts can set it.
- **Indexing**: switch it off to add `noindex, nofollow` to that page type.
- *Copy to other layouts* for the keywords and custom HTML.
- For merchant-added layouts: the **name** and the linked **page**.

## Permissions

*View storefront layouts*, *Manage storefront layouts*.
