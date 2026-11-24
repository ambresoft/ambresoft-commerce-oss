# Taxes — Phase 8

Phase 8 adds tax management and a calculation foundation. It is behaviorally inspired by CS-Cart's taxes (definitions with priority and price-included flag, rate areas, percentage/fixed rates, product assignment, calculation settings); no proprietary code or schema is used. Decisions A69–A70.

## Scope and limits

Implemented: tax definitions, reusable rate areas, rates, product assignment (editor and bulk), the calculation service, tax settings, admin screens, permissions, audit capture and tests.

Not implemented because the capability does not exist yet: cart, checkout, orders, customer accounts/addresses, discounts, shipping, storefront price display and persistent customer tax exemption. The calculation service takes addresses, an exemption flag and already-discounted unit prices as input. No legal rate, VAT percentage or country default is seeded or hardcoded.

## Ownership

| Layer | Types |
| --- | --- |
| Domain | `Address`, `RateArea`, `TaxRate`, `TaxDefinition`, `CalculationSettings`, `TaxContext`, `TaxLine`, `ResolvedTax`, `AppliedTax`, `TaxResult`, `TaxLinesResult`, `RateAreaMatcher`, `TaxCalculator` |
| Application | `TaxConfiguration` (read port), `TaxCalculationService`, `TaxAdministration`, `RateAreaAdministration`, `ProductTaxAssignment` ports, `SaveTaxData`, `SaveTaxRateData`, `SaveRateAreaData` |
| Infrastructure | `EloquentTaxConfiguration`, `EloquentTaxAdministration`, `EloquentRateAreaAdministration`, `EloquentProductTaxAssignment`, `TaxInput`, `RateAreaInput`, `TaxQueries`, `TaxNames`, `TaxServiceProvider` |
| Shared | `Decimal` (exact arithmetic, moved from Pricing), `Countries` (ISO catalog, ICU names) |
| Admin presentation | `TaxController`, `RateAreaController`, `Taxes/Index`, `Taxes/Form`, `RateAreas/Index`, `RateAreas/Form`, the Pricing-tab Taxes control, General settings tax section |

Domain and Application are framework-free (architecture test).

## Relational model (migration `2026_10_12_001800_create_tax_management.php`)

| Table | Integrity |
| --- | --- |
| `rate_areas` | status active/disabled, `is_default` (partial unique index: at most one), `lock_version`, actors |
| `rate_area_translations` | PK (area, locale); non-blank name; unique per language ignoring case |
| `rate_area_countries` | PK (area, country); `char(2)` uppercase CHECK; cascade with the area |
| `rate_area_regions` | PK (area, country, region); region `^[A-Z0-9]{1,10}$`; composite FK to the area's country row |
| `taxes` | status, priority 0–9999, `price_includes_tax`, `address_basis` shipping/billing, `lock_version`, optional registration number |
| `tax_translations` | PK (tax, locale); non-blank name |
| `tax_rates` | unique (tax, area); `rate_type` percent/fixed; CHECK that exactly the matching column is set: `percent_rate numeric(12,8)` 0–1 (fraction) or `fixed_amount numeric(20,6)` ≥ 0; FK to the area is `RESTRICT` |
| `product_taxes` | PK (product, tax); product `CASCADE` (purge), tax `RESTRICT` |
| `general_settings` (columns) | `tax_calculation_method`, `display_catalog_prices_with_tax`, `display_cart_prices_with_tax`, `tax_estimate_without_address`, `tax_default_country`, `tax_default_region` with CHECKs |

All new tables are central-audit captured. The migration is reversible and additive: no product, price or currency row changes and no tax is assigned.

## Rate areas and matching

An area holds whole countries, or only listed region codes of a country (a country with region rows is restricted to them). There is no region catalog: region codes are merchant-entered and matched exactly (uppercase). One optional **default** area has no memberships and matches every address, including a missing one.

For one tax and one address, candidates are the tax's rates whose area is **active**; the specificity is region (3) > country (2) > default (1); equal specificity is decided by the lowest area ID. An area without a rate for this tax is not a candidate, so matching continues to a less specific area that does have one. An explicit **0** is a rate: it wins and never falls back. If nothing applies the tax is reported as unresolved (`address_required` when no address is known, otherwise `no_rate`), contributes nothing to totals and `TaxResult::complete()` is false — a missing rate is never treated as zero.

The address is the context's shipping or billing address according to the tax's basis. When it is missing and **estimation** is enabled, the configured default country/region is used; otherwise only a default-area rate can apply.

## Calculation

Amounts are canonical decimal strings in the primary currency (bcmath, never floats); each tax amount is rounded **once**, half away from zero, to the primary currency's decimals, and later steps use the rounded amounts so parts reconcile with totals.

1. Only active, assigned taxes apply; duplicate IDs apply once. Order is priority ascending, then tax ID.
2. **Included** taxes are extracted from the configured price from the highest priority group down: a group with percentages R and fixed amounts F turns an amount A into (A − F) / (1 + R). Taxes of one priority share a base; a higher priority is tax on tax. What remains is the **net** amount. Included amounts never exceed the price.
3. **Added** taxes are computed from the lowest priority up. A group's base is net plus every lower-priority tax (included or added). Same-priority taxes share that base. A fixed rate adds its amount.
4. Final = configured price + added taxes. An included tax is never changed by an added tax.
5. **Exempt** (explicit context flag): no tax is charged, and the included component is removed — the customer pays the net amount. Callers needing another policy pass a different price.

Result fields: base, net, included/added/total tax, final, per-tax rows (tax, area, rate type/value, amount, status), per-priority sums, `exempt`, `complete()`, `unresolved()`, `display($withTax)` (final or net).

**Methods.** `unit_price` taxes one unit (rounded) and multiplies by quantity; `subtotal` sums lines that share the same tax set and taxes each sum once. Lines must carry unit prices already net of discounts: discount allocation belongs to the future discount/cart phase, and storefront display of the display-with-tax flags belongs to the storefront phase.

## Product assignment

See [products.md](products.md#taxes-phase-8). The Taxes page offers **Assign to all products**: it requires `taxes.assign` and `products.update`, active taxes only, an explicit confirmation and runs as one set-based statement in a transaction. It inserts only missing pairs (including products in Trash), advances the version of exactly the products that gained an assignment (so stale editors are refused), and is idempotent. Audit capture is per assignment row, which is large for very large catalogs.

## Authorization

`taxes.view`, `taxes.create`, `taxes.update`, `taxes.delete`, `taxes.assign`, `rate-areas.view`, `rate-areas.manage` are checked in controllers and again in the Application operations. Editing a product's taxes needs `products.update`; calculation settings need `settings.manage`.

## Concurrency and money

Tax and rate-area writes take the shared Catalog advisory lock (same as currency and price operations) and row-lock the record with an optimistic version; a tax's version advances whenever its names, rates or fields change. Fixed amounts are validated at the primary currency's precision and follow primary-currency conversion and precision guards ([currencies](currencies.md#fixed-tax-amounts-phase-8)).

## Tests

`TaxCalculationTest` (framework-free: percentage, fixed, inclusive/exclusive, same and compounding priorities, mixed rules, zero versus missing, specificity and ties, address basis, estimation, disabled/duplicate taxes, exemption, precision, unit/subtotal methods), `TaxManagementTest` (areas, definitions, validation, versions, deletion, permissions, HTTP screens, audit, migration round trip), `TaxProductTest` (assignment, failure rollback, disabled retention, duplication, bulk, calculation from storage, settings, currency conversion and guards), General-settings, Taxes/RateAreas/Product/Settings Vitest suites and the existing regression suites.

## Sample data

`cms:taxes-demo {actor}` (local/testing only, `TaxDemoSeeder`) creates illustrative Greek sample records so the screens and calculation can be tried: areas "Greece (sample)", "Greek Aegean islands (sample)" (country GR restricted to ISO 3166-2:GR regions K and L, which win over the country area) and an all-countries default; taxes "VAT – standard (sample)", "VAT – reduced (sample)" (both included in price, with explicit 0% for the default area) and "Recycling fee (sample)" (fixed, added, priority 0). Names are Greek/English; mapped by stable `phase8.*` keys in the shared demo-record table, so reruns create nothing and preserve edits; an existing default area is reused. The standard sample VAT is assigned only to products created by the Phase 5 feature demo. The percentages are samples, not statements of Greek law.

## Customers (Phase 9)

`CustomerTaxProfiles::context($customerId)` builds a `TaxContext` from the customer's default shipping and billing addresses (country and region code) and the single `customers.tax_exempt` flag; pass it to `TaxCalculationService`. A customer without addresses yields no addresses, so only a default-area rate or the estimation address can apply. No second address model or exemption flag exists.
