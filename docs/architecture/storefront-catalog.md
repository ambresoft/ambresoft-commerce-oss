# Public storefront catalog (A127)

`App\Storefront\Application\Contract\StorefrontCatalog` is the supported way for an add-on to read the catalog. It
returns what an anonymous visitor may discover, calculated by the storefront's own services. Resolve it from the
container. It is bound in `StorefrontServiceProvider` and implemented by `GuestStorefrontCatalog`.

```php
use App\Storefront\Application\Command\CatalogQuery;
use App\Storefront\Application\Contract\StorefrontCatalog;

final class Reader
{
    public function __construct(private StorefrontCatalog $catalog) {}

    public function firstPage(): array
    {
        return $this->catalog->searchProducts(new CatalogQuery(perPage: 20))->products;
    }
}
```

| Method | Returns |
| --- | --- |
| `status()` | `CatalogStatus`: open, currency, decimals, whether prices are visible and search is available. Never throws |
| `searchProducts(CatalogQuery)` | `CatalogProductPage`. Text goes to the active search add-on; without text, products browse in storefront order |
| `product(id, ?locale)` | `CatalogProduct`, or null if the product is not public |
| `categories(?locale)` | List of `CatalogCategory`. `parentId` carries the tree |
| `pages(?locale)` | List of `CatalogPage`: published content pages with their FAQs |

Reads throw `CatalogUnavailable` with `reason` `closed` while the store is closed, and `search` when text search has no
working engine. They never answer empty in those cases, so a caller cannot mistake an outage for a missing product.

## Brands, variations, companions and typed features

`CatalogQuery` also takes `brands` (codes or names in any language, case ignored, at most 100), `skus` (exact, at most 100) and
`onSaleOnly`. A brand that is disabled or unknown matches nothing, never everything. `onSaleOnly` uses the storefront's own sale
rule (`CategoryListing::onSale`, the "sale" listing filter) and is refused while prices are hidden, like price sorts, because it
would reveal what hidden prices hide. In a text search these narrow the engine's page, so a page can come back short while
`hasMore` is true, exactly as for a stale hit.

`CatalogProduct` also carries `variation` (an opaque ID its variants share), `variationFeatures` (the IDs of the features that
tell them apart) and `companions` ("frequently bought together", in the merchant's order, only those a visitor could discover).
Each attribute also has the feature's `code`, `type` and `unit`, and for a number feature its plain value in `number`. All of it
is read in bulk, so the number of queries still does not grow with the page.

`CatalogProduct` also carries `gtin` (the product's EAN, digits as stored), `shortDescription` (plain; `description` falls back to it) and
`weightGrams` (the stored weight converted with the store's grams-per-unit setting, as an exact decimal string, or null).

## Data boundary

Objects under `Storefront/Application/Command` are readonly, framework-free and contain plain values: never Eloquent
records. Texts are plain with markup removed, addresses are absolute, and amounts are decimal strings. Stock counts,
customer data and group rules are not in the contract. The contract is independent of any add-on, and it has no method
for one add-on's needs.

## What is public

The rule is one scope, used by search and by `product()` alike, so knowing an ID, SKU or URL gains nothing:

| Product | Catalog |
| --- | --- |
| Active, complete, accessible to guests, listed | Public |
| Out of stock, listed | Public, with its availability |
| Out of stock while `hide_out_of_stock_listings` is on | Not public (`PublicCatalog::listed`) |
| Future `available_from` | Public, status `upcoming`, not purchasable |
| Disabled, Hidden, Trash, incomplete | Not public |
| Restricted by customer group, on the product or category ancestry | Not public |
| Under a category with `exclude_from_discovery` | Not public, although its product page works |
| Under an age-checked category | Not public: nobody can confirm an age for this reader |

The contract is deliberately stricter than the product page. A page visitors can open is not therefore discoverable.
`categories()` follows the same rule.

## Prices

`BlockContent::productCards` is the only source, so catalog prices are the storefront's prices, including its order of
operations: regular price, the best sale rule for a guest at the smallest orderable quantity, then tax. `CatalogPricing`
carries `regular`, `sale`, `list`, `reference` (the amount the storefront strikes through) and `quantity`. All amounts are
tax-inclusive, as cards always are. Pricing is null while `hide_product_prices` is on. Nothing is calculated here.

**Guest only.** No method accepts a customer, and a signed-in visitor changes nothing: the implementation always asks for
the anonymous visitor. Customer-specific prices need a separate, explicitly authorized design.

**Currency.** Prices are in the primary currency, which storefront, cart and checkout use. Secondary currencies are not
shopper-facing, so they are not offered and nothing is converted.

**Not offered.** Price filtering: the storefront's filters use the stored catalog price rather than the displayed one, so
a filter would disagree with the prices returned. Price sorts have the same limit and follow the storefront.

## Pagination

`hasMore` says whether to read the next page. Decide by it, never by the size of a page.

| | Browsing | Text search |
| --- | --- | --- |
| `total` | Exact count | The engine's count, an upper bound |
| `totalExact` | true | false |
| Page contents | Always full until the last page | Can be short or empty before the last page |

A text search re-reads every hit under the catalog's visibility rule, so a stale hit is dropped and never shown. Pages of the
engine's results stay stable, so a page can come back short, or empty, while `hasMore` is true. `hasMore` can only be wrong in
the safe direction: a last page may turn out empty, but results are not cut off early.

## Performance

Reads use a constant number of queries, whatever the page size: images, features and availability are read in bulk. A
measured page of 100 uses 113 queries (it used 1,643 per-product reads before). Nothing is cached to achieve this.

## Caching

The catalog caches nothing. Sale windows and release dates change by the clock, so every read is evaluated at request
time. A caller may cache descriptive fields briefly, but must not cache `pricing` or `availability`.

## Pages

The CMS does not classify pages by topic, so `pages()` returns all active, available, unrestricted pages, and a caller
decides what they mean. Hidden pages are not returned, although their direct links work, for the same reason as products.
