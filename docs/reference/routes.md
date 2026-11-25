---
title: Routes
parent: Reference
nav_order: 1
---

# Routes
{: .no_toc }

Every URL of a standard installation, generated from `php artisan route:list --except-vendor` (598 routes, add-ons enabled at generation: Typesense search). The **name** is what developers use in `route()`. Admin URLs all require a signed-in, verified staff member with access to the administration; each screen and action then checks its own permission.

Add-on routes exist only while the add-on is enabled; they are listed in [Routes added by bundled add-ons](#routes-added-by-bundled-add-ons).

1. TOC
{:toc}

---

## Public and customer routes

### Storefront pages

| Method | URL | Name |
| --- | --- | --- |
| GET | `/` | `foundation` |
| POST | `/age-verification` | `age-verification` |
| GET | `/bestsellers` | `storefront.bestsellers` |
| GET | `/brands` | `storefront.brands` |
| POST | `/call-requests` | `storefront.call-requests.store` |
| DELETE | `/comparison` | `comparison.clear` |
| GET | `/comparison` | `comparison` |
| DELETE | `/comparison/products/{product}` | `comparison.remove` |
| PUT | `/comparison/products/{product}` | `comparison.add` |
| GET | `/locale/{code}` | `storefront.locale` |
| GET | `/new` | `storefront.new` |
| GET, POST, HEAD | `/newsletter/confirm/{subscriber}/{list}` | `newsletter.confirm` |
| POST | `/newsletter/subscribe` | `newsletter.subscribe` |
| GET, POST, HEAD | `/newsletter/unsubscribe/{subscriber}` | `newsletter.unsubscribe` |
| GET | `/offers` | `storefront.offers` |
| POST | `/products/{product}/reviews` | `reviews.store` |
| GET | `/search` | `storefront.search` |
| GET | `/search/recently-viewed` | `storefront.search.recently-viewed` |
| GET | `/search/suggest` | `storefront.search.suggest` |
| GET | `/sitemap` | `storefront.sitemap` |
| GET | `/storefront/favicon` | `storefront.favicon` |
| GET | `/storefront/media/{kind}/{owner}/{media}` | `storefront.media` |
| GET | `/storefront/products/{product}/brand-documents/{file}` | `storefront.brand-document` |
| GET | `/storefront/products/{product}/documents/{file}` | `storefront.product-document` |
| GET | `/storefront/reels/{reel}` | `storefront.reels.show` |
| GET | `/storefront/reels/{reel}/video/{video}` | `storefront.reels.video` |
| POST | `/storefront/reels/{reel}/views` | `storefront.reels.view` |
| GET | `/wishlist` | `wishlist` |
| POST | `/wishlists` | `wishlists.store` |
| GET | `/wishlists/product/{product}` | `wishlists.product` |
| DELETE | `/wishlists/{wishlist}` | `wishlists.destroy` |
| PATCH | `/wishlists/{wishlist}` | `wishlists.update` |
| DELETE | `/wishlists/{wishlist}/products/{product}` | `wishlists.remove` |
| PUT | `/wishlists/{wishlist}/products/{product}` | `wishlists.add` |
| GET | `/{fallbackPlaceholder}` | `storefront.product` |

### Cart and checkout

| Method | URL | Name |
| --- | --- | --- |
| GET | `/cart` | `cart` |
| POST | `/cart/codes` | `cart.codes.apply` |
| DELETE | `/cart/codes/{kind}` | `cart.codes.remove` |
| DELETE | `/cart/items` | `cart.clear` |
| GET | `/cart/items` | `cart.items` |
| POST | `/cart/items` | `cart.add` |
| DELETE | `/cart/items/{product}` | `cart.remove` |
| PATCH | `/cart/items/{product}` | `cart.update` |
| POST | `/cart/package` | `cart.package` |
| GET | `/checkout` | `checkout` |
| POST | `/checkout` | `checkout.store` |
| GET | `/checkout/complete/{order}` | `checkout.complete` |
| POST | `/checkout/invoice/verify` | `checkout.invoice.verify` |
| POST | `/checkout/quote` | `checkout.quote` |

### Payments

| Method | URL | Name |
| --- | --- | --- |
| GET | `/pay/cancel/{payment}` | `payments.cancel` |
| GET | `/pay/return/{payment}` | `payments.return` |
| GET | `/pay/{order}` | `payments.pay` |
| POST | `/pay/{order}` | `payments.start` |
| POST | `/payments/webhooks/{method}` | `payments.webhook` |

### Customer account and orders

| Method | URL | Name |
| --- | --- | --- |
| DELETE | `/account` | `account.destroy` |
| GET | `/account` | `account` |
| PATCH | `/account` | `account.update` |
| GET | `/account/addresses` | `account.addresses` |
| POST | `/account/addresses` | `account.addresses.store` |
| DELETE | `/account/addresses/{address}` | `account.addresses.destroy` |
| PUT | `/account/addresses/{address}` | `account.addresses.update` |
| POST | `/account/addresses/{address}/default` | `account.addresses.default` |
| GET | `/account/invitation/{user}/{hash}` | `account.invitation` |
| POST | `/account/invitation/{user}/{hash}` | `account.invitation.accept` |
| GET | `/account/newsletters` | `account.newsletters` |
| DELETE | `/account/newsletters/{list}` | `account.newsletters.destroy` |
| GET | `/account/orders` | `account.orders` |
| GET | `/account/orders/{order}` | `account.order` |
| POST | `/account/orders/{order}/cancel` | `account.order.cancel` |
| GET | `/account/orders/{order}/invoice` | `account.order.invoice` |
| GET | `/account/password` | `account.password` |
| PUT | `/account/password` | `account.password.update` |
| GET | `/account/profile` | `account.profile` |
| POST | `/account/profile` | `account.profile.store` |
| GET | `/account/reviews` | `account.reviews` |
| GET | `/order-status` | `order-tracking` |
| GET | `/orders/{order}/cancel` | `orders.cancel` |
| POST | `/orders/{order}/cancel` | `orders.cancel.store` |

### Sign-in and registration

| Method | URL | Name |
| --- | --- | --- |
| GET | `/auth/social/{provider}/callback` | `social.callback` |
| GET | `/auth/social/{provider}/redirect` | `social.redirect` |
| POST | `/email/verification-notification` | `verification.send` |
| GET | `/email/verify` | `verification.notice` |
| GET | `/email/verify/{id}/{hash}` | `verification.verify` |
| GET | `/forgot-password` | `password.request` |
| POST | `/forgot-password` | `password.email` |
| GET | `/login` | `login` |
| POST | `/login` | `login.store` |
| POST | `/logout` | `logout` |
| GET | `/register` | `register` |
| POST | `/register` | `register.store` |
| POST | `/reset-password` | `password.update` |
| GET | `/reset-password/{token}` | `password.reset` |

`/up` (health check) is provided by Laravel and answers `200` when the application boots.

## Administration routes

All under `/admin`. The guide link explains the screen.

### Add-ons

Guide: [Add-ons](../../admin/system/addons/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/addons` | `admin.addons.index` |
| POST | `/admin/addons/upload` | `admin.addons.upload` |
| DELETE | `/admin/addons/{vendor}/{name}` | `admin.addons.uninstall` |
| POST | `/admin/addons/{vendor}/{name}/disable` | `admin.addons.disable` |
| POST | `/admin/addons/{vendor}/{name}/enable` | `admin.addons.enable` |
| GET | `/admin/addons/{vendor}/{name}/icon` | `admin.addons.icon` |
| POST | `/admin/addons/{vendor}/{name}/install` | `admin.addons.install` |
| GET | `/admin/addons/{vendor}/{name}/settings` | `admin.addons.settings` |
| PUT | `/admin/addons/{vendor}/{name}/settings` | `admin.addons.settings.update` |
| POST | `/admin/addons/{vendor}/{name}/settings/actions/{action}` | `admin.addons.settings.action` |
| POST | `/admin/addons/{vendor}/{name}/settings/remote` | `admin.addons.settings.remote` |
| POST | `/admin/addons/{vendor}/{name}/upgrade` | `admin.addons.upgrade` |

### Availability statuses

Guide: [Availability statuses](../../admin/catalog/availability-statuses/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/availability-statuses` | `admin.availability-statuses.index` |
| POST | `/admin/availability-statuses` | `admin.availability-statuses.store` |
| GET | `/admin/availability-statuses/create` | `admin.availability-statuses.create` |
| DELETE | `/admin/availability-statuses/{availability_status}` | `admin.availability-statuses.destroy` |
| PUT, PATCH | `/admin/availability-statuses/{availability_status}` | `admin.availability-statuses.update` |
| GET | `/admin/availability-statuses/{availability_status}/edit` | `admin.availability-statuses.edit` |

### Banners

Guide: [Banners](../../admin/marketing/banners/)

| Method | URL | Name |
| --- | --- | --- |
| POST | `/admin/banner-groups` | `admin.banner-groups.store` |
| DELETE | `/admin/banner-groups/{group}` | `admin.banner-groups.destroy` |
| PUT | `/admin/banner-groups/{group}` | `admin.banner-groups.update` |
| GET | `/admin/banners` | `admin.banners.index` |
| POST | `/admin/banners` | `admin.banners.store` |
| POST | `/admin/banners/bulk` | `admin.banners.bulk` |
| GET | `/admin/banners/create` | `admin.banners.create` |
| POST | `/admin/banners/generate` | `admin.banners.generate` |
| DELETE | `/admin/banners/{banner}` | `admin.banners.destroy` |
| POST | `/admin/banners/{banner}` | `admin.banners.update` |
| POST | `/admin/banners/{banner}/clone` | `admin.banners.clone` |
| GET | `/admin/banners/{banner}/edit` | `admin.banners.edit` |
| GET | `/admin/banners/{banner}/media/{media}` | `admin.banners.media` |
| GET | `/admin/banners/{banner}/videos/{video}` | `admin.banners.video` |

### Blog

Guide: [Blog](../../admin/marketing/blog/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/blog-posts` | `admin.blog-posts.index` |
| POST | `/admin/blog-posts` | `admin.blog-posts.store` |
| POST | `/admin/blog-posts/assistant` | `admin.blog-posts.assistant` |
| POST | `/admin/blog-posts/bulk` | `admin.blog-posts.bulk` |
| GET | `/admin/blog-posts/create` | `admin.blog-posts.create` |
| DELETE | `/admin/blog-posts/{id}` | `admin.blog-posts.destroy` |
| POST | `/admin/blog-posts/{id}` | `admin.blog-posts.update` |
| GET | `/admin/blog-posts/{id}/cover` | `admin.blog-posts.cover` |
| GET | `/admin/blog-posts/{id}/cover-mobile` | `admin.blog-posts.cover-mobile` |
| GET | `/admin/blog-posts/{id}/edit` | `admin.blog-posts.edit` |
| GET | `/admin/blogs` | `admin.blogs.index` |
| POST | `/admin/blogs` | `admin.blogs.store` |
| POST | `/admin/blogs/bulk` | `admin.blogs.bulk` |
| GET | `/admin/blogs/create` | `admin.blogs.create` |
| DELETE | `/admin/blogs/{id}` | `admin.blogs.destroy` |
| POST | `/admin/blogs/{id}` | `admin.blogs.update` |
| GET | `/admin/blogs/{id}/edit` | `admin.blogs.edit` |

### Brands

Guide: [Brands](../../admin/catalog/brands/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/brands` | `admin.brands.index` |
| POST | `/admin/brands` | `admin.brands.store` |
| GET | `/admin/brands/create` | `admin.brands.create` |
| GET | `/admin/brands/import` | `admin.brands.import.index` |
| POST | `/admin/brands/import/preview` | `admin.brands.import.preview` |
| GET | `/admin/brands/import/template` | `admin.brands.import.template` |
| DELETE | `/admin/brands/import/{token}` | `admin.brands.import.cancel` |
| POST | `/admin/brands/import/{token}/confirm` | `admin.brands.import.confirm` |
| GET | `/admin/brands/import/{token}/errors` | `admin.brands.import.errors` |
| DELETE | `/admin/brands/{brand}` | `admin.brands.destroy` |
| POST | `/admin/brands/{brand}` | `admin.brands.update` |
| GET | `/admin/brands/{brand}/banner-image` | `admin.brands.banner-image` |
| GET | `/admin/brands/{brand}/edit` | `admin.brands.edit` |
| GET | `/admin/brands/{brand}/files/{file}` | `admin.brands.file` |
| GET | `/admin/brands/{brand}/image` | `admin.brands.image` |
| PATCH | `/admin/brands/{brand}/status` | `admin.brands.status` |

### Cache

Guide: [Cache](../../admin/system/cache/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/cache` | `admin.cache.index` |
| PUT | `/admin/cache` | `admin.cache.update` |
| POST | `/admin/cache/clear` | `admin.cache.clear` |

### Call requests

Guide: [Call requests](../../admin/orders/call-requests/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/call-requests` | `admin.call-requests.index` |
| POST | `/admin/call-requests` | `admin.call-requests.store` |
| DELETE | `/admin/call-requests/bulk` | `admin.call-requests.bulk-destroy` |
| GET | `/admin/call-requests/create` | `admin.call-requests.create` |
| DELETE | `/admin/call-requests/{callRequest}` | `admin.call-requests.destroy` |
| PATCH | `/admin/call-requests/{callRequest}` | `admin.call-requests.update` |
| GET | `/admin/call-requests/{callRequest}/edit` | `admin.call-requests.edit` |

### Carriers

Guide: [Carriers](../../admin/shipping-taxes/carriers/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/shipping-carriers` | `admin.shipping-carriers.index` |
| GET | `/admin/shipping-carriers/{provider}` | `admin.shipping-carriers.show` |
| PUT | `/admin/shipping-carriers/{provider}` | `admin.shipping-carriers.settings` |
| POST | `/admin/shipping-carriers/{provider}/accounts` | `admin.shipping-carriers.accounts.store` |
| DELETE | `/admin/shipping-carriers/{provider}/accounts/{account}` | `admin.shipping-carriers.accounts.destroy` |
| PUT | `/admin/shipping-carriers/{provider}/accounts/{account}` | `admin.shipping-carriers.accounts.update` |
| POST | `/admin/shipping-carriers/{provider}/accounts/{account}/verify` | `admin.shipping-carriers.accounts.verify` |
| GET | `/admin/shipping-carriers/{provider}/manifests` | `admin.shipping-carriers.manifests` |
| POST | `/admin/shipping-carriers/{provider}/manifests` | `admin.shipping-carriers.manifests.issue` |
| GET | `/admin/shipping-carriers/{provider}/manifests/{number}/print` | `admin.shipping-carriers.manifests.print` |

### Carts

Guide: [Carts](../../admin/catalog/carts/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/carts` | `admin.carts.index` |
| GET | `/admin/carts/{cart}` | `admin.carts.show` |

### Categories

Guide: [Categories](../../admin/catalog/categories/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/categories` | `admin.categories.index` |
| POST | `/admin/categories` | `admin.categories.store` |
| DELETE | `/admin/categories/bulk` | `admin.categories.bulk-destroy` |
| GET | `/admin/categories/create` | `admin.categories.create` |
| GET | `/admin/categories/import` | `admin.categories.import.index` |
| POST | `/admin/categories/import/preview` | `admin.categories.import.preview` |
| GET | `/admin/categories/import/template` | `admin.categories.import.template` |
| DELETE | `/admin/categories/import/{token}` | `admin.categories.import.cancel` |
| POST | `/admin/categories/import/{token}/confirm` | `admin.categories.import.confirm` |
| GET | `/admin/categories/import/{token}/errors` | `admin.categories.import.errors` |
| DELETE | `/admin/categories/{category}` | `admin.categories.destroy` |
| PUT, PATCH | `/admin/categories/{category}` | `admin.categories.update` |
| GET | `/admin/categories/{category}/banner-image` | `admin.categories.banner-image` |
| GET | `/admin/categories/{category}/block-image` | `admin.categories.block-image` |
| GET | `/admin/categories/{category}/edit` | `admin.categories.edit` |
| GET | `/admin/categories/{category}/image` | `admin.categories.image` |
| GET | `/admin/categories/{category}/menu-icon` | `admin.categories.menu-icon` |

### Code editor

Guide: [Code editor](../../admin/website/code-editor/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/code-editor` | `admin.code-editor.index` |
| GET | `/admin/code-editor/file` | `admin.code-editor.read` |
| POST | `/admin/code-editor/file` | `admin.code-editor.save` |

### Countries and regions

Guide: [Countries and regions](../../admin/shipping-taxes/countries-regions/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/countries` | `admin.countries.index` |
| PUT | `/admin/countries/{country}` | `admin.countries.update` |
| GET | `/admin/regions` | `admin.regions.index` |
| POST | `/admin/regions` | `admin.regions.store` |
| GET | `/admin/regions/import` | `admin.regions.import.index` |
| POST | `/admin/regions/import/preview` | `admin.regions.import.preview` |
| GET | `/admin/regions/import/template` | `admin.regions.import.template` |
| DELETE | `/admin/regions/import/{token}` | `admin.regions.import.cancel` |
| POST | `/admin/regions/import/{token}/confirm` | `admin.regions.import.confirm` |
| GET | `/admin/regions/import/{token}/errors` | `admin.regions.import.errors` |
| POST | `/admin/regions/status` | `admin.regions.status` |
| DELETE | `/admin/regions/{region}` | `admin.regions.destroy` |
| PUT | `/admin/regions/{region}` | `admin.regions.update` |

### Coupons

Guide: [Coupons](../../admin/marketing/coupons/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/coupons` | `admin.coupons.index` |
| POST | `/admin/coupons` | `admin.coupons.store` |
| DELETE | `/admin/coupons/bulk` | `admin.coupons.bulk-destroy` |
| GET | `/admin/coupons/create` | `admin.coupons.create` |
| DELETE | `/admin/coupons/{coupon}` | `admin.coupons.destroy` |
| PUT, PATCH | `/admin/coupons/{coupon}` | `admin.coupons.update` |
| GET | `/admin/coupons/{coupon}/edit` | `admin.coupons.edit` |

### Customer blacklist

Guide: [Customer blacklist](../../admin/customers/blacklist/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/customer-blacklist` | `admin.customer-blacklist.index` |
| POST | `/admin/customer-blacklist` | `admin.customer-blacklist.store` |
| DELETE | `/admin/customer-blacklist/{entry}` | `admin.customer-blacklist.destroy` |

### Customer groups

Guide: [Customer groups](../../admin/customers/customer-groups/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/customer-groups` | `admin.customer-groups.index` |
| POST | `/admin/customer-groups` | `admin.customer-groups.store` |
| GET | `/admin/customer-groups/create` | `admin.customer-groups.create` |
| DELETE | `/admin/customer-groups/{group}` | `admin.customer-groups.destroy` |
| PUT, PATCH | `/admin/customer-groups/{group}` | `admin.customer-groups.update` |
| GET | `/admin/customer-groups/{group}/edit` | `admin.customer-groups.edit` |

### Customers

Guide: [Customers](../../admin/customers/customers/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/customers` | `admin.customers.index` |
| POST | `/admin/customers` | `admin.customers.store` |
| GET | `/admin/customers/create` | `admin.customers.create` |
| GET | `/admin/customers/{customer}` | `admin.customers.show` |
| PUT, PATCH | `/admin/customers/{customer}` | `admin.customers.update` |
| POST | `/admin/customers/{customer}/addresses` | `admin.customers.addresses.store` |
| DELETE | `/admin/customers/{customer}/addresses/{address}` | `admin.customers.addresses.destroy` |
| PUT | `/admin/customers/{customer}/addresses/{address}` | `admin.customers.addresses.update` |
| POST | `/admin/customers/{customer}/blacklist` | `admin.customers.blacklist` |
| GET | `/admin/customers/{customer}/edit` | `admin.customers.edit` |
| POST | `/admin/customers/{customer}/invitation` | `admin.customers.invitation` |
| PATCH | `/admin/customers/{customer}/status` | `admin.customers.status` |

### Dashboard

Guide: [Dashboard](../../admin/basics/#the-dashboard)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin` | `admin.dashboard` |

### FAQs

Guide: [FAQs](../../admin/website/faqs/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/faqs` | `admin.faqs.index` |
| POST | `/admin/faqs` | `admin.faqs.store` |
| GET | `/admin/faqs/ai-log` | `admin.faqs.ai-log` |
| GET | `/admin/faqs/ai-settings` | `admin.faqs.ai-settings` |
| POST | `/admin/faqs/assistant` | `admin.faqs.assistant` |
| GET | `/admin/faqs/create` | `admin.faqs.create` |
| POST | `/admin/faqs/generate` | `admin.faqs.generate` |
| GET | `/admin/faqs/options` | `admin.faqs.options` |
| DELETE | `/admin/faqs/{faq}` | `admin.faqs.destroy` |
| PUT, PATCH | `/admin/faqs/{faq}` | `admin.faqs.update` |
| GET | `/admin/faqs/{faq}/edit` | `admin.faqs.edit` |

### Features

Guide: [Features](../../admin/catalog/features/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/features` | `admin.features.index` |
| POST | `/admin/features` | `admin.features.store` |
| GET | `/admin/features/create` | `admin.features.create` |
| GET | `/admin/features/groups` | `admin.features.groups` |
| POST | `/admin/features/groups` | `admin.features.groups.store` |
| POST | `/admin/features/groups/{group}` | `admin.features.groups.update` |
| GET | `/admin/features/ordering` | `admin.features.ordering` |
| POST | `/admin/features/ordering` | `admin.features.ordering.save` |
| GET | `/admin/features/remediation` | `admin.features.remediation` |
| POST | `/admin/features/{feature}` | `admin.features.update` |
| POST | `/admin/features/{feature}/action` | `admin.features.action` |
| GET | `/admin/features/{feature}/edit` | `admin.features.edit` |
| POST | `/admin/features/{feature}/options` | `admin.features.options.store` |
| POST | `/admin/features/{feature}/options/batch` | `admin.features.options.batch` |
| POST | `/admin/features/{feature}/options/{option}` | `admin.features.options.update` |
| GET | `/admin/features/{feature}/options/{option}/image` | `admin.features.options.image` |
| GET | `/admin/features/{feature}/options/{option}/swatch` | `admin.features.options.swatch` |

### Gift cards

Guide: [Gift cards](../../admin/marketing/gift-cards/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/gift-cards` | `admin.gift-cards.index` |
| POST | `/admin/gift-cards` | `admin.gift-cards.store` |
| DELETE | `/admin/gift-cards/bulk` | `admin.gift-cards.bulk-destroy` |
| GET | `/admin/gift-cards/create` | `admin.gift-cards.create` |
| DELETE | `/admin/gift-cards/{giftCard}` | `admin.gift-cards.destroy` |
| PUT | `/admin/gift-cards/{giftCard}` | `admin.gift-cards.update` |
| GET | `/admin/gift-cards/{giftCard}/edit` | `admin.gift-cards.edit` |
| POST | `/admin/gift-cards/{giftCard}/send` | `admin.gift-cards.send` |
| PATCH | `/admin/gift-cards/{giftCard}/status` | `admin.gift-cards.status` |

### Imports / Exports

Guide: [Imports / Exports](../../admin/system/imports-exports/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/data-transfer` | `admin.data-transfer.index` |
| GET | `/admin/data-transfer/export/{entity}` | `admin.data-transfer.export.show` |
| GET | `/admin/data-transfer/export/{entity}/download` | `admin.data-transfer.export.download` |

### Layouts

Guide: [Layouts](../../admin/website/layouts/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/layouts` | `admin.layouts.index` |
| POST | `/admin/layouts` | `admin.layouts.store` |
| POST | `/admin/layouts-media` | `admin.layouts.media.store` |
| GET | `/admin/layouts-media/{media}` | `admin.layouts.media` |
| DELETE | `/admin/layouts/{page}` | `admin.layouts.destroy` |
| GET | `/admin/layouts/{page}` | `admin.layouts.edit` |
| PUT | `/admin/layouts/{page}` | `admin.layouts.save` |
| PUT | `/admin/layouts/{page}/settings` | `admin.layouts.settings` |

### Logs

Guide: [Logs](../../admin/system/logs/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/audit-logs` | `admin.audit.index` |
| GET | `/admin/audit-logs/{log}` | `admin.audit.show` |

### Menus

Guide: [Menus](../../admin/website/menus/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/menus` | `admin.menus.index` |
| POST | `/admin/menus` | `admin.menus.store` |
| GET | `/admin/menus/create` | `admin.menus.create` |
| DELETE | `/admin/menus/{menu}` | `admin.menus.destroy` |
| GET | `/admin/menus/{menu}` | `admin.menus.show` |
| POST | `/admin/menus/{menu}` | `admin.menus.update` |
| GET | `/admin/menus/{menu}/edit` | `admin.menus.edit` |
| POST | `/admin/menus/{menu}/items` | `admin.menus.items.store` |
| GET | `/admin/menus/{menu}/items/create` | `admin.menus.items.create` |
| DELETE | `/admin/menus/{menu}/items/{item}` | `admin.menus.items.destroy` |
| POST | `/admin/menus/{menu}/items/{item}` | `admin.menus.items.update` |
| GET | `/admin/menus/{menu}/items/{item}/edit` | `admin.menus.items.edit` |
| GET | `/admin/menus/{menu}/items/{item}/image` | `admin.menus.items.image` |

### Newsletters

Guide: [Newsletters](../../admin/marketing/newsletters/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/newsletter-autoresponders` | `admin.newsletter-autoresponders.index` |
| POST | `/admin/newsletter-autoresponders` | `admin.newsletter-autoresponders.store` |
| GET | `/admin/newsletter-autoresponders/create` | `admin.newsletter-autoresponders.create` |
| DELETE | `/admin/newsletter-autoresponders/{item}` | `admin.newsletter-autoresponders.destroy` |
| PUT | `/admin/newsletter-autoresponders/{item}` | `admin.newsletter-autoresponders.update` |
| PATCH | `/admin/newsletter-autoresponders/{item}/active` | `admin.newsletter-autoresponders.active` |
| GET | `/admin/newsletter-autoresponders/{item}/edit` | `admin.newsletter-autoresponders.edit` |
| POST | `/admin/newsletter-autoresponders/{item}/test` | `admin.newsletter-autoresponders.test` |
| GET | `/admin/newsletter-campaigns` | `admin.newsletter-campaigns.index` |
| POST | `/admin/newsletter-campaigns` | `admin.newsletter-campaigns.store` |
| DELETE | `/admin/newsletter-campaigns/{campaign}` | `admin.newsletter-campaigns.destroy` |
| PUT | `/admin/newsletter-campaigns/{campaign}` | `admin.newsletter-campaigns.update` |
| GET | `/admin/newsletter-lists` | `admin.newsletter-lists.index` |
| POST | `/admin/newsletter-lists` | `admin.newsletter-lists.store` |
| GET | `/admin/newsletter-lists/create` | `admin.newsletter-lists.create` |
| DELETE | `/admin/newsletter-lists/{list}` | `admin.newsletter-lists.destroy` |
| PUT | `/admin/newsletter-lists/{list}` | `admin.newsletter-lists.update` |
| GET | `/admin/newsletter-lists/{list}/edit` | `admin.newsletter-lists.edit` |
| GET | `/admin/newsletter-subscribers` | `admin.newsletter-subscribers.index` |
| POST | `/admin/newsletter-subscribers` | `admin.newsletter-subscribers.store` |
| GET | `/admin/newsletter-subscribers/create` | `admin.newsletter-subscribers.create` |
| GET | `/admin/newsletter-subscribers/export` | `admin.newsletter-subscribers.export` |
| POST | `/admin/newsletter-subscribers/import` | `admin.newsletter-subscribers.import` |
| DELETE | `/admin/newsletter-subscribers/{subscriber}` | `admin.newsletter-subscribers.destroy` |
| PUT | `/admin/newsletter-subscribers/{subscriber}` | `admin.newsletter-subscribers.update` |
| GET | `/admin/newsletter-subscribers/{subscriber}/edit` | `admin.newsletter-subscribers.edit` |
| GET | `/admin/newsletter-templates` | `admin.newsletter-templates.index` |
| POST | `/admin/newsletter-templates` | `admin.newsletter-templates.store` |
| GET | `/admin/newsletter-templates/create` | `admin.newsletter-templates.create` |
| DELETE | `/admin/newsletter-templates/{item}` | `admin.newsletter-templates.destroy` |
| PUT | `/admin/newsletter-templates/{item}` | `admin.newsletter-templates.update` |
| PATCH | `/admin/newsletter-templates/{item}/active` | `admin.newsletter-templates.active` |
| GET | `/admin/newsletter-templates/{item}/edit` | `admin.newsletter-templates.edit` |
| POST | `/admin/newsletter-templates/{item}/test` | `admin.newsletter-templates.test` |
| GET | `/admin/newsletters` | `admin.newsletters.index` |
| POST | `/admin/newsletters` | `admin.newsletters.store` |
| POST | `/admin/newsletters/assistant` | `admin.newsletters.assistant` |
| GET | `/admin/newsletters/create` | `admin.newsletters.create` |
| GET | `/admin/newsletters/customers` | `admin.newsletters.customers` |
| POST | `/admin/newsletters/preview` | `admin.newsletters.preview` |
| DELETE | `/admin/newsletters/{item}` | `admin.newsletters.destroy` |
| PUT | `/admin/newsletters/{item}` | `admin.newsletters.update` |
| PATCH | `/admin/newsletters/{item}/active` | `admin.newsletters.active` |
| GET | `/admin/newsletters/{item}/edit` | `admin.newsletters.edit` |
| POST | `/admin/newsletters/{item}/send` | `admin.newsletters.send` |
| POST | `/admin/newsletters/{item}/test` | `admin.newsletters.test` |

### Order email templates

Guide: [Order email templates](../../admin/orders/email-templates/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/order-emails` | `admin.order-emails.index` |
| ANY | `/admin/order-emails/invoice` | permanent redirect to `/admin/order-emails/order-summary` |
| POST | `/admin/order-emails/preview` | `admin.order-emails.preview` |
| POST | `/admin/order-emails/test` | `admin.order-emails.test` |
| GET | `/admin/order-emails/{kind}` | `admin.order-emails.layout` |
| PUT | `/admin/order-emails/{kind}/{layout}` | `admin.order-emails.layout.update` |
| GET | `/admin/order-emails/{status}/edit` | `admin.order-emails.edit` |
| PUT | `/admin/order-emails/{status}/templates/{template}` | `admin.order-emails.update` |

### Order statuses

Guide: [Order statuses](../../admin/orders/order-statuses/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/order-statuses` | `admin.order-statuses.index` |
| POST | `/admin/order-statuses` | `admin.order-statuses.store` |
| GET | `/admin/order-statuses/create` | `admin.order-statuses.create` |
| POST | `/admin/order-statuses/order` | `admin.order-statuses.order` |
| DELETE | `/admin/order-statuses/{status}` | `admin.order-statuses.destroy` |
| PUT | `/admin/order-statuses/{status}` | `admin.order-statuses.update` |
| PATCH | `/admin/order-statuses/{status}/active` | `admin.order-statuses.active` |
| GET | `/admin/order-statuses/{status}/edit` | `admin.order-statuses.edit` |
| POST | `/admin/order-statuses/{status}/move` | `admin.order-statuses.move` |

### Orders

Guide: [Orders](../../admin/orders/orders/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/orders` | `admin.orders.index` |
| POST | `/admin/orders` | `admin.orders.store` |
| PATCH | `/admin/orders/bulk-status` | `admin.orders.bulk-status` |
| GET | `/admin/orders/create` | `admin.orders.create` |
| GET | `/admin/orders/files` | `admin.order-files.index` |
| GET | `/admin/orders/files/packing-slip` | `admin.order-files.packing-slip` |
| PUT | `/admin/orders/files/packing-slip/{layout}` | `admin.order-files.packing-slip.update` |
| POST | `/admin/orders/quote` | `admin.orders.quote` |
| GET | `/admin/orders/{order}` | `admin.orders.show` |
| PUT | `/admin/orders/{order}` | `admin.orders.update` |
| POST | `/admin/orders/{order}/comments` | `admin.orders.comments.store` |
| GET | `/admin/orders/{order}/edit` | `admin.orders.edit` |
| POST | `/admin/orders/{order}/gift-card` | `admin.orders.gift-card` |
| GET | `/admin/orders/{order}/invoice` | `admin.orders.invoice` |
| POST | `/admin/orders/{order}/notifications/{notification}/retry` | `admin.orders.notifications.retry` |
| GET | `/admin/orders/{order}/packing-slip` | `admin.orders.packing-slip` |
| POST | `/admin/orders/{order}/payments` | `admin.orders.payments.store` |
| POST | `/admin/orders/{order}/payments/{payment}/refresh` | `admin.orders.payments.refresh` |
| POST | `/admin/orders/{order}/quote` | `admin.orders.quote-existing` |
| POST | `/admin/orders/{order}/shipments` | `admin.orders.shipments.store` |
| DELETE | `/admin/orders/{order}/shipments/{shipment}` | `admin.orders.shipments.cancel` |
| GET | `/admin/orders/{order}/shipments/{shipment}/label` | `admin.orders.shipments.label` |
| POST | `/admin/orders/{order}/shipments/{shipment}/resolve` | `admin.orders.shipments.resolve` |
| POST | `/admin/orders/{order}/shipments/{shipment}/track` | `admin.orders.shipments.track` |
| PATCH | `/admin/orders/{order}/status` | `admin.orders.status` |

### Pages

Guide: [Pages](../../admin/website/pages/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/pages` | `admin.pages.index` |
| POST | `/admin/pages` | `admin.pages.store` |
| POST | `/admin/pages/bulk` | `admin.pages.bulk` |
| GET | `/admin/pages/create` | `admin.pages.create` |
| DELETE | `/admin/pages/{page}` | `admin.pages.destroy` |
| PUT | `/admin/pages/{page}` | `admin.pages.update` |
| GET | `/admin/pages/{page}/edit` | `admin.pages.edit` |

### Payment methods

Guide: [Payment methods](../../admin/payment-methods/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/payment-methods` | `admin.payment-methods.index` |
| POST | `/admin/payment-methods/order` | `admin.payment-methods.order` |
| PUT | `/admin/payment-methods/{method}` | `admin.payment-methods.update` |
| PATCH | `/admin/payment-methods/{method}/active` | `admin.payment-methods.active` |
| GET | `/admin/payment-methods/{method}/edit` | `admin.payment-methods.edit` |
| GET | `/admin/payment-methods/{method}/image` | `admin.payment-methods.image` |
| POST | `/admin/payment-methods/{method}/verify` | `admin.payment-methods.verify` |

### Product packages

Guide: [Product packages](../../admin/catalog/product-packages/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/product-packages` | `admin.product-packages.index` |
| POST | `/admin/product-packages` | `admin.product-packages.save` |
| POST | `/admin/product-packages/assign` | `admin.product-packages.assign` |
| DELETE | `/admin/product-packages/{package}` | `admin.product-packages.delete` |

### Products

Guide: [Products](../../admin/catalog/products/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/product-media/{media}` | `admin.products.image` |
| GET | `/admin/products` | `admin.products.index` |
| POST | `/admin/products` | `admin.products.store` |
| POST | `/admin/products/bulk` | `admin.products.bulk` |
| GET | `/admin/products/import` | `admin.products.import.index` |
| POST | `/admin/products/import/preview` | `admin.products.import.preview` |
| GET | `/admin/products/import/template` | `admin.products.import.template` |
| DELETE | `/admin/products/import/{token}` | `admin.products.import.cancel` |
| POST | `/admin/products/import/{token}/confirm` | `admin.products.import.confirm` |
| GET | `/admin/products/import/{token}/errors` | `admin.products.import.errors` |
| GET | `/admin/products/trash` | `admin.products.trash` |
| POST | `/admin/products/{product}` | `admin.products.update` |
| POST | `/admin/products/{product}/action` | `admin.products.action` |
| POST | `/admin/products/{product}/duplicate` | `admin.products.duplicate` |
| GET | `/admin/products/{product}/edit` | `admin.products.edit` |

### Promotions

Guide: [Promotions](../../admin/marketing/promotions/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/promotions` | `admin.promotions.index` |
| POST | `/admin/promotions` | `admin.promotions.store` |
| GET | `/admin/promotions/create` | `admin.promotions.create` |
| PUT, PATCH | `/admin/promotions/{promotion}` | `admin.promotions.update` |
| GET | `/admin/promotions/{promotion}/edit` | `admin.promotions.edit` |
| PATCH | `/admin/promotions/{promotion}/status` | `admin.promotions.status` |

### Reels

Guide: [Reels](../../admin/marketing/reels/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/reels` | `admin.reels.index` |
| POST | `/admin/reels` | `admin.reels.store` |
| GET | `/admin/reels/create` | `admin.reels.create` |
| DELETE | `/admin/reels/{reel}` | `admin.reels.destroy` |
| POST | `/admin/reels/{reel}` | `admin.reels.update` |
| GET | `/admin/reels/{reel}/cover` | `admin.reels.cover` |
| GET | `/admin/reels/{reel}/edit` | `admin.reels.edit` |
| GET | `/admin/reels/{reel}/history` | `admin.reels.history` |
| PATCH | `/admin/reels/{reel}/status` | `admin.reels.status` |
| GET | `/admin/reels/{reel}/video` | `admin.reels.video` |

### Reports

Guide: [Reports](../../admin/reports/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/reports` | `admin.reports.overview` |
| GET | `/admin/reports/brands` | `admin.reports.brands` |
| GET | `/admin/reports/brands/export` | `admin.reports.brands.export` |
| GET | `/admin/reports/categories` | `admin.reports.categories` |
| GET | `/admin/reports/categories/export` | `admin.reports.categories.export` |
| GET | `/admin/reports/customers` | `admin.reports.customers` |
| GET | `/admin/reports/customers/export` | `admin.reports.customers.export` |
| GET | `/admin/reports/inventory` | `admin.reports.inventory` |
| GET | `/admin/reports/inventory/export` | `admin.reports.inventory.export` |
| GET | `/admin/reports/orders` | `admin.reports.orders` |
| GET | `/admin/reports/orders/export` | `admin.reports.orders.export` |
| GET | `/admin/reports/products` | `admin.reports.products` |
| GET | `/admin/reports/products/export` | `admin.reports.products.export` |
| GET | `/admin/reports/promotions` | `admin.reports.promotions` |
| GET | `/admin/reports/promotions/export` | `admin.reports.promotions.export` |
| GET | `/admin/reports/sales` | `admin.reports.sales` |
| GET | `/admin/reports/sales/export` | `admin.reports.sales.export` |
| GET | `/admin/search-terms` | `admin.search-terms.index` |
| PATCH | `/admin/search-terms/{term}/visibility` | `admin.search-terms.visibility` |

### Reviews

Guide: [Reviews](../../admin/catalog/reviews/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/reviews` | `admin.reviews.index` |
| POST | `/admin/reviews/bulk` | `admin.reviews.bulk` |
| DELETE | `/admin/reviews/{review}` | `admin.reviews.destroy` |
| PATCH | `/admin/reviews/{review}` | `admin.reviews.update` |
| GET | `/admin/reviews/{review}/edit` | `admin.reviews.edit` |
| POST | `/admin/reviews/{review}/status` | `admin.reviews.status` |

### SEO rules

Guide: [SEO rules](../../admin/website/seo-rules/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/seo-rules` | `admin.seo-rules.index` |
| POST | `/admin/seo-rules` | `admin.seo-rules.store` |
| GET | `/admin/seo-rules/create` | `admin.seo-rules.create` |
| DELETE | `/admin/seo-rules/{rule}` | `admin.seo-rules.destroy` |
| PUT | `/admin/seo-rules/{rule}` | `admin.seo-rules.update` |
| GET | `/admin/seo-rules/{rule}/edit` | `admin.seo-rules.edit` |
| PATCH | `/admin/seo-rules/{rule}/status` | `admin.seo-rules.status` |

### Settings

Guide: [Settings](../../admin/settings/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/settings/ai-log` | `admin.settings.ai-log` |
| GET | `/admin/settings/company` | `admin.settings.company` |
| PUT | `/admin/settings/company` | `admin.settings.company.update` |
| POST | `/admin/settings/company/links` | `admin.settings.company.links.store` |
| POST | `/admin/settings/company/links/order` | `admin.settings.company.links.order` |
| DELETE | `/admin/settings/company/links/{link}` | `admin.settings.company.links.destroy` |
| POST | `/admin/settings/company/links/{link}` | `admin.settings.company.links.update` |
| GET | `/admin/settings/company/links/{link}/image` | `admin.settings.company.links.image` |
| GET | `/admin/settings/company/logo/{kind}` | `admin.settings.company.logo` |
| POST | `/admin/settings/company/logos` | `admin.settings.company.logos` |
| POST | `/admin/settings/company/payment-icons` | `admin.settings.company.payment-icons.store` |
| POST | `/admin/settings/company/payment-icons/order` | `admin.settings.company.payment-icons.order` |
| DELETE | `/admin/settings/company/payment-icons/{icon}` | `admin.settings.company.payment-icons.destroy` |
| GET | `/admin/settings/company/payment-icons/{icon}/image` | `admin.settings.company.payment-icons.image` |
| GET | `/admin/settings/currencies` | `admin.settings.currencies.index` |
| POST | `/admin/settings/currencies` | `admin.settings.currencies.store` |
| GET | `/admin/settings/currencies/create` | `admin.settings.currencies.create` |
| DELETE | `/admin/settings/currencies/{currency}` | `admin.settings.currencies.destroy` |
| PUT, PATCH | `/admin/settings/currencies/{currency}` | `admin.settings.currencies.update` |
| GET | `/admin/settings/currencies/{currency}/edit` | `admin.settings.currencies.edit` |
| POST | `/admin/settings/currencies/{currency}/primary` | `admin.settings.currencies.primary` |
| GET | `/admin/settings/general` | `admin.settings.general` |
| PUT | `/admin/settings/general` | `admin.settings.general.update` |
| GET | `/admin/settings/languages` | `admin.settings.languages` |
| PUT | `/admin/settings/languages` | `admin.settings.languages.update` |
| GET | `/admin/settings/languages/translations` | `admin.settings.translations` |
| PUT | `/admin/settings/languages/translations` | `admin.settings.translations.update` |
| GET | `/admin/settings/search` | `admin.settings.search` |
| PUT | `/admin/settings/search` | `admin.settings.search.update` |
| POST | `/admin/settings/search/rebuild` | `admin.settings.search.rebuild` |
| POST | `/admin/settings/search/reconcile` | `admin.settings.search.reconcile` |
| POST | `/admin/settings/search/repair` | `admin.settings.search.repair` |
| POST | `/admin/settings/search/retry` | `admin.settings.search.retry` |
| POST | `/admin/settings/search/runs/{run}/cancel` | `admin.settings.search.cancel` |
| POST | `/admin/settings/search/sync` | `admin.settings.search.sync` |
| PUT | `/admin/settings/storefront-colors` | `admin.settings.storefront-colors.update` |

### Shipping methods

Guide: [Shipping methods](../../admin/shipping-taxes/shipping-methods/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/shipping-methods` | `admin.shipping-methods.index` |
| POST | `/admin/shipping-methods` | `admin.shipping-methods.store` |
| GET | `/admin/shipping-methods/create` | `admin.shipping-methods.create` |
| POST | `/admin/shipping-methods/order` | `admin.shipping-methods.order` |
| DELETE | `/admin/shipping-methods/{method}` | `admin.shipping-methods.destroy` |
| PUT | `/admin/shipping-methods/{method}` | `admin.shipping-methods.update` |
| PATCH | `/admin/shipping-methods/{method}/active` | `admin.shipping-methods.active` |
| GET | `/admin/shipping-methods/{method}/edit` | `admin.shipping-methods.edit` |
| GET | `/admin/shipping-methods/{method}/image` | `admin.shipping-methods.image` |

### Sign-in and your account

Guide: [Sign-in and your account](../../admin/basics/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/access-pending` | `admin.access.pending` |
| POST | `/admin/email/verification-notification` | `admin.verification.send` |
| GET | `/admin/email/verify` | `admin.verification.notice` |
| GET | `/admin/email/verify/{id}/{hash}` | `admin.verification.verify` |
| GET | `/admin/forgot-password` | `admin.password.request` |
| POST | `/admin/forgot-password` | `admin.password.email` |
| PATCH | `/admin/locale` | `admin.locale.update` |
| GET | `/admin/login` | `admin.login` |
| POST | `/admin/login` | `admin.login.store` |
| POST | `/admin/logout` | `admin.logout` |
| GET | `/admin/notifications` | `admin.notifications.index` |
| POST | `/admin/notifications/read-all` | `admin.notifications.read-all` |
| GET | `/admin/notifications/stream` | `admin.notifications.stream` |
| POST | `/admin/notifications/{notification}` | `admin.notifications.change` |
| GET | `/admin/passkeys` | `admin.passkeys.index` |
| POST | `/admin/passkeys` | `admin.passkeys.store` |
| POST | `/admin/passkeys/login` | `admin.passkeys.login` |
| POST | `/admin/passkeys/login/options` | `admin.passkeys.login.options` |
| POST | `/admin/passkeys/options` | `admin.passkeys.options` |
| POST | `/admin/passkeys/{passkey}/delete` | `admin.passkeys.destroy` |
| GET | `/admin/register` | `admin.register` |
| POST | `/admin/register` | `admin.register.store` |
| POST | `/admin/reset-password` | `admin.password.update` |
| GET | `/admin/reset-password/{token}` | `admin.password.reset` |

### Social login

Guide: [Social login](../../admin/website/social-login/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/social-login` | `admin.social-login.index` |
| POST | `/admin/social-login` | `admin.social-login.store` |
| GET | `/admin/social-login/create` | `admin.social-login.create` |
| DELETE | `/admin/social-login/{provider}` | `admin.social-login.destroy` |
| PUT, PATCH | `/admin/social-login/{provider}` | `admin.social-login.update` |
| PATCH | `/admin/social-login/{provider}/active` | `admin.social-login.active` |
| GET | `/admin/social-login/{provider}/edit` | `admin.social-login.edit` |

### Staff users and roles

Guide: [Staff users and roles](../../admin/users-and-roles/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/account-security/{user}` | `admin.account-security.show` |
| GET | `/admin/roles` | `admin.roles.index` |
| POST | `/admin/roles` | `admin.roles.store` |
| GET | `/admin/roles/create` | `admin.roles.create` |
| DELETE | `/admin/roles/{role}` | `admin.roles.destroy` |
| PUT, PATCH | `/admin/roles/{role}` | `admin.roles.update` |
| GET | `/admin/roles/{role}/edit` | `admin.roles.edit` |
| GET | `/admin/users` | `admin.users.index` |
| POST | `/admin/users` | `admin.users.store` |
| GET | `/admin/users/create` | `admin.users.create` |
| DELETE | `/admin/users/{user}` | `admin.users.destroy` |
| PUT, PATCH | `/admin/users/{user}` | `admin.users.update` |
| GET | `/admin/users/{user}/edit` | `admin.users.edit` |
| POST | `/admin/users/{user}/invitation` | `admin.users.invite` |

### Stickers

Guide: [Stickers](../../admin/marketing/stickers/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/stickers` | `admin.stickers.index` |
| POST | `/admin/stickers` | `admin.stickers.store` |
| GET | `/admin/stickers/create` | `admin.stickers.create` |
| PATCH | `/admin/stickers/limits` | `admin.stickers.limits` |
| DELETE | `/admin/stickers/{sticker}` | `admin.stickers.destroy` |
| POST | `/admin/stickers/{sticker}` | `admin.stickers.update` |
| GET | `/admin/stickers/{sticker}/edit` | `admin.stickers.edit` |
| GET | `/admin/stickers/{sticker}/image` | `admin.stickers.image` |
| PATCH | `/admin/stickers/{sticker}/status` | `admin.stickers.status` |

### Taxes and rate areas

Guide: [Taxes and rate areas](../../admin/shipping-taxes/taxes/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/rate-areas` | `admin.rate-areas.index` |
| POST | `/admin/rate-areas` | `admin.rate-areas.store` |
| GET | `/admin/rate-areas/create` | `admin.rate-areas.create` |
| DELETE | `/admin/rate-areas/{area}` | `admin.rate-areas.destroy` |
| PUT, PATCH | `/admin/rate-areas/{area}` | `admin.rate-areas.update` |
| GET | `/admin/rate-areas/{area}/edit` | `admin.rate-areas.edit` |
| GET | `/admin/taxes` | `admin.taxes.index` |
| POST | `/admin/taxes` | `admin.taxes.store` |
| POST | `/admin/taxes/assign-all` | `admin.taxes.assign-all` |
| GET | `/admin/taxes/create` | `admin.taxes.create` |
| DELETE | `/admin/taxes/{tax}` | `admin.taxes.destroy` |
| PUT, PATCH | `/admin/taxes/{tax}` | `admin.taxes.update` |
| GET | `/admin/taxes/{tax}/edit` | `admin.taxes.edit` |

### Variation groups

Guide: [Variation groups](../../admin/catalog/variation-groups/)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/admin/variation-groups` | `admin.variation-groups.index` |
| POST | `/admin/variation-groups` | `admin.variation-groups.store` |
| GET | `/admin/variation-groups/create` | `admin.variation-groups.create` |
| DELETE | `/admin/variation-groups/{group}` | `admin.variation-groups.destroy` |
| GET | `/admin/variation-groups/{group}` | `admin.variation-groups.show` |
| POST | `/admin/variation-groups/{group}/features` | `admin.variation-groups.features` |
| POST | `/admin/variation-groups/{group}/listing` | `admin.variation-groups.listing` |
| POST | `/admin/variation-groups/{group}/members` | `admin.variation-groups.members.store` |
| DELETE | `/admin/variation-groups/{group}/members/{product}` | `admin.variation-groups.members.destroy` |
| POST | `/admin/variation-groups/{group}/members/{product}/transfer` | `admin.variation-groups.members.transfer` |
| POST | `/admin/variation-groups/{group}/name` | `admin.variation-groups.rename` |
| POST | `/admin/variation-groups/{group}/order` | `admin.variation-groups.order` |
| POST | `/admin/variation-groups/{group}/variations` | `admin.variation-groups.variations` |

## Routes added by bundled add-ons

These routes exist only while their add-on is enabled. Admin routes carry the usual staff middleware plus the add-on's
own permissions.

### Sitemap (`ambresoft/sitemap`)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/sitemap.xml` | `module.ambresoft.sitemap.public.index` |
| GET | `/sitemap-{version}-{part}.xml` | `module.ambresoft.sitemap.public.part` |
| GET | `/admin/sitemap` | `module.ambresoft.sitemap.admin.index` |
| PUT | `/admin/sitemap/settings` | `module.ambresoft.sitemap.admin.settings` |
| PUT | `/admin/sitemap/draft` | `module.ambresoft.sitemap.admin.draft` |
| POST | `/admin/sitemap/generate` | `module.ambresoft.sitemap.admin.generate` |
| POST | `/admin/sitemap/publish` | `module.ambresoft.sitemap.admin.publish` |

### Facebook/Google XML product feeds (`ambresoft/product-feeds`)

| Method | URL | Name |
| --- | --- | --- |
| GET | `/storefront/product-feeds/{feed}.xml` | `module.ambresoft.product-feeds.public.show` |
| GET | `/storefront/product-feeds/{feed}/generate` | `module.ambresoft.product-feeds.public.generate` |
| GET | `/admin/addons/ambresoft/product-feeds` | `module.ambresoft.product-feeds.admin.index` |
| GET | `/admin/addons/ambresoft/product-feeds/create` | `module.ambresoft.product-feeds.admin.create` |
| GET | `/admin/addons/ambresoft/product-feeds/{feed}/edit` | `module.ambresoft.product-feeds.admin.edit` |
| POST | `/admin/addons/ambresoft/product-feeds` | `module.ambresoft.product-feeds.admin.store` |
| PUT | `/admin/addons/ambresoft/product-feeds/{feed}` | `module.ambresoft.product-feeds.admin.update` |
| DELETE | `/admin/addons/ambresoft/product-feeds/{feed}` | `module.ambresoft.product-feeds.admin.destroy` |
| POST | `/admin/addons/ambresoft/product-feeds/{feed}/generate` | `module.ambresoft.product-feeds.admin.generate` |

### GDPR Cookies (`ambresoft/gdpr-cookies`)

| Method | URL | Name |
| --- | --- | --- |
| POST | `/storefront/gdpr-cookies/consents` | `module.ambresoft.gdpr-cookies.public.consent` |
| GET | `/admin/addons/ambresoft/gdpr-cookies` | `module.ambresoft.gdpr-cookies.admin.index` |
| PUT | `/admin/addons/ambresoft/gdpr-cookies` | `module.ambresoft.gdpr-cookies.admin.save` |
| POST | `/admin/addons/ambresoft/gdpr-cookies/renew` | `module.ambresoft.gdpr-cookies.admin.renew` |
| PUT | `/admin/addons/ambresoft/gdpr-cookies/scripts` | `module.ambresoft.gdpr-cookies.admin.scripts` |

The other bundled add-ons (Stripe Checkout, Typesense search, Google Analytics, Google Tag Manager, Cart reminders and
Black Friday Theme) add no routes of their own. Stripe's webhook uses the core route `POST /payments/webhooks/{method}`.
