# Storefront SEO audit and implementation record

Scope: existing storefront only. XML sitemap implementation explicitly deferred. No admin routes, screens, schema or merchant content changes.

## Baseline and critical invariants

Laravel/Inertia React storefront with SSR disabled (`config/inertia.php`). Blade already emits server metadata; React page wrappers update titles only. Layout/page SEO overrides and redirect rules exist. No first-party JSON-LD was found in the inspected server or client renderers. Public catalog queries enforce visibility; actual product cards supply current tax-display prices/promotions, primary currency, stock and SKU. Locale selection is session-based; translated slugs exist but do not establish independent language-addressable responses. Currency format is read from the primary currency. Keep existing routing, fallback translations, canonical slugs, member product identities, category visibility, filters, sorts, pagination, cart, checkout, accounts, addons and layout placement intact.

Critical regression suites: ProductPageTest, VariationGroupTest, CategoryListingTest, CategoryLandingTest, StorefrontLayoutTest, LayoutSettingsTest, SearchTest, CartTest, CheckoutTest, AuthenticationTest, SeoRedirectTest, BlogTest, DirectoryPagesTest and related frontend suites. Test database only.

## Page-type policy matrix

| Existing page type/routes | Metadata and canonical | Robots | Structured data | Future XML eligibility |
|---|---|---|---|---|
| `/` homepage | configured SEO/store identity; absolute root | merchant indexability | WebSite, Organization | yes if indexable |
| nested category landing | own category title/content; own URL | public/ungated only | CollectionPage, BreadcrumbList, actual subcategory ItemList | yes |
| category/brand/feature-option listing | entity title/content; current page canonical | filters/sorts noindex; ordinary pages indexable | CollectionPage, BreadcrumbList, displayed ItemList | unfiltered canonical only |
| `/new`, `/bestsellers`, `/offers` | configured collection title; page canonical | filters/sorts noindex | CollectionPage, displayed ItemList | canonical only |
| product detail (including individual variation members) | actual member/custom SEO; actual member URL | public/ungated + merchant setting | Product, truthful Offer, genuine aggregateRating, BreadcrumbList | yes |
| static content | actual title/custom SEO/content | preview noindex | WebPage, BreadcrumbList | public canonical only |
| blog index/pagination | blog title/content; page canonical | preview noindex | CollectionPage, displayed article ItemList | canonical only |
| blog article | actual article/custom SEO; article URL | preview noindex | BlogPosting, BreadcrumbList | public canonical only |
| `/brands`, existing HTML `/sitemap` | directory title; own canonical | merchant setting | CollectionPage | yes; HTML directory remains unchanged |
| `/search` and suggestions | query-specific/private utility | noindex | no product rich-result graph | no |
| cart, checkout/completion, wishlist/comparison, account, order tracking | utility-specific; no public offers | noindex | none | no |
| identity/password/verification; signed payment/newsletter/cancellation | utility-specific | noindex | none | no |
| age gate, closed store, addon frames, preview, errors | no product data leaked; actual HTTP status | noindex | none | no |
| media, favicon, documents, reel APIs, mutation/JSON endpoints | preserve content types/access rules | utility endpoints excluded where appropriate | none | no |
| admin | existing protection preserved | existing noindex and crawler/access controls | no storefront graph | never |

## Findings → implementation → verification checklist

- [x] Missing first-party structured data → shared storefront SEO projection from already resolved block content → graph/route tests for products, collections, articles and directories.
- [x] Inertia metadata becomes stale; initial tags can duplicate React titles → keyed shared storefront head + same server projection → DOM/head navigation tests and initial HTML assertions.
- [x] Missing canonical/social metadata on home/directories/categories → configured absolute origin, real route and pagination → route tests; no request-host trust.
- [x] Generic/empty category/static descriptions → derive plain text from that entity only; preserve custom overrides → missing/escaped text tests.
- [x] Preview/age gate/frame meta and header inconsistency; utility endpoint coverage → consistent storefront noindex projection/headers without touching admin → route/header tests.
- [x] Product JSON-LD must match discounts/tax/currency/stock → use actual resolved member card and currency_format, no invented identifiers/ratings → regression assertions.
- [x] Crawling/pagination/language limitations → audit real links and response behavior; keep page-specific canonicals, no fabricated hreflang → pagination/localization/redirect tests.
- [ ] Performance/mobile/image behavior → preserve existing responsive media and layout; inspect primary-image loading, measure representative pages where tools permit → record measured results only.

## Current limitations to verify/report

No XML sitemap (deferred). No production domain, Search Console/field Core Web Vitals, or externally reachable production pages supplied. No perfect SEO score or rich-result guarantee implied. Session-selected languages cannot receive reliable independent hreflang annotations without a separately authorized routing decision. CSR full-body rendering must be tested independently from server metadata; this task does not silently enable a new SSR runtime. Trusted merchant custom head HTML can contain duplicate third-party tags; do not delete merchant integrations automatically.

## Implemented behavior and verification

`StorefrontSeo` builds metadata and one escaped JSON-LD graph from the already resolved main-content block. It does not perform independent catalog, stock or price calculations. `ProductPage` retains its existing logo presentation while exposing an actual active brand name even without a logo. Product offers match the visible member price and primary currency, including existing promotions and display-tax logic. Existing digital-product inventory/purchasing rules are used unchanged; no physical shipping or condition is invented for a digital offer. Real approved rating summaries are included; individual reviews and authors are not fabricated.

Blade emits the generated description, canonical, Open Graph, Twitter and JSON-LD in the initial response. Inertia 3's installed native `serverHead` option manages the same keyed tags on subsequent visits; existing React title components remain responsible for page titles. Non-storefront views cannot inherit the shared storefront head. The administration's components/routes were not edited. Private utility headers now cover checkout, search/suggestions, signed flows, tracking, locale switches and documents while preserving existing explicit headers. Errors have noindex headers; store closure remains HTTP 503 with Retry-After. No public content is replaced with an error or gate to improve an SEO score.

Descriptions prefer existing overrides, then that entity's own sanitized text/name. Pagination has page-specific titles and canonicals. The existing listing behavior of clamping invalid/out-of-range page requests is preserved; canonicals use the page actually displayed. Filters/sorts retain noindex and the unfiltered canonical. No hreflang or currency URL system was invented. No XML sitemap, database migration, new dependency, external account connection or commit was introduced. The existing HTML `/sitemap` and robots.txt policy remain intact.

The homepage receives a visually hidden real store/title H1 inside its existing main landmark only if merchant content has no H1. It does not replace merchant headings or add an extra render cycle. Existing product hero eager/high-priority loading, banner/logo preloads, responsive layouts, media eligibility and lazy non-primary images are preserved.

### Actual checks

- Baseline: **107 backend tests passed, 2,910 assertions** before integration.
- Comprehensive storefront regression: **283 passed, 6,118 assertions**, 170.87 seconds. Includes layouts/blocks, widgets, search, variations, authentication, product/category/blog/directory routes, redirects, cart/checkout and robots protection.
- Additional final focused run: **66 passed, 1,717 assertions**; last fallback/canonical/content validation: **46 passed, 1,294 assertions** (overlapping suites, not additive totals).
- Storefront frontend plus new head/heading tests: **75 passed across 9 files**. Types, changed-file ESLint/Prettier, Vite build and PHP formatting passed.
- Expanded full frontend suite: **445 passed, 131 failed across 20 admin test files**. The failures point to the unchanged `resources/js/layouts/admin/AdminLayout.tsx:132`: mocked Inertia pages omit `url`, and existing `url.startsWith(...)` throws. The same code is in HEAD; admin code was deliberately left untouched. This is not a passing full frontend suite.
- Focused PHPStan found no errors in the new SEO projection, but the command remains nonzero for the existing `ProductPage.php:66` Collection variance issue and the configuration's unmatched `larastan.noEnvCallsOutsideOfConfig` ignore pattern. Neither is concealed as a passing static check.
- **31 actual HTTP destinations checked**, including public content, pagination, filters, product, utility/auth paths, admin sign-in, missing URLs, robots.txt and the deferred XML sitemap. Initial HTML metadata and JSON-LD were parsed, and Product price/currency plus collection item URLs were checked against their actual Inertia content payloads. See [HTTP evidence](storefront-seo-http.json).
- Chrome rendered checks cover homepage, landing/listing category, page two, brand, directories, blog index/article, published static page, search, cart, comparison, wishlist and tracking. See [browser evidence](storefront-seo-browser.json). An actual Inertia page-two link updated the title/canonical/24-entry list without duplicate descriptions or schema blocks. Product DOM had one H1 and one of each SEO tag; its Offer was EUR 2799.00/InStock, matching the page. No hydration error was observed on these validated pages.

### Scores and performance limits

No Lighthouse, PageSpeed Insights SEO score, mobile performance score or field Core Web Vitals was measured. Do not interpret assertion counts as an SEO score. The HTTP evidence records single local development response times, approximately **83–504 ms** for HTML responses in that run; these are not production TTFB or Core Web Vitals and are not a controlled before/after performance benchmark. Full page bodies remain client rendered under the existing architecture; essential head metadata and structured data are server rendered. External Google rendering/indexing and production performance must still be checked after deployment.

### Remaining merchant/deployment work

- XML `/sitemap.xml` still returns 404 by explicit scope deferral. The existing HTML site map remains 200 and continues to list actual published content.
- Existing footer configuration points at six missing pages: `/about`, `/contact`, `/gift-certificates`, `/blog`, `/favorites`, `/compare` (all confirmed 404). Correct the saved menu entries through `/admin/menus`, or create the intended content through the existing CMS. Known working alternatives are `/wishlist` and `/comparison`; `/blog/nea` and `/blog/odighi-aghoras` are real blog destinations. The intended about/contact/gift-certificate content cannot be invented. No merchant navigation was silently overwritten and no fake pages were generated.
- Public content that the merchant intentionally gives duplicate custom SEO strings retains those overrides. Empty/thin content can only generate factual names, not invented marketing copy. Populate those pages' SEO descriptions for stronger snippets.
- Configure `APP_URL` to the exact public production origin. Production development/private/Docker origins are refused for generated canonical/schema URLs; a missing valid origin requires configuration, not a guessed domain. Keep production debug disabled using the existing deployment process.
- Session-based locale negotiation remains an architectural limitation for reliable language-specific indexing. Independent hreflang needs stable, genuinely equivalent locale addresses; changing language routing is outside this request.
- Existing crawler exclusions in robots.txt (including the merchant's AI/aggressive-crawler policy) were preserved. Google/Bing public storefront access remains allowed; robots exclusion is not a replacement for authentication.
- Use Google's Rich Results Test on the deployed product URL, Schema.org Validator on category/article graphs, and PageSpeed Insights in mobile/desktop modes for the representative paths in the evidence. Localhost cannot be fetched by those external services. Search Console indexing, field CWV and rich-result appearance have not been verified. Valid structured data does not guarantee a rich result, and collection markup does not guarantee category product rich results.

## Primary guidance consulted

- [Google product snippets](https://developers.google.com/search/docs/appearance/structured-data/product-snippet)
- [Google merchant listings](https://developers.google.com/search/docs/appearance/structured-data/merchant-listing)
- [Google pagination](https://developers.google.com/search/docs/specialty/ecommerce/pagination-and-incremental-page-loading)
- [Google ecommerce URLs](https://developers.google.com/search/docs/specialty/ecommerce/designing-a-url-structure-for-ecommerce-sites)
- [Google breadcrumbs](https://developers.google.com/search/docs/appearance/structured-data/breadcrumb)
- [Google multilingual sites](https://developers.google.com/search/docs/specialty/international/managing-multi-regional-sites)

The requested jsonld.com product reference was reviewed; Google's documentation and the application's actual data were used for implementation decisions.

## Changed files

- `app/Http/Middleware/KeepOutOfSearchEngines.php`
- `app/Storefront/Infrastructure/ProductPage.php`
- `app/Storefront/Infrastructure/StorefrontSeo.php`
- `app/Storefront/Presentation/Http/AccountPageController.php`
- `app/Storefront/Presentation/Http/CartController.php`
- `app/Storefront/Presentation/Http/CheckoutController.php`
- `app/Storefront/Presentation/Http/ComparisonController.php`
- `app/Storefront/Presentation/Http/DirectoryController.php`
- `app/Storefront/Presentation/Http/HomepageController.php`
- `app/Storefront/Presentation/Http/OrderTrackingController.php`
- `app/Storefront/Presentation/Http/ProductPageController.php`
- `app/Storefront/Presentation/Http/SearchController.php`
- `app/Storefront/Presentation/Http/StoreClosed.php`
- `app/Storefront/Presentation/Http/WishlistPageController.php`
- `bootstrap/app.php`
- `docs/audits/storefront-seo-browser.json`
- `docs/audits/storefront-seo-http.json`
- `docs/audits/storefront-seo.md`
- `resources/js/app.tsx`
- `resources/js/components/StorefrontPrimaryHeading.tsx`
- `resources/js/pages/Storefront/Home.tsx`
- `resources/js/tests/StorefrontPrimaryHeading.test.tsx`
- `resources/js/tests/StorefrontSeoHead.test.tsx`
- `resources/views/app.blade.php`
- `tests/Feature/BlogTest.php`
- `tests/Feature/CategoryListingTest.php`
- `tests/Feature/LayoutSettingsTest.php`
- `tests/Feature/ProductPageTest.php`
- `tests/Feature/StorefrontSeoTest.php`
