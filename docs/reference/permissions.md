---
title: Permissions
parent: Reference
nav_order: 2
---

# Permissions
{: .no_toc }

Every capability you can grant to a role in **Users → Roles**, with the label the role editor shows. Generated from the
permission registry: 145 permissions, 137 in core and 8 from the bundled Sitemap, Facebook/Google XML and GDPR Cookies
add-ons (those exist only while the add-on is enabled).

- A **Super Admin** has every registered permission automatically.
- New permissions are granted to **nobody** until a Super Admin adds them to a role.
- Showing a menu entry and allowing an action are separate checks: for example, viewing products (`products.view`) does
  not allow editing them (`products.update`).

1. TOC
{:toc}

---

## Catalog

| Permission | What it allows |
| --- | --- |
| `brands.view` | View brands |
| `brands.manage` | Manage brands |
| `brands.import` | Import brands by CSV |
| `products.import` | Import products by CSV |
| `categories.import` | Import categories from CSV |
| `products.status` | Change product status / bulk status |
| `products.trash-view` | View product Trash |
| `products.restore` | Restore products |
| `products.force-delete` | Permanently delete products |
| `features.view` | View features |
| `features.create` | Create features |
| `features.update` | Edit features and options |
| `features.status` | Activate, archive and restore features |
| `features.delete` | Permanently delete features |
| `features.groups` | Manage Feature Groups |
| `features.order` | Order features and groups |
| `reviews.view` | View product reviews |
| `reviews.moderate` | Moderate product reviews |
| `reviews.edit` | Correct review ratings and text |
| `reviews.delete` | Delete product reviews |
| `carts.view` | View carts |
| `availability-statuses.manage` | Manage availability statuses |
| `products.view` | View products |
| `products.create` | Create products |
| `products.update` | Update products |
| `products.delete` | Delete products |
| `categories.view` | View categories |
| `categories.create` | Create categories |
| `categories.update` | Update categories |
| `categories.delete` | Delete categories |

## Orders

| Permission | What it allows |
| --- | --- |
| `orders.create` | Create orders |
| `orders.status` | Change order statuses |
| `orders.notify` | Retry failed order emails |
| `orders.customer-ip` | See customer IP addresses |
| `orders.payments` | Record payments and share customer payment links |
| `orders.shipments` | Create and manage carrier shipments and pickup lists |
| `call-requests.view` | View call requests |
| `call-requests.manage` | Create and manage call requests |
| `call-requests.delete` | Delete call requests |
| `order-statuses.manage` | Manage order statuses |
| `order-emails.manage` | Manage order email templates |
| `orders.view` | View orders |
| `orders.update` | Update orders |

## Customers

| Permission | What it allows |
| --- | --- |
| `customers.view` | View customers |
| `customers.create` | Create customers |
| `customers.update` | Edit customers and their addresses |
| `customers.status` | Disable and restore customers |
| `customer-groups.view` | View customer groups |
| `customer-blacklist.view` | View the customer blacklist |
| `customer-groups.manage` | Manage customer groups |
| `customer-blacklist.manage` | Manage the customer blacklist |

## Website

| Permission | What it allows |
| --- | --- |
| `theme-code.manage` | Edit frontend theme source (trusted code) |
| `layouts.view` | View storefront layouts |
| `layouts.manage` | Manage storefront layouts |
| `cache.manage` | Manage storefront cache |
| `menus.view` | View website menus |
| `menus.manage` | Manage website menus |
| `pages.view` | View pages |
| `blog.view` | View blogs |
| `seo-rules.view` | View SEO rules |
| `pages.manage` | Manage pages |
| `blog.manage` | Manage blogs and posts |
| `seo-rules.manage` | Manage SEO rules (redirects) |
| `faqs.view` | View and link FAQs |
| `faqs.manage` | Manage FAQ content |
| `faqs.generate` | Generate FAQ drafts with AI |
| `blog.generate` | Write blog posts with AI |
| `social-login.view` | View social login providers |
| `social-login.manage` | Manage social login providers and credentials |

## Marketing

| Permission | What it allows |
| --- | --- |
| `reels.view` | View reels |
| `reels.manage` | Manage reels |
| `reels.analytics` | View reel viewers and viewing history |
| `banners.view` | View banners |
| `banners.manage` | Manage banners |
| `promotions.view` | View promotions |
| `stickers.view` | View stickers |
| `promotions.manage` | Manage promotions |
| `stickers.manage` | Manage stickers and assignments |
| `coupons.view` | View coupons and usage history |
| `coupons.manage` | Create and edit coupons |
| `coupons.delete` | Delete coupons |
| `giftcards.view` | View gift cards and their history |
| `giftcards.manage` | Issue and edit gift cards |
| `giftcards.delete` | Delete gift cards |
| `newsletters.view` | View newsletters, templates, autoresponders, campaigns and mailing lists |
| `newsletters.manage` | Manage newsletters, templates, autoresponders, campaigns and mailing lists |
| `newsletters.send` | Send newsletters and test messages |
| `newsletters.generate` | Write newsletters with AI |
| `subscribers.view` | View and export newsletter subscribers |
| `subscribers.manage` | Add, edit, import and delete newsletter subscribers |
| `banners.generate` | Generate banners with AI |

## Payments, shipping and taxes

| Permission | What it allows |
| --- | --- |
| `currencies.view` | View currency settings |
| `currencies.create` | Create currencies |
| `currencies.update` | Edit currency names and formatting |
| `currencies.rates` | Change exchange rates |
| `currencies.status` | Activate or disable currencies |
| `currencies.primary` | Change the primary currency (converts product prices) |
| `currencies.delete` | Delete secondary currencies |
| `taxes.view` | View taxes |
| `taxes.create` | Create taxes |
| `taxes.update` | Edit taxes, their rates and status |
| `taxes.delete` | Delete taxes |
| `taxes.assign` | Assign taxes to all products |
| `rate-areas.view` | View rate areas |
| `rate-areas.manage` | Manage rate areas |
| `countries.view` | View shipping countries |
| `regions.view` | View regions (nomoi) |
| `countries.manage` | Manage shipping countries |
| `regions.manage` | Manage regions (nomoi) |
| `payment-methods.manage` | Manage payment methods |
| `shipping-methods.view` | View shipping methods |
| `shipping-methods.manage` | Manage shipping methods |
| `shipping-carriers.manage` | Manage carrier settings and credentials |

## Settings, reports and logs

| Permission | What it allows |
| --- | --- |
| `audit.view` | View audit logs |
| `languages.manage` | Manage system languages and UI translations |
| `search.manage` | Manage product search |
| `settings.manage` | Manage general and company settings |
| `settings.brand-landing-pages` | Change brand landing-page source |
| `ai.logs.view` | View AI call history |
| `reports.view` | View sales, order, product, category, inventory and promotion reports |
| `reports.customers` | View customer reports (names and spending) |
| `reports.export` | Export report tables as CSV |

## Staff and access

| Permission | What it allows |
| --- | --- |
| `account-security.view` | View account security (super administrators only) |
| `admin.access` | Enter administration |
| `users.view` | View staff users |
| `users.create` | Create staff users |
| `users.update` | Update staff users |
| `users.deactivate` | Deactivate staff users |
| `users.set-password` | Set staff passwords and recovery email |
| `users.assign-roles` | Assign staff roles |
| `roles.view` | View roles |
| `roles.create` | Create roles |
| `roles.update` | Update roles |
| `roles.delete` | Delete unused roles |
| `roles.assign-permissions` | Assign permissions |

## Add-ons

| Permission | What it allows |
| --- | --- |
| `addons.manage` | Manage add-ons (install, enable, disable, upgrade, uninstall) |
| `addons.upload` | Upload add-on packages (runs third-party code) |
| `ambresoft.sitemap.view` | View XML sitemap |
| `ambresoft.sitemap.manage` | Manage sitemap settings and drafts |
| `ambresoft.sitemap.publish` | Publish XML sitemap |
| `ambresoft.product-feeds.view` | View Facebook/Google XML feeds |
| `ambresoft.product-feeds.manage` | Manage Facebook/Google XML feeds |
| `ambresoft.gdpr-cookies.view` | View GDPR cookie settings |
| `ambresoft.gdpr-cookies.manage` | Manage GDPR cookie settings |
| `ambresoft.gdpr-cookies.scripts` | Manage GDPR cookie scripts |
