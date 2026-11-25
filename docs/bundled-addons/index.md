---
title: Bundled add-ons
nav_order: 5
has_children: true
permalink: /bundled-addons/
---

# Bundled add-ons

These add-ons ship with the platform in the `modules/ambresoft/` folder. They are installed **disabled** (by
`php artisan cms:addons-sync`); you choose which ones to enable in **Add-ons**.

| Add-on | What it does |
| --- | --- |
| [Stripe Checkout](stripe-checkout/) | Card payments (and other methods Stripe offers) on Stripe's hosted page |
| [Typesense search](typesense/) | Fast, typo-tolerant product search with suggestions and filters |
| [Sitemap](sitemap/) | XML sitemaps for search engines, with review and scheduled publication |
| [Facebook/Google XML](product-feeds/) | Product feeds for Google Merchant Center and Meta (Facebook, Instagram) catalogs |
| [Google Analytics](google-analytics/) | GA4 on public storefront pages, with ecommerce events and purchases |
| [Google Tag Manager](google-tag-manager/) | Your GTM container on public pages, with shopper events in the data layer |
| [GDPR Cookies](gdpr-cookies/) | A cookie consent banner, categories, cookie declarations, consent-gated scripts and a consent log |
| [Abandoned cart reminders](cart-reminders/) | Emails to signed-in customers who left products in their cart |
| [Black Friday Theme](black-friday-theme/) | A dark storefront palette with gold accents |

## Enabling an add-on

1. Open **Add-ons**, find the add-on and click **Install** (if it is not installed yet), then **Enable**.
2. Configure it: enabled add-ons show links to their screens on the Add-ons page (settings, payment method, search,
   sitemap…).
3. New add-on permissions are given to nobody: grant them to the right roles in **Users → Roles** (Super Admins have
   them automatically).
4. Ask the server operator to restart the background services: `docker compose restart worker scheduler`.

See [Add-ons](../admin/system/addons/) for the full lifecycle (disable, upgrade, uninstall).
