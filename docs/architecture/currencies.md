# Currencies and base-currency pricing — Phase 4.1

## Scope and source

Phase 4.1 adds Settings → Currencies and integrates the primary currency into the Phase 4 product Pricing tab and list. The original roadmap JSON referenced by the Phase 4.1 request was not present in the repository, its git history or the project documents (`development-phases.md` lists only "10 — Pricing"). Its Phase 4.1 pricing requirements could therefore not be verified; only the explicitly requested currency system was implemented. No list/sale/cost price, tiers, discounts, taxes, storefront selector, checkout, payment-currency handling or automatic rate provider exists.

## Ownership

A new `Pricing` context owns currencies (A50). Catalog still owns the product's single base price (A44).

| Layer | Types |
| --- | --- |
| Domain | `Currency`, `Money`, `Decimal` (bcmath), `CurrencyConverter`, `CurrencyFormatter`, `PrimaryCurrencyChange`, `CurrencyRepository`, `CurrencyUnavailable`, `InvalidCurrencyChange` |
| Application | `CurrencyAdministration` port, `SaveCurrencyData`, `PriceDisplay` (shared display entry point), `CurrencyDeletionGuards` |
| Infrastructure | `CurrencyRecord`/`CurrencyMapper`, cached `EloquentCurrencyRepository`, transactional `EloquentCurrencyAdministration`, `CurrencyInput` rules, `CurrencyAudit`, `PricingServiceProvider` |
| Admin presentation | `CurrencySettingsController`, `Settings/Currencies.tsx`, `Settings/CurrencyForm.tsx` |

Domain/Application are framework-free (architecture test).

## Relational model

| Table | Integrity |
| --- | --- |
| currencies | Unique `char(3)` uppercase code; name ≤ 80; symbol ≤ 10; position before/after; decimal separator `.`/`,`; thousands separator `,` `.` `'` no-break space or none, different from the decimal separator; decimals 0–6; `rate numeric(20,10) > 0`; status active/disabled; `lock_version`. CHECK: a primary row has rate 1 and is Active. |
| currency_audits | Currency ID (no FK, survives deletion), code, actor, action, JSONB context, time |

Exactly one primary: a partial unique index allows at most one primary at any moment; a `DEFERRABLE INITIALLY DEFERRED` constraint trigger rejects any commit leaving zero or several. Application operations additionally serialize under the Catalog advisory lock and row locks. Products carry no currency column: every stored `products.price` is an amount in the current primary currency.

The migration seeds EUR (Euro, `€`, before, `,` thousands, `.` decimals, 2 places) as the initial primary because Phase 4 prices were entered at two decimals with no recorded currency and the en/el deployment uses the euro. Existing prices are unchanged. Change it only through the converting primary-currency operation below; directly editing the row would reinterpret every price.

## Conventions

**Rate:** units of the target currency per one unit of the primary currency. `converted = base amount × rate`. With EUR primary and USD 1.10, €100.00 displays as $110.00 and the stored price stays 100 EUR. The primary's effective rate is exactly 1.

**Arithmetic:** canonical decimal strings with bcmath; never PHP/JS floats. Products are multiplied exactly; nothing is rounded until a boundary.

**Rounding:** half away from zero (identical to PostgreSQL `round(numeric, n)`; "half up" for non-negative prices), applied once at:

1. display — to the target currency's decimals;
2. primary-currency conversion of stored prices — to the new primary's decimals;
3. rate rebasing — to 10 decimal places.

Always convert from the stored primary amount; never chain displayed conversions.

**Formatting:** `CurrencyFormatter` rounds, groups thousands, applies the decimal separator/precision, then places the symbol directly before the amount (`€1,234.50`) or after it with a no-break space (`1.234,50 €`). Negative values lead with `-` (`-€1,234.56`); a value rounding to zero never shows `-`. A formatted string is presentation only and is never persisted.

**Precision:** the primary currency's decimals are the product price input precision (replacing `config('products.price_scale')`). Lowering it is rejected while any stored price (including Trash) has more fractional digits, so prices are never silently rounded.

**Converted amounts.** A primary change re-denominates every primary-currency product amount in the same statement: `price`, `list_price` and `shipping_cost` (NULL stays NULL), plus every `product_price_history` row, which is converted rather than recorded as a price change. The precision guard and the overflow pre-check cover the same product columns (history rows only the overflow check). Before this change `list_price` was not converted; earlier conversions are not rewritten.

## Operations

| Operation | Capability | Rules |
| --- | --- | --- |
| View list/editor | currencies.view | Authoritative read from storage |
| Create | currencies.create | Recognized ISO 4217 code (ICU data), unique; always created secondary |
| Edit name/symbol/format/decimals | currencies.update | Code immutable; primary precision guard |
| Change rate | currencies.rates | > 0, ≤ 10 integer and 10 fractional digits; primary fixed at 1 |
| Activate/disable | currencies.status | Primary cannot be disabled |
| Make primary | currencies.primary | See below |
| Delete | currencies.delete | Not primary; registered guards may block |

Each update determines its required capabilities from the fields that actually change. Every mutation runs in one transaction under the shared Catalog advisory lock (`2142513`), locks the row, checks `lock_version` (stale forms are rejected) and re-authorizes the actor; hiding controls is not the boundary. After commit, the display cache is invalidated. Audits record creation, field changes (from/to), rate changes (from/to, relative primary), status changes, primary changes, failed conversions and deletions with actor and time.

Rate, status and formatting changes never write product rows; subsequent conversions use the new configuration.

### Changing the primary currency

The target must be Active and secondary. The edit page previews the affected price count, an example conversion and every recalculated rate. The administrator ticks an acknowledgement and types the exact target code; the server requires the code and the current target version.

In one transaction (holding the Catalog lock, so no product save interleaves):

1. Lock all currency rows; recompute rebased rates `new rate(X) = rate(X) ÷ rate(T)` and reject the change if any falls outside the stored range; reject if any converted price would exceed `numeric(20,6)`.
2. Unset the old primary (receiving `1 ÷ rate(T)`), update all other rates, set the target primary with rate 1; bump every currency version.
3. `UPDATE products SET price = round(price × rate(T), decimals(T))` for every row including Trash and drafts (NULL stays NULL), incrementing every product `lock_version`.
4. Audit `primary_changed` with old/new code, rate, decimals, rounding rule, count and every rate change.

Failure at any step rolls everything back; a `primary_change_failed` audit is then written outside the rolled-back transaction. Because all product versions advance, an editor opened under the old currency cannot save an old-currency amount. Switching back later is another conversion with the rebased rate, so rounding differences can remain; the UI states this. A single statement suits the current catalog scale; a very large catalog would hold the Catalog lock for the duration of one UPDATE, which should be measured before considering a batched, resumable design.

### Deletion

Disabled and Active secondaries can be deleted after confirmation; product prices are unaffected; audit history keeps the code. Future order/payment/refund modules must register a `CurrencyDeletionGuards` closure (receiving the code under the lock) returning a translatable reason; such currencies can still be disabled. No such module exists yet.

## Product integration

- Editor Pricing tab: label `Price (EUR)`, hint naming the primary currency and allowed decimals, the stored price shown at the currency's precision without rounding, and a read-only list of the saved price in each active secondary currency. Those values are not form fields and are never submitted.
- List: the price column header names the primary code; cells use the server-formatted primary price. No component formats money itself.
- Duplication, drafts, completeness, activation, Trash/restore and stale-save rules are unchanged; they operate on the primary amount.

## Future storefront contract (not implemented)

- The primary currency is the default storefront currency.
- Customers will choose among `CurrencyConverter::active()` (primary first, then Active secondaries).
- Use `PriceDisplay::format($storedPrimaryAmount, $selectedCode)`; switching currency changes only displayed values, never stored prices. Disabled/unknown targets throw `CurrencyUnavailable` and must fall back explicitly to the primary.
- The selected display currency is not the payment currency. Cart/order/shipping totals must calculate in the primary currency and convert at a documented boundary; payment integrations must verify gateway support before charging in another currency and must snapshot currency code/rate on orders.

## Deployment

After pulling: `php artisan migrate`, then `php artisan cms:permissions-sync` (registers seven definitions, grants nothing). Assign currency capabilities through Roles; super administrators inherit them. Review the seeded EUR formatting before entering prices. The display cache (`pricing.currencies.v1`, 10 minutes) is invalidated after each committed change; writes never read it.

## Verification

`tests/Unit/CurrencyConversionTest.php` (no Laravel/DB): rate direction, primary identity, exact arithmetic, half-away rounding, negative zero, formatting variants, disabled/unknown targets, invariants, rebasing and range failures. `tests/Feature/CurrencyTest.php` (PostgreSQL): constraints and the deferred exactly-one-primary trigger, HTTP validation, per-operation capabilities, primary protections, cache invalidation, audits, stale writes, product list/editor projections, precision guard, full primary conversion (including Trash, drafts, ties, version bumps and SQL/domain rounding agreement), atomic rollback on validation and simulated database failure, and guarded deletion. `Currencies.test.tsx` and `Products.test.tsx` cover the confirmation flow, immutable code, rate labelling and that converted values are never submitted.

## Fixed tax amounts (Phase 8)

Fixed tax rates (`tax_rates.fixed_amount`) are amounts in the primary currency like `products.price`. A primary-currency change re-denominates them in the same transaction with the same half-away-from-zero rounding, advances the version of each affected tax and records the count in the `primary_changed` audit context; the overflow pre-check also covers them. Lowering the primary currency's decimals is refused while any fixed tax amount has more fractional digits. Percentage rates are unitless and never change.
