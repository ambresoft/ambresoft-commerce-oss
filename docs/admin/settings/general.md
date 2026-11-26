---
title: General settings
parent: Settings
grand_parent: Administration guide
nav_order: 2
---

# General settings
{: .no_toc }

**Settings → General settings** (`/admin/settings/general`)

Store-wide defaults. Individual products can override some of them where noted. All tabs are saved together with one
**Save settings**; if someone else saved meanwhile, you are asked to refresh.

1. TOC
{:toc}

---

## Basic settings

| Setting | Notes |
| --- | --- |
| **Open storefront** | Switch off to close the store to visitors: they see the **Closed-store message** (per language). Verified staff with administration access can still preview the store. The administration keeps working |
| **Discourage search engine indexing (development)** | Adds `noindex, nofollow` to the storefront. **Switch it off when the store goes live** |
| **Accepted phone numbers** | *Digits, spaces and + ( ) - .* or *Digits only*, for customer and address phone fields |
| **Weight unit** and **Grams per weight unit** | For example `kg` and `1000`. Changing the factor converts every product weight |
| **Administration preferences** | Warn about unsaved changes, show menu descriptions, how long success messages stay (0 = until closed) |

## Appearance & calendar

| Setting | Notes |
| --- | --- |
| **Date display format**, **Time display format** | How dates and times are shown |
| **Store timezone** | Used for dates, reports, schedules and "whole day" date ranges |
| **Calendar date format**, **Calendar week starts on** | For date pickers (Monday or Sunday) |
| **Maximum site width (px)** | Width of the storefront content (320–3840, default 1320) |
| **Show Back to top button** | A storefront button that scrolls back to the top |

## Catalog

| Setting | Notes |
| --- | --- |
| **Product card style** | *Classic*, *Showcase* or *Signature* |
| **Blend product picture backgrounds** | Product pictures with a white background take the light grey of the area behind them |
| **Hide out-of-stock products from listings** | Products with no stock leave carousels, blocks and category listings; their own page stays reachable. Needs inventory tracking |
| **Hide prices on the storefront** | Hides all product prices and *Add to cart* controls; optional **Message shown instead of the price** per language. The administration still shows prices |
| **Disable cart and checkout** | Removes the cart icon and closes the cart and checkout; saved carts are kept |
| **Require customers to log in or register before placing an order** | Off (default): guests can check out with an email address. Shown while the cart is enabled |
| **Show prices without VAT** | Storefront prices always include VAT; this also shows the price without VAT beneath them (product pages, cards and cart) |
| **New products period (days)** | Newest-product blocks show products created within this many days (1–3650, default 90) |
| **Allow customers to compare products**, **Products compared at once** | Product comparison, 2–10 products at once (default 4) |
| **Enable wishlists** | Saved lists are kept while switched off |
| **Enable product reviews and ratings** | Store-wide switch; products also have their own switch |
| **Best sellers** | **Sales period (days)** (0 = all history), **Minimum units sold**, **Minimum paid orders**, **Exclude categories from best sellers** |
| **Brand landing pages** | Use dedicated brands (default) or feature values for brand pages. Changing it needs an extra permission and an SEO confirmation, because page addresses change |
| **Reels period (days)** | Period for the *Newest* and *Most viewed* reels blocks (1–3650, default 90) |

## Checkout

| Setting | Notes |
| --- | --- |
| **Invoice requests** | Let business customers request an invoice after their **VAT number** is verified |

The VAT check is chosen separately for **Greek companies** and **Other countries**:

| Method | Notes |
| --- | --- |
| **VIES** (free, default) | The European Commission's service for EU VAT numbers. Does not cover non-EU countries |
| **AADE** (Greek companies) | The AADE business registry service. Needs the **special** AADE service username and password (never your TAXISnet password); the customer must be signed in |
| **Greek AFM checksum only** (free) | Checks only the format of the number, not that the business exists |
| **VAT Sense** (other countries) | Your own VAT Sense API key; covers non-Greek EU members, Great Britain, Australia, Norway, Switzerland, South Africa and Brazil |

A failed or unavailable check never approves an invoice request; the customer can still order with a receipt.
Credentials are stored encrypted and never shown again.

## Inventory

| Setting | Notes |
| --- | --- |
| **Track inventory** | On by default. Off: stock is not checked or moved (quantities are kept) |
| **When out of stock** | *Do not allow purchase* or *Allow backorders (buy in advance)*. Products follow this unless they have their own value; the screen shows how many products override it |
| **Low-stock threshold** | Products at or below it are labelled *Low stock* in lists and reports |

## Coupons

| Setting | Notes |
| --- | --- |
| **Enable coupons and gift cards in the cart** | Shows the code field in the cart |
| **Allow only one coupon per order** | On: a second coupon is refused. Off: a new coupon replaces the first |

## Tax calculation

| Setting | Notes |
| --- | --- |
| **Tax calculation method** | **Unit price** (tax each unit, then multiply) or **Subtotal** (tax the sum of lines with the same taxes) |
| **Show catalog prices with tax**, **Show cart and checkout prices with tax** | Display options |
| **Estimate taxes for customers without an address** | Uses the **Default country** and **Default region code** until the customer enters an address |

## Emails

| Setting | Notes |
| --- | --- |
| **Send order emails from**, **Sender name**, **Reply-to address** | The sender of order, gift card and store emails |
| **Send emails** | **via PHP mail function** (default), **via SMTP server** (host, port, encryption None/TLS/SSL, optional username and password) or **via sendmail program** (an absolute path to a sendmail-compatible program) |

The SMTP password is encrypted and never shown again. To test your settings, use **Send a test email** in any order email
template.

{: .tip }
> On a local installation all emails are caught by Mailpit at [localhost:8025](http://localhost:8025) regardless of
> these settings' recipients.

## Storefront colors

**Primary color** (buttons, active dots, focus outlines), **Primary hover color**, **Text on primary color**, **Text
color**, **Page background**, **Sale color** (old prices, unavailable products) and the **Sign-in and registration
header** colour. **Use default** resets a colour.

## AI settings

| Setting | Notes |
| --- | --- |
| **Enable AI generation** | Turns on the AI assistants (banners, blog posts, newsletters, FAQs). Manual editing always works |
| **Anthropic API key** | Your own key from Anthropic. Stored encrypted, never sent to the browser |
| **Claude model** | The model used for generation |
| **Maximum output tokens per call** | Limits the length (and cost) of each answer |
| **Generations per user per hour** | A per-staff-member limit |
| **Generations per day for the whole store** | Shared by all staff over the last 24 hours (default 200). Every request to the AI provider counts, including failed ones |

Usage is listed in **Logs → AI log**. Input tokens are also billed by Anthropic.

## Permission

*Manage general and company settings* (`settings.manage`). Changing the brand landing-page source also needs *Change
brand landing-page source*.
