# Storefront foundation (A101)


Bestseller periods, minimum sales thresholds and category exclusions now follow [General → Catalog settings](general-settings.md#best-sellers); all-time remains the default. This supersedes fixed all-time descriptions below.

This phase adds the editable homepage and layout engine. It uses the existing Laravel/Inertia/React application, with independent public components. Final storefront design and product/category/search/cart/checkout page implementations remain later phases. The earlier proposal that layouts must be entirely code-owned is superseded by the explicitly authorized, bounded relational grid/block model below. This is not arbitrary freeform positioning or executable merchant code.

## Ownership and persistence

`App/Storefront` owns layout persistence, registered presentation contracts and the public homepage. `Application/Contract/BlockType` and `Application/Service/StorefrontRegistry` are framework-free extension points; `Domain/Service/LayoutRules` validates structure. Infrastructure owns Laravel queries, transactions, caching, content resolution and first-party definitions. The admin controller is `App/Admin/Controllers/LayoutController`; public controllers are `Storefront/Presentation/Http`.

Migration `2026_10_29_000100_create_storefront_layouts` creates:

- `storefront_layouts`: registered page type and optimistic version. The initial Default and Homepage records are inserted once by the tracked migration.
- `storefront_grids`: UUID identity, owning layout, region, nullable parent, absolute width, row and sibling position. Composite foreign keys keep parents in the same layout; deletion cascades down the grid tree.
- `storefront_blocks`: UUID identity, owner layout, immutable registered type, internal name, template identifier, safe class names and validated scalar JSON settings. Content references and translations are separate relations.
- `storefront_block_translations`: locale-linked optional public title and sanitized HTML. Active languages are edited; saved inactive translations are preserved. Requested-language text falls back to the configured default, then existing English content. Internal names never become public headings.
- `storefront_block_selections`: ordered product/category/banner/banner-group foreign keys, exactly one target per row. Deleting a selected record removes its reference; resolvers also filter stale cached selections against live eligibility.
- `storefront_placements`: grid, block, order, enabled state and three device flags. Composite foreign keys keep placements and blocks in their owning layout.
- `storefront_overrides`: Homepage's explicit override of a Default placement: disable it or use an independent block configuration. Reset deletes the override. Deleting a shared source also removes its override configurations and increments dependent layout versions.

A configured block belongs to one placement or override. “Use existing” and Duplicate copy configuration into a new identity; subsequent edits are independent. Default placements are genuinely shared through inheritance, rather than copied into every page. Homepage controls its `content` region; Default controls top panel, header, navigation, before/after content and footer. Homepage overrides retain the shared position and device settings, and can change content/configuration or disable the placement. Moving a shared block is done in Default.

Every save validates the entire snapshot and commits transactionally under an advisory lock. A stale version fails without overwriting another editor. Cross-layout UUIDs, duplicate placement slots, unknown types/templates, malformed settings and invalid selections fail validation. Settings a type does not declare (for example a setting removed after blocks were saved with it) are dropped rather than refused, so old keys neither block a save nor survive it. When rendering, a stored value the type no longer accepts (a removed option, a narrower range) falls back to that setting's default instead of hiding the block (`LayoutRenderer::settings()`); staff saving the layout must still enter valid values. Protected blocks enforce configure/move/disable/delete policy on the server. The shared Identity block is code-controlled and cannot be disabled, reconfigured or removed; it uses Company data and the existing account entry point. No cart, wishlist or comparison placeholders are introduced.

## Sixteen columns and responsive behavior

Widths are **absolute columns**, 1–16. Each row's sibling widths must sum to at most its parent's width (16 at region root). Thus an 8-column parent can contain 4+4 children. Rows explicitly allow another horizontal group beneath the preceding row. Row then position determines grid order; placement position determines block order. Blocks render before child-grid rows inside a grid. Nesting is bounded to eight levels, with at most 100 grids and 150 placements per layout.

The editor uses the CS-Cart-style Create new / Use existing block dialog with explicit single-item or carousel choices; persistent per-grid type dropdowns are not used. Each block fills its grid. Independent parallel content is arranged through sibling grids, rather than an automatic multi-item grid inside a block. Banner carousels display one full-grid slide at a time. The editor provides nested boundaries, widths/rows, block dialogs, independent content/General tabs, searchable ordered selectors, duplication, switches, confirmation dialogs and keyboard-accessible move controls. Block configuration is staged with Apply; **Save layout** persists all staged changes. Existing unsaved-change preferences guard navigation. Grid changes are validated in the dialog and on the server. Native HTML drag-and-drop and the same DragGrip/arrow-key pattern as Order Statuses reorder grids and blocks. Blocks can drop into another grid, including an empty one. Grid moves preserve existing sibling row slots; moves that exceed a row’s capacity or introduce cycles are rejected, with grid settings available for explicit structural changes. Dragging stages changes for Save layout.

Default theme breakpoints: mobile below 768px, tablet 768–1023px, desktop from 1024px. Desktop preserves all sixteen-column relationships; smaller screens stack grids. Device flags affect CSS visibility independently from stacking, with one copy of the content. Images retain intrinsic dimensions and responsive size limits. Carousels use native horizontal scrolling, scroll snap and accessible previous/next controls.

## Content resolution

`LayoutRenderer` resolves active theme, the requested registered layout and shared Default layout, applies explicit overrides, batches block configurations by registered type, and sends only resolved public content/tree data. Empty, disabled, unavailable or malformed blocks are omitted; empty grids are pruned. Unknown templates never become import paths.

Functional first-party types:

| Type | Filling modes | Default templates |
| --- | --- | --- |
| Banners | Ordered manual selection; latest by `created_on DESC, id DESC`; existing group by `position ASC, id DESC` | Single item, carousel |
| Products | Ordered manual selection; newest by `created_at DESC, id DESC` among products created within the **New products period** (General settings → Catalog, default 90 days); paid non-void all-time bestsellers by units DESC then ID DESC (products never sold do not appear); applicable product sale by newest order. The automatic sources accept an optional **Only from categories** filter (each chosen category includes its subcategories; empty means every category) | Single item, carousel |
| Categories | Ordered manual selection | Single category, carousel |
| Brands | Ordered manual selection; all-time paid-unit bestsellers; alphabetical eligible brands | Single brand, carousel |
| Products by brand | One brand, with all existing Products sources and display settings | Single product, carousel |
| HTML Content | Localized rich text through the existing basic rich-text editor and Symfony sanitizer | HTML |
| Identity (protected) | Company name/logo, existing account link | Identity |

Banners reuse Marketing's existing groups, content, images, safe URL policy, external target flag, activation, start-inclusive/end-exclusive timestamps and customer groups. The public theme and admin preview share `components/banners/BannerCanvas.tsx`: the saved background, object image/YouTube/eligible associated products, masks, typography, title formatting, alignment, padding and button styling render through the same component. Desktop/tablet/phone canvases retain the preview’s 1200×450, 768×420 and 375×520 design sizes, scale to their containing grid and switch at the theme breakpoints. Device overrides inherit PC values; localized content and media use configured default-language fallback. A single canvas is mounted for each banner, without duplicated hidden device content. Background MP4 files use the existing private video storage and an owner-checked public stream. Full-slide links retain external-link settings; embedded YouTube stays outside links and uses a linked CTA. Banner data and associated eligible product objects load in batches. No banner records are copied.

`Catalog/Infrastructure/PublicCatalog` centralizes the homepage publication scope: active, undeleted complete products, normal active category ancestry and customer-group access. Hidden products are excluded. Categories use their existing hierarchy and access rules. Product availability uses `ProductRules`, stock/quantity constraints, configured inventory policy and available-from date. Variant identities are evaluated on their own stock. There is no purchase flow in this phase.

Prices are decimal strings from the primary currency. ProductSales resolves actually applicable date/group/minimum-quantity/quota discounts in batches; the catalog's minimum purchasable quantity supplies quantity context. TaxCalculationService applies the existing catalog tax-display setting, customer tax profile/estimation policy, and unit-price tax calculation in one batch. Its currency precision is loaded once per calculation, avoiding a per-card lookup. Cart-level promotions are not applied to an unformed cart. “On sale” requires an effective product-level discount, rather than the mere existence of configuration or a reference price. No featured flag exists, so a Featured source is not offered. Bestseller rankings reuse Reporting's paid/non-void order definition, including recorded captured payments; no popularity metric is invented.

Products/categories are presentation cards in this foundation and do not link to unimplemented detail routes. No fake store content is seeded. Starter banners/products blocks select current records; the category showcase starts empty for manual selection. Empty stores render the shared identity and omit empty sections.

## Theme and block extension guide

`config/storefront.php` selects the bundled `default` theme. There is no theme upload or switching UI. Backend registration is in `StorefrontServiceProvider`, before enabled add-ons load. A trusted application/add-on provider may register another `BlockType`, theme or page through `StorefrontRegistry`:

```php
$registry->block(new YourBlockType);
$registry->templates('default', ['your_template']);
```

A BlockType supplies a stable ID, translated label, field descriptors/defaults, supported templates, allowed pages, capability, whether owners can create instances (`creatable`), protected-action policy, a settings validator and a **batch** content resolver. Supported editor fields are select/number/boolean/text/richtext and ordered selections from the four relational target families. Custom template labels use `template_labels` and custom select choices use `option_labels`, mapping IDs to the provider’s registered UI translation keys. Extra validated resolver payload fields are available through the public content record. Additional relational target families require an explicit migration/adapter, not unvalidated IDs in JSON. Trusted extension definitions are code; the merchant never edits a schema or PHP path.

A future core page registers a page type and a code-controlled block with explicit action policy. Its resolver invokes the relevant core service. This contract does not replace product, category or checkout logic with HTML. New layouts need an idempotent migration to create their initial persisted record.

Frontend theme files live under `resources/js/storefront/themes/Default/`:

- `index.tsx`: trusted theme and template registration, typed public payload rendering.
- `theme.css`: scoped starter styling, grid/responsive rules and carousel presentation.
- `../../registry.ts`, `../../types.ts`: stable theme/template registration and public projection contract.

`pages/Storefront/Home.tsx` discovers only build-time theme entry files through Vite's glob. A developer adds a trusted theme directory, registers its matching backend metadata and selects it through application configuration. Custom templates register through `registerTemplate(theme, id, component)`; database strings cannot load code. The Default theme's functional styling deliberately leaves final visual design open.

The existing Inertia app has SSR disabled and no Node SSR service. This phase keeps that established runtime: Laravel resolves the complete layout/content projection; React renders it. The admin frontend does not reconstruct public pages. HTML SSR, production SEO strategy and locale URL design remain explicit future decisions, rather than an unconfigured second public framework.

## Layout page settings and merchant-added layouts (A103)

Every layout row carries page settings, following CS-Cart's layout (location) settings: **name** (merchant-added layouts only), **page title**, **meta description** and **meta keywords** per language in `storefront_layout_translations`, and one **custom HTML** (`storefront_layouts.custom_html`, at most 20,000 characters, placed in the page `<head>`). The editor opens them from the gear beside the active layout tab. *Copy to other layouts* beside the keywords and the custom HTML is an action of that save, not a stored flag. Settings have their own `settings_version`, separate from the structure's `lock_version`, so changing them never invalidates unsaved grid edits or the structure cache.

Merchants add layouts with **Add layout page…**. A merchant-added layout has a key `custom.<random>` and behaves like the homepage: the shared Default regions plus one content region, and the block types the homepage allows. It can be linked to one static page (Website → Pages) through the nullable, unique `page_id` (deleting the page unlinks the layout; a page uses one layout). Linking a page adds the protected **main content** block to the layout (first row of its content region; staff move it like any block, but cannot remove it while the page is linked) and unlinking removes it again (`LayoutSettings::syncMainContent()`, migration `2026_11_06_000400` for layouts linked before). The storefront then serves the page at its SEO name (`/privacy-policy`), see [static pages](website-pages.md#storefront). Layouts without a page (login, cart, …) are not part of this; the built-in Default and Homepage layouts cannot be deleted, moved or linked.

**Precedence.** `LayoutSettings::seo($page, $locale, $override)` returns the title, description and keywords of a rendered page: a non-empty override (the SEO of the page being shown — a static page now, a product later) wins, then the layout (current language, then default language), then the Default layout. The custom HTML belongs to the layout alone; use *copy* to share it. The homepage already renders through this; a future page controller passes its own `seo_title`, `meta_description` and `meta_keywords` as the override.

**Indexing.** `storefront_layouts.indexable` (default true, so every page is indexable) is a checkbox in the settings. When off, the page carries `<meta name="robots" content="noindex, nofollow">` and an `X-Robots-Tag` header; a page's own SEO may pass `indexable` in the override. `cms:demo-store` fills sample titles and meta texts (English and Greek) for the built-in layouts only where they are empty.

**Security.** Custom HTML is trusted markup (analytics tags and similar) printed unescaped in the storefront head, so only staff with `layouts.manage` can set it; it is never printed in the administration, and `html`, `head` and `body` tags are refused so it cannot reopen the document.

## Product pages (A105)

A product's address is its category path plus its slug (`/tech/laptops/macbook-pro-14`), the same URL the product cards link to. `Route::fallback` sends every address no other route matches to `ProductPageController`, so existing routes always win. `ProductPage::resolve()` finds visible products by slug: the current address renders; another valid address of the same product (another language's slug, a moved category) redirects with 301; anything else is 404. Disabled, hidden, deleted, incomplete or group-restricted products are never shown (`PublicCatalog::products()`), and a closed store answers 503 as on the homepage.

The page uses the built-in **Products** layout (`page_type = products`, migration `2026_10_31_000200`): the Default shared regions plus a content region holding the **main content** block. That block type is registered like the others, but is not creatable, cannot be deleted or disabled, and is allowed only on the Products layout. Its settings switch page sections on or off (all on by default): frequently bought together, similar products, description, specifications, reviews, questions (FAQs), price history and the sticky product bar. Merchants can move it and place banners, product carousels or HTML above and below it. Its payload is the page's product, passed to `LayoutRenderer::page(..., $main)` and returned by `BlockContent` only on that page.

`ProductPage::data()` builds that payload from existing read models only: the shared product card (price with tax display, reference price, stickers, availability), translated name, short description and description (sanitized again on output), pictures in order (`PublicMedia`), the category trail (`CategoryUrls`), the availability status label, free shipping and release date, feature values grouped by feature group (active, applicable, non-empty), the first six as highlights, the variation group's choices (each option links to the visible member that keeps the other current choices), rating summary (`ProductRatings`), similar products and frequently bought together (`ProductRecommendations`, A104). The payload also carries whether the product is returnable, its active linked FAQs (sanitized answers, current language with fallback) and its price history: the regular price changes from `ProductPriceHistory`, not temporary sales, shown with or without tax like every catalog price. Approved reviews come as a separate `reviews` prop, paged with `?reviews_page=` through a partial reload. Signed-in customers post reviews to the existing `reviews.store` route; guests are sent to sign in.

SEO: the product's `seo_title`, `seo_description` (otherwise the short description or description as plain text) and `meta_keywords` override the Products layout settings, which fall back to the Default layout (A103). The page also gets a canonical link and preloads its first picture.

The theme renders the block with `themes/Default/ProductPage.tsx`, modelled on the reference supplied by the merchant. The parts are: a gallery with thumbnails and swipe; an information column with the category back link, rating, short description, highlights, variation tiles or pills and the price box; then frequently bought together, where the visitor can untick companions but not the main product and the total is recomputed in the browser in whole minor units (no floating point), similar products in the existing product carousel with a product block's default presentation, the struck-through reference price with the saving (`saving`, computed on the server from the displayed amounts), the description, the specifications and the reviews. A sticky bar with section links, price and cart button appears once the price box scrolls away. Shared theme pieces (picture, money, product card, carousel) moved to `themes/Default/parts.tsx`. The cart button is presentational until the cart exists, as on product cards.

## Wishlist page (A106)

`/wishlist` (customers only; guests are sent to sign in; never indexed) renders the built-in **Wishlist** layout, whose protected **Main content** block (the same block type as the product page) shows the customer's lists as tabs and the saved products of the chosen list (`?list=`) in a grid. The block's **cards per row** setting (default 5, 1–8) is a `pages: ['wishlist']` field, shown only when editing that layout; phones always show two and tablets at most three. The grid reuses the storefront product card (`Product` in `themes/Default/parts.tsx`) with the product block's default presentation, so it follows *Product card style* in General settings → Catalog. Every card (blocks, carousels, similar products, the wishlist) carries `favorite` (in the signed-in customer's general list, one query per render) and its heart opens the "add to list" window through `WishlistProvider`; the heart shows only where the block's *show favourites* option is on and wishlists are enabled. The product page has round heart and share buttons beside the main picture (`ProductActions`): the heart opens the same window, and share uses the device's share sheet or copies the page link.

## Order tracking

`/order-status` renders the built-in **Order tracking** layout (`page_type = order_tracking`, migration `2026_11_06_000500`) with the protected main content block: a plain GET form (`?number=`, works without scripts, 20 lookups a minute). `Storefront\Infrastructure\OrderTracking` answers:

- a **guest order** (no customer): shown to anyone with its number;
- a **registered customer's order**: a visitor who is not signed in is asked to sign in (`state = login`); the number alone is kept in the session (`order_tracking.number`) and the customer login returns to `/order-status?number=…` (only a valid order number is ever used, so it cannot redirect elsewhere; the usual intended-URL is still cleared on sign-in). Signed in as the owner, the answer adds a link to the full order in the account; any other customer gets the same "not found" as a number that does not exist.

Order numbers are sequential, so the page shows only what tracking needs: number, date, current status and the status history. Names, addresses, products, amounts and e-mail never appear there. Private and never indexed.

## Category landing pages

A category marked **Landing page** in the category editor (`categories.is_landing_page`, migration `2026_11_04_000100`; never the system category) is served at its own storefront path (the category slugs from the top down, e.g. `/technologia/gaming`) with the built-in **Category landing** layout (`page_type = category_landing`): the Default shared regions plus a content region holding the protected **main content** block. `CategoryLanding` finds the visible landing category whose path matches exactly (the visitor's customer groups and every ancestor's status apply) and returns its trail, its name and its visible subcategories in position order as cards (block image first, then the category image, as on category blocks). The product page fallback route resolves landing categories first, then product listing categories (every other visible category, see [category listings](category-listings.md)), then static pages, brand landing pages and products. The category's own SEO title, description and keywords win over the layout's.

A landing category lists subcategories instead of products, so it cannot hold any: turning a category that has products into one is refused, and `ProductState::validCategory()` treats a landing category as unusable for products. The flag is set from the editor only; CSV imports leave it unchanged. The main content block's settings on this layout are **cards per row** (default 5, 1–8; tablets show at most four), **card shadow** (default on), **on mobile** grid or carousel (default grid) and **cards per row on mobile** (default 2, 1–4).

A landing category whose age check is on (its own, or the strictest of its ancestors', via `CategoryAgeRestrictions`) sends only `age_gate` (the age and the category's own warning; empty uses the theme text) with no subcategories, description or FAQs until the visitor confirms. `POST /age-verification` (`age`, 30 a minute) remembers the highest confirmed age in the encrypted `age_verified` cookie for a year, so limits up to it open directly. The theme (`AgeGate.tsx`) covers the page with the confirmation card. A category's optional **banner image** (`categories.banner_image_media_id`, migration `2026_11_06_000200`, set in the category editor's Image tab next to the category and block images; private storage, served like them) shows full width under the title and above the description; the age gate holds it back. An optional **banner link** (`categories.banner_link`, migration `2026_11_06_000300`, the menu link rule `MenuRules::safeUrl()`: a store path or an http(s)/mailto/tel address, never `javascript:`) makes the banner clickable. The category's active FAQs (`StorefrontFaqs`, shared with product pages) follow the subcategories. Product pages apply the same check (`AgeGates`) to the product's category: until the visitor confirms, the Products layout's main content block carries only `age_gate`, nothing of the product. Gated pages (landing, listing or product) are sent with `noindex`. Category listings use the same gate and send no products or counts until the visitor confirms.

## Cart

A cart (`carts`, `cart_items`, migration `2026_11_05_000100`) is purchase intent: lines of a product and a quantity, never prices. Each cart has a public `uuid`, the identifier every client uses (the storefront today, a mobile app later). A signed-in customer has one cart (partial unique index on `customer_id`); a guest's cart is named by the encrypted `cart` cookie (30 days from the last change, `HttpOnly`, `SameSite=Lax`). `CurrentCart` resolves the visitor's cart: browsing never creates one, the first addition does. When a guest with a cart signs in, the cart becomes the customer's, or is merged into the one they already have (quantities of the same product add up where the product's rules allow it, otherwise the larger allowed quantity is kept), and the cookie is dropped. A cookie that names a customer's cart is ignored.

`Cart\Infrastructure\Carts` checks every change on the server: the product must be visible to the visitor (`PublicCatalog::products()`), priced and not hidden by *Hide prices*, released (`available_from`), and the quantity must satisfy its minimum, maximum and step and the stock rule (`ProductRules`; backorders and switched-off tracking lift the stock check). Adding raises a quantity to the smallest one the product allows, so product cards always send 1 and the product page sends the chosen quantity; setting a quantity on the cart page must be exactly allowed. At most 100 lines and 100,000 units per line (CHECK). The JSON routes are `POST /cart/items` (`product_id`, optional `quantity`), `PATCH /cart/items/{product}` and `DELETE /cart/items/{product}`; each answers with the whole cart (`CartPage::data()`) and the header count.

`/cart` renders the built-in **Cart** layout (`page_type = cart`) with the protected main content block; private (`no-store`, `noindex`). `CartPage` shows the lines as the standard product cards (live prices, sales, customer group prices, tax display), each with the quantity rules its stepper follows (the maximum respects enforced stock) and a line total. A line that cannot be bought as it stands says why and is left out of the total; lines of products the visitor can no longer see are not shown or counted, and return if the product does. The page puts the lines beside a summary that stays in view while scrolling; quantity changes wait 450 ms after the last click before one request is sent. Every storefront page carries `cart_count` for the header badge; additions show a short notice with a link to the cart. On desktop and tablet the header's cart icon opens a mini cart (`themes/Default/MiniCart.tsx`), which reads `GET /cart/items` each time it opens: the lines with picture, name and quantity, removal, and a link to the cart. The Identity block's *Open a mini cart* setting (`show_mini_cart`, on by default; migration `2026_11_05_000400` sets it on existing headers) turns it off; phones (below 640px) always go to `/cart`. Settings → General → Catalog → Prices → *Disable cart and checkout* (`cart_enabled`, on by default, migration `2026_11_05_000500`) inversely edits the saved enabled value and closes `/cart`, its JSON routes and `/checkout` (including subpaths) with 404 when checked; the theme receives `cart_enabled` and drops every add-to-cart button (cards, product page, bought-together, sticky bar), the quantity stepper and the header cart icon. Carts are kept. The [checkout](checkout.md) honours the same switch.

A cart remembers one coupon and one gift card (`carts.coupon_id`, `carts.gift_card_id`, migration `2026_11_05_000200`), entered in one field: `POST /cart/codes` (`code`; a `GC-…` pattern is a gift card, anything else a coupon; unknown codes are refused, 20 attempts a minute) and `DELETE /cart/codes/{coupon|gift_card}`. Nothing is reserved or redeemed. Every calculation uses `OrderPricing` for the buyable lines, their actual quantities and current customer, so automatic promotions apply without a code, quantity sales and product-promotion competition follow the order rules, and a valid coupon participates once at its existing priority. The summary shows each automatic promotion separately from the coupon. Tax-inclusive savings are attributed by line and reconciled at currency precision; optional net totals come from the tax service after discounts. Pricing uses the customer's default tax addresses/exemption, or store estimates for a guest, until [checkout](checkout.md) collects an address. The gift card then pays as much of the rest as its balance covers (`GiftCardRules::applicable`); its balance remains unchanged. A code that stops applying stays in the cart with its reason and is left out of the total, while automatic promotions continue to apply. A guest's codes move to the customer's cart on sign-in unless it already has its own. *Allow only one coupon per order* refuses a second coupon until the first is removed (off, it replaces it). *Enable coupons and gift cards in the cart* (`cart_codes_enabled`, on by default, migration `2026_11_05_000300`) removes the field and the code routes (404) when off; codes already entered are kept but ignored, while automatic promotions remain enabled. No migration is needed for automatic cart promotions; rebuild the frontend and hard-refresh.

Each automatic promotion shows an info tooltip beside its name only when a merchant-authored description exists. It uses the admin description in the shopper's language, falling back to the saved default-language description. Empty descriptions show no tooltip; no description is generated from eligibility rules. Only this customer-facing text reaches public props, not internal condition/group/target records. The existing storefront tooltip supports hover, keyboard focus, click/tap and Escape.

Staff with **carts.view** see every cart under Catalog → Carts: owner (customer or guest; e-mail only with `customers.view`), products, units, created and last change, with search, owner and contents filters. A cart's page prices its lines for its owner as the storefront would now and lists lines the owner can no longer see. Guest carts are kept until removed; there is no automatic expiry yet.

## Comparison page

`/comparison` renders the built-in **Comparison** layout (`page_type = comparison`, migration `2026_11_03_000200`): the Default shared regions plus a content region holding the protected **main content** block (allowed on the Products, Wishlist, Account and Comparison layouts). Comparison is open to guests and customers: the list is kept in the visitor's session (`ProductComparison`), never in the database, and survives signing in. `PUT/DELETE /comparison/products/{product}` and `DELETE /comparison` change it and answer JSON; everything, including the page, is a 404 while **General settings → Catalog → Allow customers to compare products** is off. The store limit is **Products compared at once** (`comparison_max_products`, 2 to 10, default 4); a product past it is refused with a message. Only products the visitor can see can be added; products that stop being visible leave the list when the page is read. The page is private (`no-store`, `noindex`).

The page shows the compared products as columns (picture, name, rating, price, remove) and a feature table built from the product page's feature values. A row is *similar* when every product has the same value and *different* otherwise (a product without the feature counts as different); a switch shows all, only the differences or only the similarities. The comparison button (two inward arrows) is part of the round buttons beside the product page's main picture, above share, and sits under the heart of the cards of [category listings](category-listings.md); product blocks and carousels do not carry it. Wide tables scroll sideways on phones.

## Account pages (A108)

`/account`, `/account/orders`, `/account/addresses`, `/account/reviews` and `/account/password` (customers only; private, never indexed, `no-store`) render the built-in **Account** layout (page type `account`, inheriting the Default shared regions). Its protected **Main content** block (the block type of the product and wishlist pages; no settings of its own here) shows a menu of the account's parts and the open part; `AccountPageController` passes `content.account` with the `section` and that section's data (`AccountPage`). Mutations (profile, addresses, password, reviews, account deletion) keep their existing `CustomerSelfService` routes. Layout SEO, custom head HTML and the shared header/footer apply as on other pages. See [customers](customers.md).

## Security, caching and operations

`layouts.view` and `layouts.manage` are persisted capabilities, enforced by the admin controller/write service. Type-specific capabilities and protected policies are also enforced for mutations. CSS classes accept only ASCII letters/digits/underscore/hyphen/space. HTML is sanitized on write and read; scripts, event handlers and unsafe links cannot execute. Plain text uses React escaping. Only registered templates are rendered. Administrative names, access targeting, audit fields, notes and media storage keys are excluded from public props; validated presentation settings are included only where the theme needs them. Storefront closure still returns the existing localized HTTP 503 screen.

Existing images remain private originals in Media. `/storefront/media/{kind}/{owner}/{media}` verifies the owning product/category/banner/company relationship, current eligibility and permitted image/video MIME on **every request**, then streams with `nosniff` and private/no-store headers. Missing files are omitted by content resolvers. It never exposes arbitrary storage paths or a generic media-ID download. Public page props likewise use private/no-store because content may vary by signed-in customer.

Only resolved **structure/configuration** is cached using Laravel's existing cache. Every read checks the live layout version; successful saves increment it, making prior cache keys unreachable immediately. Old keys expire after 300 seconds. Product prices/stock/taxes/access, banner schedules/content/images, category visibility and HTML content eligibility are resolved live, so time boundaries and catalog mutations require no invalidation jobs. Selections and content records are loaded by family in batches; automatic product sources use deterministic bounded queries. Sale discovery scans batches of 100 candidates through ProductSales until the configured limit is filled; very large sparse-sale catalogs may require a separately designed indexed eligibility projection later. Bestseller selection uses one SQL aggregate of authoritative paid order lines; it is not calculated per card.

Audit triggers cover all layout relations. Saves are grouped as a storefront-layout operation; HTML bodies are excluded from audit snapshots. A layout version changes after every authorized save. No new queue or scheduling system is introduced.

After deployment run ordinary `php artisan migrate`, `php artisan cms:permissions-sync`, then `pnpm build`. Grant `layouts.view`/`layouts.manage` to staff through Roles; existing custom grants are preserved. Website → Layouts opens Homepage with Default available as a tab. Running normal migrations again does not duplicate starter configuration.

Category blocks expose the existing Card shadow checkbox in Content. It defaults off to preserve existing layouts, is validated as a boolean on the server and applies to both single-category and carousel cards. Brand logo blocks retain their existing presentation.

## Sitemap and alphabetical brand directory (A124)

The built-in **Sitemap** (`/sitemap`) and **All brands** (`/brands`) layouts each inherit Default's shared regions and start with one protected main-content block in a full 16-column content grid. Website → Layouts manages their structure and localized SEO settings just like other system pages. The additive `2026_11_13_000100_add_storefront_directory_layouts` migration creates them once and preserves existing records if rerun. The pages render through the existing `Storefront/Page` entry and registered `main_content` template; no new theme or page-storage system exists.

The visible HTML sitemap has Store information (Information / My account columns) followed by the hierarchical category directory in three desktop columns. It lists active information pages that have a linked storefront layout, are within their availability period and are accessible to the current customer group. Hidden, disabled, unavailable and unlinked pages are omitted. Root URLs already handled by a built-in route or visible category are omitted from the information-page list. Page names/slugs use the existing locale fallback. All brands is linked as a built-in information entry. Account, cart, checkout and favourites shortcuts use existing routes; disabled cart/checkout or wishlist features omit their shortcuts. Category names and full paths use `PublicCatalog` and `CategoryUrls`, excluding the recovery category and inaccessible/disabled ancestry. No individual products are enumerated.

All brands lists every active, named dedicated brand, including brands without products. Its localized names are sorted using the existing PHP Intl collator. Letter headings normalize accents and case (including Greek); nonletter initials appear under `#`. Letter jump links target the corresponding headings. Links use the existing `CatalogLandingPages::brandUrls()` rules, so disabled brand landing pages or URLs owned by another route/page/category leave names as plain text. Legacy feature-option landing pages remain governed by the existing setting; the brand index does not turn options into dedicated brands or change the setting.

Both pages read live content even when layout structure is cached and send private/no-store responses because shared header data and sitemap visibility depend on the visitor. Store closure and layout indexing/SEO settings apply normally. UI keys are supplied in all six shipped languages. Existing merchant menus are preserved; add `/sitemap` and `/brands` wherever desired through Website → Menus. Additional link sections can use existing layout HTML blocks; no separate sitemap-management module is introduced. This feature does not generate an XML sitemap or modify robots.txt.

The reference behavior comes from [CS-Cart's public Sitemap documentation](https://docs.cs-cart.com/latest/user_guide/website/sitemap.html): default category and information links with optional custom groups. No CS-Cart source code, theme assets or schema is used.

### Product-page newest and recently viewed products

The Products layout's protected Main Content block owns `show_newest` (true),
`newest_same_category` (false) and `show_recently_viewed` (true). Sections follow
Similar Products in the order Newest, Recently Viewed, using the same product
cards and carousel. Empty or disabled sections render no container. Newest uses
the existing product block resolver and configured new-product age (90 days by
default); category matching uses the current product's assigned category through
the existing subtree filter. Other blocks retain their existing filters.

History records the latest 12 unique successful product views, ordered by actual
UTC view timestamps. Guest entries live in the existing visitor session; signed-in
entries belong to the authentication identity in PostgreSQL (including registered
customers awaiting verification). Session creation merges and clears the guest
entries, keeps the latest timestamp per product, and trims to 12. Account writes
serialize on the identity row. Logout clears the visitor session. Rendering filters
history through the public listing scope and omits the current product; hidden
carousel settings do not stop tracking. HEAD requests, prefetches, redirects and
age-gated pages do not record views. Apply migration
`2026_11_18_000200_create_recently_viewed_products` before deploying the code.

The Main Content editor also owns `section_order`, an exact permutation of
Similar, Newest, Recently Viewed, Description, Specifications and Reviews. Its
sortable rows reuse the header icon-order control with inline visibility switches;
the existing `show_*` settings retain their values. Saved blocks without an order
use that current sequence. Questions, documents, price history, the scrolling
product bar and Bought Together remain under Options
and do not participate in sorting. Bought Together stays above the ordered area;
questions stay below it. The newest-category checkbox lives inside the Newest
sortable row and defaults to off. No new layout or database entity is introduced.

Products blocks also offer `recently_viewed` as a filling source. They read the
same visitor history and reuse public product eligibility, category subtree
filters, item limits, cards and templates. Filtering precedes the limit and
preserves viewing order; an empty eligible history renders no block. No new
tracking mechanism or settings store is introduced.

### Signature product cards

Settings → General → Catalog offers Classic, Showcase and Signature through the
existing `product_card_style` setting. Signature shares Showcase's card controls
and live product data, keeps enabled brand/category labels above the title, shows
the current price before the reference price and moves the cart action into a
full-width button below the details. Its colours follow the active storefront
palette; narrow cards, mobile and unavailable products use an accessible icon-only cart button. All
existing product-block display settings still apply. No gallery controls are
rendered for the single card image. Apply migration
`2026_11_18_000300_add_signature_product_card_style` before deployment; existing
selections remain unchanged. Rollback maps Signature to Showcase before restoring
the older database constraint.

### Mobile listing rows

Product-listing Main Content blocks expose `mobile_products_per_row` as a select
with only `1` and `2`; the server validates the same two values. Old blocks default
to two columns. One column uses the existing card's image on the left and details
on the right below 768px, preserving each style's cart action, variations, stickers
and display settings. Two columns retain the existing grid; desktop column counts
are independent. The category layout on this local installation is configured
for one mobile column. No schema change is needed.

Tablet listings (768–1023px) use their own `tablet_products_per_row` dropdown:
two products by default, optionally three. Existing layouts pick up the default
without a data migration. Mobile and desktop column settings remain independent.

In the one-column mobile listing, mobile-visible image stickers appear above the
product title instead of covering its picture. Other layouts retain the configured
image placement; sticker colours, links and device visibility are unchanged.

For the one-column mobile Showcase card, its corner cart button anchors to the
bottom-right of the whole card, outside the photo. Wishlist and compare controls
form a column at the top-right above the cart; the details reserve horizontal room
for these actions. Variations follow the rating and prices align at the bottom,
to the left of the cart. Other layouts retain their existing control positions.
