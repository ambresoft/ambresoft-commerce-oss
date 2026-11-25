# Coupons

Marketing → Coupons provides searchable, sortable, paginated administration with bulk deletion. Create/edit uses the dashboard’s own fields, selectors, tooltips and tabs. General contains name, unique code, percentage/fixed discount, minimum subtotal, registered-customer restriction, free shipping, product/category scope, optional dates, total/customer limits and enabled status. Usage history shows order, customer, monetary benefit, date and whether the allowance was released. Separate persisted `coupons.view`, `coupons.manage` and `coupons.delete` capabilities protect reads and writes. Normal permission sync preserves existing role grants.

The OpenCart documentation is a behavioral reference, not a source of schema or implementation: <https://docs.opencart.com/admin-interface/marketing/coupons>. Intentional boundaries: percentage input is limited to 100%; deleted codes remain reserved; a guest’s allowance is tracked by normalized email rather than bypassed. Coupon names are internal references, not localized storefront content. UI strings are keyed English/Greek translations with normal language fallback.

## Pricing and eligibility

- Codes are case-sensitive ASCII letters/digits/hyphens/underscores, 3–20 characters. Blank code removes an order’s coupon.
- One coupon per order, after existing product-sale prices. No coupon stacking is introduced. The storefront cart reads *Allow only one coupon per order*: on, a second coupon is refused until the first is removed; off, it replaces the first. Stacking remains an open decision.
- The minimum is merchandise subtotal after sales but before taxes and shipping. Included taxes are removed from the undiscounted basis when testing this threshold.
- Discounts apply to eligible entered prices (which may include tax), then taxes are recalculated. Fixed discounts are capped at eligible merchandise value. Allocation uses exact largest remainders at currency precision; any indivisible per-unit remainder is split into at most two unit-price tax lines and combined back into the original order line. No monetary calculation uses floating point.
- Product selections and category selections are a union; category selection includes descendants. The explicit `all_products` flag survives target deletion. Restricted coupons without matching products fail, including free-shipping coupons.
- Free shipping removes the current shipping charge and its taxes; the original charge and tax IDs remain in shipping details so clearing the code restores shipping correctly. History records merchandise and shipping-charge discounts; taxes are represented in the order’s existing tax breakdown.
- Empty dates mean unbounded. Start and end dates include the whole day in store timezone. Empty or zero use limits mean unlimited. A zero monetary discount requires free shipping.
- Registered-customer coupons require a selected existing customer in the staff order editor. Any future public entry point must derive this identity from authenticated customer context, not submitted customer IDs.

## Persistence and lifecycle

`coupons`, `coupon_products` and `coupon_categories` hold definitions and restrictions. Orders snapshot coupon ID/code, merchandise discount and shipping discount. Order items snapshot their allocated discounts. A primary-currency change converts current fixed discounts and minimums, increments definition versions, and leaves order/redemption currency snapshots unchanged. Precision reduction rejects amounts that would lose digits; conversion rejects a fixed discount rounded to zero unless free shipping supplies a benefit.

`coupon_redemptions` records actual saved-order usage, never previews. Per-customer accounting uses a SHA-256 hash of normalized email (profile email for registered customers), so guest use is also limited. Changing email creates a different identity; this is an operational email-based limit, not proof of a unique person. Contact names are read from the order, not duplicated in redemption or audit data.

Order mutation, stock reservation and redemption happen in one transaction. Coupon-related order mutations take the existing catalog/currency advisory lock before product and coupon locks. A PostgreSQL trigger also locks the coupon and checks total/per-customer capacity for new/reactivated uses, protecting direct/concurrent writers. A partial unique index allows only one active redemption per order. Foreign keys retain financial references and indexes bound usage/history queries.

Cancel/restore-stock statuses release allowance. Reactivation reacquires capacity while keeping the order’s original discount, even after expiry or archival. Lowering a limit does not invalidate an existing active use. Changing coupon, customer identity or priced contents revalidates eligibility. No-op saves keep existing prices. Repricing a discounted line whose product was permanently removed requires removing that line; its stored tax cannot be reconstructed safely. Definition edits and deletes use optimistic versions. Bulk deletion is atomic and archives definitions; existing orders can link to retained history.

The audit allow-list includes definition fields, targets, order discount snapshots and redemption amounts/releases. It excludes customer identity hashes. Rollback after actual redemptions requires restoring a database backup because removing discount snapshots cannot reconstruct original taxes. This restriction is enforced by the migration.

## Delivery boundary and verification

Coupons are usable through existing order entry, quote and save operations. The public storefront cart/widget is a later phase; no public checkout route or client-side authority is introduced here. Tests cover CRUD/capabilities/versions, validation, exact allocation, tax-inclusive minimums, category descendants, expiry/timezone, guest/profile limits, cancelled/reactivated orders, quote-versus-save, shipping, currency conversion and database concurrency.

## Automatic promotion integration

[Promotions](promotions.md) can optionally wrap an existing coupon with conditions/scheduling and put it at an explicit priority. The coupon still supplies its benefit and limits, once. Standalone coupons remain unchanged and require no promotion. Orders now separate coupon-only monetary amounts from combined discounts so automatic promotions never inflate coupon usage history; existing orders are safely backfilled. The new wrapper's disabled/expired/ineligible state prevents its linked coupon from applying, while all original coupon restrictions still run. No coupon data or editor is replaced.
