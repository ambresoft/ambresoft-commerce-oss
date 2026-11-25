---
title: Google Analytics
parent: Bundled add-ons
nav_order: 5
---

# Google Analytics

Adds **Google Analytics 4** to the public storefront pages, with optional ecommerce tracking.

## Setting it up

1. **Add-ons**: install and enable **Google Analytics**, then open its **Settings**.
2. **Google Analytics measurement ID**: your GA4 ID (`G-XXXXXXXXXX`), found in Google Analytics under **Admin → Data
   streams**. Old Universal Analytics IDs (`UA-…`) no longer collect data and are refused.
3. **Track e-commerce transactions** (optional): sends *add to cart*, *remove from cart* and *add to wishlist* from the
   storefront, and every placed order as a **purchase**.
4. For purchases, enter a **Measurement Protocol API secret** (Google Analytics → Admin → Data streams → your stream →
   Measurement Protocol API secrets). Purchases are sent by the server, so no tracking script runs on checkout pages.
5. Restart the background services: `docker compose restart worker scheduler`. The worker must keep running to send
   purchases.

## Where it runs

Only on public storefront pages: never on account, checkout, payment, order tracking or sign-in pages, and never in the
administration. Events contain product data, never personal data.

When the [GDPR Cookies](../gdpr-cookies/) add-on is enabled, Google Analytics loads only after the visitor consents.
