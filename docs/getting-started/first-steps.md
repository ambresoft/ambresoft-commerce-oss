---
title: Set up your store
parent: Getting started
nav_order: 3
---

# Set up your store

A checklist for a new store, in the order that avoids rework. Each step links to its guide.

## 1. Keep the store closed while you work

**Settings → General settings → Basic settings**: switch **Open storefront** off and keep **Discourage search engine
indexing (development)** on. You can still preview the store while signed in. See [General settings](../../admin/settings/general/).

## 2. Business basics

- [ ] **Company data**: name, address, contact emails, tax details, logo, favicon. → [Company data](../../admin/settings/company/)
- [ ] **Languages**: activate your languages and choose the default. → [Languages](../../admin/settings/languages/)
- [ ] **Currency**: check the primary currency and its decimals **before entering prices**. → [Currencies](../../admin/settings/currencies/)
- [ ] **General settings**: timezone, date formats, weight unit, inventory rules, emails (sender and delivery method).
  → [General settings](../../admin/settings/general/)
- [ ] **Send a test email** from an order email template to check that emails arrive.
  → [Email templates](../../admin/orders/email-templates/)

## 3. Staff

- [ ] Invite your staff and give them roles. → [Staff users and roles](../../admin/users-and-roles/)

## 4. Where you sell and how you charge

- [ ] **Countries** you ship to, and their **regions**. → [Countries and regions](../../admin/shipping-taxes/countries-regions/)
- [ ] **Rate areas** and **taxes**, and the tax calculation settings. → [Taxes and rate areas](../../admin/shipping-taxes/taxes/)
- [ ] **Shipping methods** with their rates. → [Shipping methods](../../admin/shipping-taxes/shipping-methods/)
- [ ] **Payment methods**: cash on delivery, bank deposit (with your IBAN), Stripe. → [Payment methods](../../admin/payment-methods/)
- [ ] **Order statuses** and the **customer emails** of each status. → [Order statuses](../../admin/orders/order-statuses/)

## 5. Catalog

- [ ] **Categories** → **features** → **brands** → **products** (or import them from CSV).
  → [Catalog](../../admin/catalog/), [Imports / Exports](../../admin/system/imports-exports/)
- [ ] **Variation groups** for products sold in several colours or sizes. → [Variation groups](../../admin/catalog/variation-groups/)
- [ ] **Availability statuses** wording. → [Availability statuses](../../admin/catalog/availability-statuses/)

## 6. Storefront

- [ ] **Storefront colours**. → [General settings](../../admin/settings/general/#storefront-colors)
- [ ] **Menus** for the header and footer. → [Menus](../../admin/website/menus/)
- [ ] **Layouts**: homepage, header, footer, category and product pages. → [Layouts](../../admin/website/layouts/)
- [ ] **Pages**: about us, privacy policy, terms, shipping and returns, each linked to a layout.
  → [Pages](../../admin/website/pages/)
- [ ] **Banners** and other content. → [Banners](../../admin/marketing/banners/)

## 7. Extras

- [ ] **Add-ons**: search (Typesense), sitemap, analytics, cookie consent, product feeds…
  → [Bundled add-ons](../../bundled-addons/)
- [ ] **Google sign-in** for customers. → [Social login](../../admin/website/social-login/)
- [ ] **Cache**: switch on the storefront content cache. → [Cache](../../admin/system/cache/)

## 8. Test, then open

- [ ] Place test orders as a guest and as a customer, with each payment and shipping method.
- [ ] Check the emails customers receive.
- [ ] Read [Going to production](../production/).
- [ ] **Open storefront** on and **Discourage search engine indexing** off.
