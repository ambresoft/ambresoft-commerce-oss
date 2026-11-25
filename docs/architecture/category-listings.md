# Category product listings and filters (A114)


Bestseller periods, minimum sales thresholds and category exclusions now follow [General → Catalog settings](general-settings.md#best-sellers); all-time remains the default. This supersedes fixed all-time descriptions below.

## Which categories

A visible category whose **Landing page** flag (`categories.is_landing_page`) is off is a product listing: its storefront path (category slugs from the top down, e.g. `/texh/tilefonia/smartphone`) renders the built-in **Category** layout (`page_type = category`, migration `2026_11_08_000100`). Landing page categories keep the Category landing layout and show subcategories. `CategoryLanding::resolve(..., $landing)` finds both kinds with the same visibility rules (customer groups and every ancestor's status); the system category, disabled categories and wrongly nested paths stay 404. No category flag, type or record changes.

A listing shows the eligible products (`PublicCatalog::listed()`: active, complete, visible to the visitor's groups, the store's out-of-stock rule) of the category **and all its subcategories**. Completeness already requires products to sit in leaf categories, so a parent listing is the union of its leaves. Its visible child categories are shown as links above the products. The category's trail, name, banner, description and FAQs come from `CategoryLanding::data()`, shared with landing pages (`CategoryHeader` / `CategoryFaqs` in the theme). Age-restricted categories show only the age gate and send no products or counts.

SEO: the category's own `seo_title`, `seo_description` and `meta_keywords` win over the layout's settings (the layout's page title is a suffix). The canonical address is the plain category path (plus `?page=N` beyond page 1). Any filter or sort in the address sends `noindex, nofollow` (meta and header) and points the canonical at the plain category.

## New products, best sellers and offers

`/new`, `/bestsellers` and `/offers` (explicit routes ahead of the product fallback, `ProductPageController::collection()`) list the whole store with the same blocks, filters, sorting and cards, each on its own layout (`new_products`, `bestsellers`, `offers`, migration `2026_11_08_000500`: filters 4 + main content 12, configured like the Category layout). *New*: created within the *New products period*. *Best sellers*: every visible product with paid, non-void units, most sold first under *Recommended*. *Offers*: every visible product whose sale applies to the visitor (`ProductSales`, as the *On sale* shopping option). The page title is the translated page name (`storefront.page_*`) with the layout's title suffix; the canonical address is the plain path. These pages add a **Category** filter (`category=12,15`): the product's own (leaf) category with counts, most products first; only categories the visitor may see are accepted. The page's own shopping option is left out of the shopping options. Products of age-restricted categories appear as they do in product blocks; their product pages keep the age gate.

## Brand pages

A brand page (`/apple`, `CatalogLandingPages`, see [brands](brands.md)) is the same listing scoped to the brand: every eligible product of that brand in every category, with the same filters, sorting, cards and address contract, under the brand's heading: a white card across the whole page with the brand picture beside its name and description (the protected, non-creatable **Page heading** block, `page_header`, first row of the Catalog landing layout, migration `2026_11_08_000400`; theme `themes/Default/CatalogLanding.tsx`). The brand filter is left out there (every product has that brand). With the legacy source (feature-option landing pages), the page lists the products holding that option and leaves that feature's filter out; its heading shows the option's picture, name and description. The page keeps its own **Catalog landing** layout and SEO texts; migration `2026_11_08_000300` adds the product filters grid beside its main content (4 + 12 columns) when the layout still has its starter shape, and otherwise leaves a rearranged layout as it is. The listing settings of the main content block and the filters block are available on both layouts.

## Layout and blocks

The migration creates the content row: a 4-column grid with the protected **Product filters** block (`product_filters`, type registered in `FirstPartyBlock`, allowed only on the Category layout, not creatable, cannot be deleted but can be switched off) beside a 12-column grid with the protected **Main content** block. The sidebar starts with "Filters" and its clear link beside it. Below 1024px the grids stack, the filters block takes no room, and a full-width "Filters" button (filter icon, number of active filters) right above the products opens the panel full screen with "Show N products"; Escape and the close button return focus to that button. The button appears only while a filters block is on the page (`useFiltersPanel` in `listingUrl.ts` connects the two blocks). Staff can move the blocks and add banners, HTML or product blocks around them as on other layouts.

Main content settings on this layout (validated on the server like every block setting; forged values are refused):

| Setting | Values | Default |
| --- | --- | --- |
| Products per row | 2–5 (tablets at most 3, phones 2) | 3 |
| Products per page | 6–60 | 24 |
| Default sorting | Recommended, lowest price, highest price, newest, rating | Recommended |
| Show the sorting menu | on/off | on |
| Show the number of products | on/off | on |
| Show the brand above the name | on/off | off |
| Show the product category above the name | on/off | off |
| Show the variations as small pictures | on/off | off |

**Sorting.** *Recommended* puts products in stock first, then the best approved rating (as cards show it; unrated last), then the newest; no popularity score is invented. *Rating* is the approved average, best first. Price sorts use the catalog price (see below); *Newest* uses creation time. Every order ends with the product ID, so pages are stable. The theme shows the current order with a sort icon; opened, the menu lists it first and the other orders below.

The three card options are also available on product blocks and products-by-brand blocks (*Show the brand above the name*, *Show the product category above the name*, *Show the variations as small pictures*, all off). Brand and category share one line above the name, each linked: the brand to its brand page while brand pages are on and own that address (`BlockContent::withBrands()`, loaded only when a block shows brands; the card field is `brand_link`), the category to its listing. Cards carry their category (name and link) at no extra cost; variations are loaded in one batch only when a block asks for them (`BlockContent::withVariations()`): the visible members of the card's variation group with their main picture, the card's own product marked and brought into view, in one row that scrolls sideways (swipe, scroll or the arrows that appear on the side with more).

Product filters settings: **Values shown before "Show more"** (3–50, default 8); optional groups **Show the shopping options** (on), **Show the delivery time** (on), **Show the rating filter** (off) and **Show the in-stock filter** (off). A group that is off still applies when an address asks for it, and is then shown so it can be removed. Hiding pagination or the active filter chips is deliberately not offered (products past page one would be unreachable; chips are the only way to see what is applied).

Cards are the storefront product card (`productCards()`): live prices with tax display, product sales and the struck-through reference price, stickers, approved ratings, the favourite heart, the cart button and the card style from General settings. Listing cards also carry the **compare** button under the heart (Settings → General → Catalog → *Allow customers to compare products*, the store's limit and the existing session-backed `/comparison/products/{id}` routes); product blocks and carousels do not.

## Filters, the address and counts

`Storefront\Infrastructure\CategoryListing` owns the listing; its scope is a category with its subcategories, a brand or a feature option. The controller calls `prepare()` once per page (validated state + every count); the filters block reads `filters()` and the main content block `results()` from that same data, so the two never disagree.

The address is the only filter state:

| Parameter | Meaning |
| --- | --- |
| `price_min`, `price_max` | Inclusive bounds on the stored catalog price (decimal, up to 6 places) |
| `brand=apple,samsung` | Brand codes (the brands' public codes, as in `/apple`) |
| `f{feature}=12,13` | Option IDs of a single or multiple choice feature |
| `f{feature}_min`, `f{feature}_max` | Inclusive numeric bounds of a number feature (compared as `numeric`, never text) |
| `f{feature}=1` | A Yes/No feature answered Yes |
| `in_stock=1` | Quantity above zero (offered only while stock is tracked and out-of-stock products are listed) |
| `shop=new,sale,bestseller` | Shopping options, see below |
| `availability=3,5` | Availability status IDs ("delivery time"), see below |
| `rating=5,4` | Whole stars of the approved average as cards show it (4 = 4.0 to 4.9); several combine with OR |
| `category=12,15` | The products' own categories (store-wide pages only) |
| `sort`, `page` | Sort (server allowlist: recommended, price_asc, price_desc, newest, rating) and page (clamped to the last page) |

Only active brands, filterable active features of the supported types and their active options are accepted; anything else (unknown IDs, non-filterable features, malformed numbers, arrays, more than 20 features or 50 values) is silently dropped. Filters only ever narrow the visitor's eligible products, so no value can reveal a product, price or count the listing would not show. Feature filterability is the existing **Filterable** flag of the feature editor; text features are never filters.

**Semantics.** Groups combine with AND; values inside one group with OR. Each group's counts apply every other group but not itself (disjunctive faceting), so the counts beside the brands say what each brand would give with the other filters kept. Selected values stay listed (with 0 if needed) so they can be removed; values without products are disabled. Counts are exact distinct products: one product counts once per option even with several values or subcategories. Price and numeric ranges show the min/max of the products matching the other groups.

**Shopping options** ("Επιλογές αγοράς", right after the price; filters block setting *Show the shopping options*, on by default, on category and brand pages alike). *New*: created within General settings → Catalog → *New products period*, the rule of the newest-products blocks. *On sale*: the product's sale rules give the visitor a lower price than its own, decided by `ProductSales` (customer groups, dates in the store timezone, quotas, minimum quantity), exactly what the card's struck-through price shows; only products with an enabled sale rule are priced (shortcut: in PHP on each request, fine for hundreds of sale products per category; thousands need an indexed effective-sale projection). Withheld while prices are hidden. *Best seller*: one of the 20 best-selling products of the category or brand by paid, non-void units (`ReportOrders::productUnitTotals()`, the bestseller blocks' ranking; that aggregate reads the whole paid order history on each request, as the bestseller blocks do). Options combine with OR, like other values of one group. A merchant-defined "on sale" from reference (list) prices alone is not a sale.

**Delivery time** ("Χρόνος παράδοσης", filters block setting *Show the delivery time*, on by default): every availability status that the scope's products show, in the merchant's status order, with counts. A product counts under the label it shows (`AvailabilityRules::bucket` in SQL): its in-stock status while its quantity is above zero or stock is not tracked, otherwise its out-of-stock status; a product without its own uses the store default. Values combine with OR; unknown status IDs are ignored.

**Price basis.** Price filters and price sorts use the stored catalog price (`products.price`). The prices on the cards stay the authoritative ones: product sales, customer-group sales and tax display are computed per product by `ProductSales` and `TaxCalculationService`, which cannot run inside SQL without a second pricing engine. With price-inclusive taxes and no running sale the two are equal; a product on sale is filtered and sorted by its regular price. An effective-price projection (or search-engine documents with computed prices) is the follow-up; the *On sale* shopping option above decides membership with `ProductSales` instead. While *Hide prices* is on, the price filter and price sorts disappear and price parameters are ignored.

**Browser.** `themes/Default/listingUrl.ts` builds sorted addresses (one address per state; commas readable), drops `page` on every filter or sort change, and loads them with an Inertia visit (state and scroll kept). Inertia cancels an older visit when a newer starts, and the pending address is the state until the answer arrives, so quick successive clicks build on each other and a slow earlier answer never wins. After a filter, sort or page change the page scrolls smoothly back to the top of the listing when the visitor is below it (instantly with *reduced motion*). Back/forward and reload restore the state from the address. Price and number filters are min/max inputs over a two-handle slider (two native range inputs, keyboard operable) with the range's ends beneath; they load only when a handle is released, Enter is pressed or an input is left, and a bound moved back to the range's end is dropped. The slider moves in whole currency units for prices and in the bounds' own precision for numbers. A failed load shows a message; the products and the ticked filters stay those of the address shown, so nothing pretends to match the failed choice.

## Queries

Per page: one facet statement, one page statement, the card batch (`productCards()`, fixed number of queries whatever the page size) and the shared layout queries. No query runs per card or per filter value (`test_cards_and_counts_take_a_fixed_number_of_queries`).

The facet statement reads the category subtree's eligible products once into a materialized CTE with one boolean per active filter group and the approved-review average (one grouped join over the subtree's reviews). Every count is a `UNION ALL` branch over that CTE: total, price range, brands, stock, ratings, and for features one branch for the unselected features plus one per selected feature (so each branch narrows the products before joining their values). JIT is switched off for this statement (`SET LOCAL jit = off`): on PostgreSQL's default `jit_above_cost` the planner compiled it and spent ~75 ms doing so. The page statement applies the same conditions with `WHERE`, sorts with a stable secondary key (`id`) and pages with `LIMIT/OFFSET`.

Index added: `products_category_newest (category_id, created_at DESC, id DESC)` (migration `2026_11_08_000200`) for the default page of a category. Existing indexes already serve the rest (`product_feature_assignments (product_id, feature_id)`, `product_feature_choices (assignment_id, option_id)`, `products (brand_id, status, id)`, `products (category_id, id)`, the approved-review partial index). A price-sort index was not added: it was not measured to help.

Nothing is cached: prices, stock, sales and visibility stay live, as on every storefront page.

## Measured performance

`tests/Benchmark/CategoryListingBenchmarkTest.php` (outside the suites; run it with `docker compose run --rm --no-deps app php artisan test tests/Benchmark/CategoryListingBenchmarkTest.php`) seeds 100,000 products in 5 leaf categories of 20,000 under one parent, 40 brands, 5 filterable features (900,000 feature values) and 60,000 reviews in the test database's rolled-back transaction, then times 10 warm runs. Local Docker on Apple silicon, PostgreSQL 18 defaults (4 MB `work_mem`); HTTP includes the whole page (layout, header, footer). p95 in ms:

| Scenario | Facets before → after | Page before → after | HTTP before → after |
| --- | --- | --- | --- |
| Leaf (20k), no filters | 308 → 192 | 56 → 21 | 433 → 244 |
| Leaf, 2 brands + color | 277 → 148 | 55 → 49 | 367 → 225 |
| Leaf, price + screen size + in stock, price sort | 348 → 154 | 62 → 58 | 433 → 271 |
| Leaf, page 30 sorted by name | 312 → 206 | 95 → 101 | 428 → 331 |
| Parent (100k), no filters | 747 → 502 | 108 → 100 | 880 → 696 |
| Parent, brand + 2 colors + 5G + rating | 626 → 248 | 13 → 10 | 671 → 290 |

With *Recommended* as the default order (it sorts the whole category by rating, so it cannot stop early like *Newest*), the default page query of the 20k category is 85 ms p95 instead of 24 ms (HTTP 318 ms instead of 252 ms); a merchant who prefers speed sets *Newest* as the default. Against the internal targets: filter changes on a 20k-product category stay below 300 ms (p95 225–271 ms); the unfiltered 20k listing is 244 ms against the 200 ms target, and a 100k-product parent category is 696 ms. The remaining cost is inherent to exact counts in SQL: every product of the category is checked against the publication rules and every feature value of those products is aggregated on each request.

**Limits until a search engine (Typesense) is added:** counting cost grows linearly with the category size; sale and effective-price filters/sorts are not possible; name sorting is alphabetical without relevance; no text search. The boundary for that step is `CategoryListing`: `state()` (validated filters, the address contract), `prepare()`/`facets()` (counts) and the page query can be served by a search provider, while the address, the blocks, the theme and `productCards()` (prices, eligibility, stickers) stay as they are.

Each storefront request also runs ~165 small queries regardless of the listing, mostly repeated reads of `general_settings` and `locales` (`GeneralSettings` and `LocaleCatalog` are not shared per request). They cost a few tens of milliseconds and are a separate follow-up.

## Search results

`/search` (A120) uses this listing's contract with the search engine as its source:
- **Shared pieces.** `CategoryListing::state()` validates the same parameters, `present()` builds the same filter
  groups and chips from the engine's counts, and `results()` shows the engine's ranked page of products with the same
  cards and pagination.
- **Search layout.** The Search layout's filters and main content blocks have the listing settings. The default sort
  `recommended` means relevance there.
- **No shop filters.** The shopping options (new, sale, best sellers) are not offered on search.

See [search](search.md).
