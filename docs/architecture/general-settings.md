# General settings

Settings → General holds installation-wide defaults that individual records may override. It is merchant configuration, distinct from deployment configuration (`.env`). Decision A68.

## Storage

Migration `2026_10_11_001700_create_general_settings.php` creates `general_settings`: one row, `id = 1` enforced by CHECK together with a valid action set and a positive `lock_version`. Options are typed columns; later options arrive by additive migrations, not a key/value table or JSONB blob. `updated_by` references the staff user. The table is audit-captured (entity `general_settings`, field `out_of_stock_action`) through the central ledger.

## Out-of-stock action (first option)

`out_of_stock_action` is `N` (do not allow purchase at zero stock, the initial value) or `B` (allow backorders). A product's `products.out_of_stock_actions` is nullable:

| Product value | Meaning |
| --- | --- |
| NULL | Follows the general setting. The effective value is resolved when read (`ProductRules::effectiveOutOfStockAction`); no product rows are rewritten when the general setting changes. |
| `N` / `B` | Deliberate override for that product, kept even when it equals the current general value. |

Migration 001700 converts the Phase 7 default `N` to NULL. Because the general setting starts at `N`, effective behavior is unchanged; an explicit `B` stays an override. `down()` restores explicit values from the general setting in force and the previous NOT NULL/default `N`.

Stock never changes a product's status or visibility. No storefront or cart exists, so the effective action is persisted and unit-tested as a domain rule, not enforced by a purchase flow.

## Authorization and concurrency

`settings.manage` gates the page and the save on the server; the route, controller and `GeneralSettings::save` all authorize. Run `cms:permissions-sync` after migrating, then grant it through Roles (Super Admin keeps the registered-capability bypass). Saves run in one transaction under a row lock and reject a stale `version`, so two open tabs cannot silently overwrite each other.

## Admin

Settings → General (sidebar, Settings group) edits the action, shows how many live products carry their own value and links to the Products list filtered with `stock_action=custom`. The product list adds a "When out of stock" filter (`general` | `custom`). The editor's Pricing & inventory tab shows "Use the general setting (current value)" as the first choice, a note describing whether the product follows or overrides, a one-click return to following and, for staff with `settings.manage`, a link to the setting.

## Tests

`GeneralSettingsTest` (defaults, persistence, version bump, audit, invalid values, stale write, database constraints, capability, override count, navigation), the override cases in `ProductTest`, `ProductRulesTest` and the Vitest suites for the General page, product editor and list filter.

## Tax calculation settings (Phase 8)

Migration 001800 adds typed columns to the same single row (no key/value store): `tax_calculation_method` (`unit_price` default | `subtotal`), `display_catalog_prices_with_tax`, `display_cart_prices_with_tax`, `tax_estimate_without_address` and a default `tax_default_country`/`tax_default_region`. CHECK constraints require a country when estimation is on and a country for any region. Saves are validated on the server (country from the ISO allowlist, region as uppercase letters/digits), omitted fields keep their values and the whole row shares the settings version and `settings.manage`. The display flags and the subtotal method are persisted foundations: no storefront, cart or checkout reads them yet. [Taxes](taxes.md).

## Basic settings and grouped preferences (A81)

Migration `2026_10_14_000900_expand_general_settings.php` adds typed columns to the existing singleton. One version, permission and transaction cover all six tabs: **Basic settings** (first/default), **Appearance & calendar**, **Catalog**, **Inventory**, **Promotions** and **Tax calculation**. Draft values survive tab changes; tab badges identify validation errors, including nested message translations. Omitted fields retain their previous values.

- **Storefront access:** `storefront_open` initially true, preserving the neutral foundation entry page. Closing replaces `/` with an escaped plain-text closure page and HTTP 503. Admin, authentication and `/up` remain available. There are no other storefront routes yet; future storefront routes must consume the same access rule. Closure text is merchant content in `general_settings_translations`, uniquely keyed by settings and locale. Editor saves require every active language and reject inactive/unknown languages. Missing text falls back to the saved system default, then a keyed UI default. Inactive saved translations remain available after reactivation. Closing is independent of Laravel deployment maintenance mode.
- **Appearance:** allowlisted numeric date/time formats, IANA timezone identifiers (UTC initially), independent calendar date format and Monday/Sunday week start. Data timestamps in product, banner, customer, blacklist and audit screens use the store timezone. Page/banner availability editors convert between that timezone and UTC instants; nonexistent daylight-saving wall times are rejected. Dates without time remain ISO calendar dates, without timezone shifts. The shared date picker respects the calendar format and week start. Charts retain locale-oriented tick granularity.
- **Admin interaction:** persisted warning preference guards registered admin forms on Inertia navigation and browser unload; saves are allowed and in-flight forms do not prompt. Menu descriptions are optional translated hover titles on existing navigation links. Success messages auto-hide after 0–300 seconds (5 initially); 0 requires manual dismissal. Validation errors do not auto-hide.
- **Phone input:** `formatted` preserves existing customer/address validation (digits with a leading + and formatting), or `digits` accepts 3–40 digits only. The same server rule applies to browser and direct customer operations. Existing contact data is not rewritten; later edits validate the saved phone under the current rule. Company contact settings and blacklist normalization retain their own existing semantics.
- **Weight:** a short unit label (kg initially) plus an exact positive decimal grams-per-unit factor (1000 initially, at most 1000000, six decimals). Product editor unit labels consume this setting. A factor change converts all non-null product weights, including trash, under the existing Catalog advisory lock in the settings transaction, rounded to six decimals; overflow and positive-to-zero rounding reject the whole save. Converted product versions advance, rejecting old editors. Label-only changes also advance weighted product versions. Dimensions and descriptive feature units keep their own meanings.
- **Inventory:** tracking defaults on and preserves quantities when switched off. `ProductRules` can bypass only its stock check when tracking is off, preserving min/max/step restrictions; no cart exists yet. A nonnegative low-stock threshold (0 initially) produces product-list labels at or below the threshold when tracking is on. It sends no notification email and changes no status or visibility.
- **Catalog / promotions:** comparison defaults on and one-coupon-per-order defaults on. These explicitly requested options are persisted for the future comparison and checkout consumers; neither subsystem is added by this work.

The new settings and closure translations join transactional audit capture. No capability synchronization or new grants are required. Apply the migration and build assets. Tests cover default preservation, authorization through existing tests, stale writes, PostgreSQL constraints, closure/admin availability, locale completeness, phone input, weight conversion and rollback, low-stock derivation, tabs, date/calendar/timezone behavior, warnings and notification timing.

## Hide prices on the storefront

Migration `2026_10_21_000100_add_price_visibility_setting.php` adds `general_settings.hide_product_prices` (boolean, default false) and a locale-linked `general_settings_translations.price_hidden_message` (≤ 500 characters, empty by default). Settings → General → **Catalog** shows the switch and, once it is on, one message field per active language (for example "Please contact the shop for a price"). One version, permission (`settings.manage`) and transaction cover it like every other setting; both fields are audit-captured.

It is deliberately installation-wide: there is no per-product flag, so a merchant who does not want to display prices changes one setting instead of editing every product. **The administration is unaffected**: staff keep seeing, entering and editing prices on products, orders and everywhere else; the switch only controls what customers see.

**Storefront rule:** never render a product price (product page, listings, search, comparison, cart lines, related products, structured data or feeds) without asking `GeneralSettings::pricesHidden()`. When it is true, show `GeneralSettings::priceHiddenMessage($locale)` instead (escape it; nothing when empty) and completely omit Add to cart controls and purchase quantity steppers, including product cards, the product page, its sticky bar and bundles. A hidden price is also not to leak through JSON props, the page source or sorting by price.


Directly beneath **Hide prices**, **Disable cart and checkout** inversely edits the existing `cart_enabled` setting (enabled by default). These are independent switches: hiding prices removes product purchase controls; disabling the cart also removes the header cart icon and closes `/cart`, its JSON routes and `/checkout` (including subpaths) with 404. Existing carts and their codes remain stored and work again when selling is enabled. No new setting, migration or permission is introduced.

## Storefront maximum width

Appearance includes `site_max_width`, a whole-pixel maximum content width (320–3840, default 1320). The typed column and PostgreSQL check arrive in `2026_11_01_000200_add_site_max_width_setting`; the existing settings permission, optimistic version and audit transaction apply. Omitted values preserve the saved width. Public layout props expose `max_width`; the default theme uses it for all region containers and the product sticky bar. Narrow viewports remain fluid, while full-width color bands retain their existing behavior. This does not change administration width.

## Back to top

Appearance includes `show_back_to_top` (boolean, enabled by default), added by `2026_11_01_000300_add_back_to_top_setting`. It follows the existing settings permission, versioned save and audit transaction. The default storefront theme shows a fixed bottom-right arrow after 300 px of scrolling; clicking returns to the top with smooth scrolling, or immediately when reduced motion is preferred. Disabling the setting removes the control.

## Best sellers

General → Catalog → Best sellers controls the shared storefront merchandising ranking. `bestsellers_days` is a rolling period of 0–3650 days: 0 (default) keeps all history; 30, 90 and 120 are examples. The inclusive cutoff is measured from the current UTC instant, using the payment that first fully covered the order, not order placement or subsequent duplicate payments. Existing fully-paid, positive-total, non-void sales semantics and units-descending/ID-descending tie ordering remain authoritative.

`bestsellers_min_units` and `bestsellers_min_orders` require at least 1–1000000 units and distinct paid orders per product within that period; both default to 1. `bestseller_excluded_categories` stores up to 100 selected category IDs with a primary key and cascading foreign key; exclusions expand to descendants live and use current product category ownership. An empty selection excludes nothing. Product eligibility rules still apply. Brand totals sum qualifying products after these rules.

`Storefront\Infrastructure\Bestsellers` supplies all Products / Products by brand / Brands bestseller blocks, `/bestsellers` and category/brand shopping filters. Reporting totals and sold-product statistics retain their existing history. Saves reuse `settings.manage`, the general-settings version, transaction and audit ledger. Category search uses that same permission and localized category queries. There is no fallback to older sales when the chosen period is empty.

Migration `2026_11_21_000100_add_bestseller_settings` adds checked integer columns and the category relation with audit triggers. Rollback removes these settings and restores the previous general-settings audit fields without changing orders or products.
