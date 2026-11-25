---
title: Home
layout: home
nav_order: 1
description: Documentation for Ambresoft Commerce, the open-source B2C ecommerce platform.
permalink: /
---

# Ambresoft Commerce documentation
{: .fs-9 }

Everything you need to install, run and extend an Ambresoft Commerce store, written for store owners, staff and developers.
{: .fs-6 .fw-300 }

[Get started](getting-started/){: .btn .btn-primary .fs-5 .mb-4 .mb-md-0 .mr-2 }
[View on GitHub](https://github.com/ambresoft/ambresoft-commerce-oss){: .btn .fs-5 .mb-4 .mb-md-0 }

---

Ambresoft Commerce is a self-hosted, open-source ecommerce platform for **B2C online stores**: brands and retailers that
sell directly to consumers. Catalog, storefront designer, checkout, payments, shipping, taxes, marketing, reports and
AI writing tools ship as one product that runs on your own server. It is free software under the
[GNU Affero General Public License v3.0](https://github.com/ambresoft/ambresoft-commerce-oss/blob/master/LICENSE).

## Who this documentation is for

| You are… | Start here |
| --- | --- |
| **A store owner or staff member** who manages products, orders and content | [Administration guide](admin/) |
| **Setting up a store for the first time** | [Getting started](getting-started/) → [Set up your store](getting-started/first-steps/) |
| **Curious what your customers see** | [The storefront](storefront/) |
| **Turning on extra features** (Stripe, search, analytics, cookies…) | [Bundled add-ons](bundled-addons/) |
| **A developer** who installs, operates or extends the platform | [Developer guide](developers/) |
| **Looking up a URL, permission or command** | [Reference](reference/) |

{: .tip }
> You do not need to be technical to run a store. The [Administration guide](admin/) explains every screen in plain
> language, with step-by-step instructions. Technical terms are explained in the [Glossary](reference/glossary/).

## What is in the box

- **Catalog**: products with images, files and identifiers (SKU, EAN, MPN, ISBN, UPC), nested categories, brands,
  features and filters, variation groups, product packages, stock and availability statuses, reviews and stickers.
- **Storefront designer**: layouts built from a 16-column grid and blocks, a banner designer with separate desktop,
  tablet and phone layouts, menus, content pages, a blog, FAQs and a theme code editor.
- **Checkout**: guest and account checkout, saved addresses, invoice requests with VAT number checks, payments (cash on
  delivery, bank deposit, Stripe Checkout) and shipping (methods, carriers, rate areas).
- **Orders and customers**: custom order statuses, editable order emails, invoices and packing slips, shipments,
  customer accounts, groups, a blacklist and call-back requests.
- **Marketing**: promotions, coupons, gift cards, scheduled product sales, newsletters, banners and reels.
- **AI assistants** (with your own Anthropic API key): write banners, blog posts, newsletters and FAQs in every store
  language. You review and save; nothing goes live on its own.
- **Administration you can trust**: staff roles and permissions checked on the server, passkeys, a full audit log and
  reports.
- **Speed**: FrankenPHP application server, Typesense search, Redis for sessions, cache and queues, PostgreSQL for data.
- **Languages**: the administration and storefront ship in English, Greek, Spanish, French, Italian and Dutch, and
  every piece of store content can be translated per language.

## Getting help

- Questions and bug reports: [GitHub issues](https://github.com/ambresoft/ambresoft-commerce-oss/issues).
- Security problems: report them privately as described in the
  [security policy](https://github.com/ambresoft/ambresoft-commerce-oss/blob/master/SECURITY.md), never in a public issue.
- Contributing: see [CONTRIBUTING.md](https://github.com/ambresoft/ambresoft-commerce-oss/blob/master/CONTRIBUTING.md).
