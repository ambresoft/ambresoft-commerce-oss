---
title: Google Tag Manager
parent: Bundled add-ons
nav_order: 6
---

# Google Tag Manager

Loads your **Google Tag Manager** container on the public storefront pages and pushes shopper events to the data layer,
so you can manage tags (analytics, ads, remarketing) in GTM.

## Setting it up

1. In Google Tag Manager, create a container for your website and copy its id (`GTM-XXXXXXX`).
2. **Add-ons**: install and enable **Google Tag Manager**, then open its **Settings**.
3. Enter the **Google Tag Manager container id**.
4. Optionally change the **Data Layer** name (default `dataLayer`).
5. Choose which events to send and their names:
   - *Add to cart*, *Remove from cart*, *Wish list*, *Comparison list*, *Call request*.
6. Save.

Check in your browser (Tag Assistant or the console) that the data layer receives the events after the matching actions.

## Where it runs

Only on public storefront pages. Private pages (account, checkout, payment, order tracking, sign-in) never load the
container; moving from a public page to a private one reloads the page. Events carry product data (id, name, SKU, price,
quantity, currency), never personal data.

When the [GDPR Cookies](../gdpr-cookies/) add-on is enabled, the container loads only after the visitor consents.
