# Payments — Phase 10

The `Payment` context owns payment methods, their configuration and the money recorded against orders. Payment state
is independent of the order (fulfilment) status. Cash on delivery is core; Stripe Checkout is a bundled first-party add-on;
other providers are add-ons using the same contract ([add-on developer guide](../addons.md)). Decision A86.

## Adaptation to the current order lifecycle

Phase 10 wired payments into the staff order lifecycle (A82); the storefront [checkout](checkout.md) (A118) now uses the
same operations:

- Staff choose a payment method for a new or edited order from the methods currently offered (enabled, completely
  configured, owner add-on enabled, supporting the order currency). The order stores the method and its name at that time.
- **Offline methods** (cash on delivery, the example bank transfer): placing the order records no payment. Staff
  record the money when it is actually collected (`orders.payments`).
- **Redirect methods** (Stripe Checkout): staff share a signed **customer payment link** (`/pay/{order}`, 30 days). The page
  shows only the store, order number, amount due and method, and offers "Pay". The server opens a provider payment for
  the outstanding server-calculated amount and sends the customer to the provider; the return URL triggers a
  server-side capture and verification. A future storefront checkout will call the same `OrderPayments` operations.
- Orders are always created before payment, so a provider callback can never create (or duplicate) an order.

## Ownership

| Layer | Types |
| --- | --- |
| Domain | `PaymentRules` (attempt transitions, order payment status, captured/outstanding amounts; exact decimals) |
| Application | Contracts `PaymentMethod`, `RedirectPaymentMethod`, `PaymentProviderUnavailable`; commands `SettingField`, `PaymentAmount`, `PaymentStart`, `PaymentRedirect`, `PaymentCheck`, `PaymentResult`; `PaymentMethodRegistry` |
| Infrastructure | `CashOnDelivery`, `PaymentSettings` (rows, encrypted secrets, issues, availability), `PaymentMethods` (admin writes), `PaymentQueries`, `OrderPayments` (all payment writes), `PaymentLinks`, `PaymentMethodChoices` (implements Order's `OrderPaymentMethods` port), `PaymentMethodExtensionPoint`, `PaymentServiceProvider` |
| Presentation | `PaymentPageController` (public, Blade, no scripts); Admin `PaymentMethodController`, `OrderPaymentController`. Navigation: top-level **Payment methods** |

Order depends only on its own port (`App\Order\Application\Contract\OrderPaymentMethods`); Payment calls Order's
published `OrderAdministration::changeStatus` for the optional status after payment.

## Registration

`PaymentMethodRegistry` holds every method with its owner: `core` (cash on delivery and bank deposit, registered by
`PaymentServiceProvider`) or the add-on name. Add-ons declare methods in their manifest
(`extra.cms-module.contributes.payment-methods`: identifier → class); the `payment-methods` extension point validates
the declaration at install/enable (identifier format, class inside the add-on's namespace and package, identifier not
used by core, another add-on or a stored row of another owner) and registers a lazy factory when the add-on loads.
Lists and choices skip methods whose owner add-on is no longer enabled, also in long-running processes.

## Persistence (migration 2026_10_16_000100)

| Table | Purpose and integrity |
| --- | --- |
| payment_methods | One row per registered code: `owner` (core or add-on; another owner can never take the row over), enabled, position, optional `paid_status_id` (FK, null on delete), plain `settings` (JSON object), `secrets` (one encrypted JSON object), verification fingerprint/time, optimistic version. Rows are created on first use with default names in every language; cash on delivery starts enabled, bank deposit and add-on methods disabled |
| payment_method_translations | Customer-facing name (required in the default language) and description per language |
| orders.payment_method_id / payment_method_name | Chosen method (restrictive FK) and its name snapshot; both or neither (CHECK) |
| order_payments | `manual` records (always `captured`) and `online` attempts: unique public `reference`, method, state, exact amount + currency, provider and capture references (unique per method), redirect URL, short detail code, note, staff author, claim/completion times. Partial unique index: one open (`created`/`processing`) online attempt per order |

Disabling a method or an add-on never deletes rows: historical orders keep the method name and their payments.

## States

Attempts: `created` → `processing` (claimed for capture) → `captured` / `pending` / `failed` / `cancelled`;
`pending` → `captured` / `failed`; a released claim returns to `created`. Final: captured, failed, cancelled.
Order payment status (derived): `paid` when captured money covers the total, `partially_paid`, `pending` (a capture
awaits the provider), otherwise `unpaid`. Editing an order total after payment changes the derived status, never the
recorded money.

## Safeguards

- Amounts and currency are calculated on the server (outstanding = total − captured, bcmath). Browser-submitted amounts,
  return URLs or "success" parameters are never trusted.
- Every capture is verified server-side by the method (Stripe: session status, amount, currency and our reference in
  `client_reference_id`) and again by `OrderPayments` (amount/currency equal to the attempt, capture reference present and unique).
  Anything else is recorded as `failed` (`verification_failed`, `duplicate_capture`) and logged without payload.
- Duplicate protection: one open attempt per order; an atomic claim (`UPDATE … WHERE state = 'created'`) before every
  capture, taken over only when stale (2 minutes); provider idempotency keys (`cms-create-{reference}`,
  `cms-capture-{reference}`); settlement under row locks and only from a non-final state; unique capture references.
- The status after payment runs once — when the order first becomes fully paid — through `OrderAdministration`, so stock
  and customer emails behave exactly as a staff status change. If that change is refused (e.g. not enough stock), the
  payment stays recorded and staff move the order manually.
- Provider outages throw `PaymentProviderUnavailable`: nothing is recorded, the claim is released, the customer can
  retry. "Check status" (staff) re-reads unfinished attempts.
- Public URLs are signed with the application key (pay: 30 days; return/cancel: 1 day; the provider's `token` and
  `PayerID` parameters are ignored when validating); changing the order or attempt ID invalidates them. The return
  must carry the provider reference of that attempt. Pages are `no-store`, `no-referrer`, `noindex`, with
  `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self' https:` and a CSRF-protected
  POST before any provider call. Routes are throttled.
- Secrets are encrypted with `APP_KEY`, write-only in the editor (an empty field keeps the stored value; "remove" clears
  it), never in Inertia props, HTML, audit records or logs. Credential changes clear the verification; redirect methods
  are offered only with verified credentials (HMAC fingerprint of the credential fields).

## Cash on delivery and bank deposit (core)

Core offline methods without settings or credentials, supporting every currency: `cod` (enabled out of the box) and
`bank_deposit` (starts disabled: the store's bank names and IBANs are written in its customer-facing description in
each language, multi-line, up to 500 characters, then it is enabled). Both have configurable names/descriptions per
language, display order, enabled state and optional status after payment. Choosing either never marks an order paid;
staff record the payment once the money (cash or deposit) has arrived. Neither is an add-on.

## Stripe Checkout (bundled add-on `ambresoft/stripe-checkout`)

Stripe's hosted Checkout Sessions for one-time payments, confirmed by signed webhooks through the provider-neutral webhook path described in [Stripe Checkout](stripe-checkout.md) (A93): `HandlesPaymentWebhooks` (signature verification and event translation by the method), `payment_webhook_events` (one row per verified event ID), `PaymentWebhooks` and `OrderPayments::applyProviderResult` (once-only settlement, late-money recording). `ExpiresProviderPayments` lets a method end an old provider session when the CMS cancels or replaces an attempt. Return URLs carry the provider reference (for example Stripe's `session_id`); a provider that cannot append it to the cancel URL relies on the signed URL alone.

## Permissions

`payment-methods.manage` (configure methods), `orders.payments` (record payments, share payment links, check status).
Reference roles: store admin gets both, order manager gets `orders.payments` (new installations; existing reference
roles via `cms:permissions-sync --grant-commerce-defaults`).

## Limitations

Refunds, voiding of recorded manual payments,
partial captures, payment emails/variables, or payment
status in the order list. Additional providers, saved cards and recurring payments are out of scope.

## Method images

Each payment method can carry one optional image (migration 2026_10_17_000200), uploaded in its editor; see [shipping](shipping.md#persistence-migration-2026_10_17_000100) for the shared storage rules.

## Product restrictions (migration 2026_11_06_000100)

A payment method can refuse products it must not pay for, set in the **Restrictions** section of its editor:

- **Exclude physical products** / **Exclude digital products** (`payment_methods.exclude_physical`, `exclude_digital`), using
  the product's `type`. Excluding both is refused (CHECK `payment_method_product_types` and a validation message): the
  method could never be used. Typical use: cash on delivery excludes digital products.
- **Excluded categories** (`payment_method_excluded_categories`, method + category, both cascading on delete). Excluding a
  category excludes **all of its subcategories**, at any depth, exactly like the category age check: a product is
  excluded when its own category or any ancestor of it is listed. The editor offers every category by its full path
  ("Technology › Gaming"); deleting a category removes it from every method's list.

The rule lives in `Payment\Domain\Service\PaymentRestrictions::blocked()` (framework-free, `PaymentRestrictionsTest`);
`Payment\Infrastructure\PaymentMethodRestrictions::forProducts($productIds)` applies it to every method and answers, per
method code, `null` (the method can pay) or the first blocked product and the reason (`physical`, `digital`, `category`).
Both are audited (`AuditTables::paymentRestrictions()`).

**Checkout contract** (implemented, see [checkout](checkout.md)). The checkout calls `PaymentMethodRestrictions::forProducts()` with
the products in the cart, offers only the methods that answer `null`, and refuses again on the server when the order is
placed (the cart can change between showing the methods and paying). The reason can tell the customer why a method is
missing. Staff choosing a method for an order in the admin (`PaymentMethodChoices`) are deliberately **not** restricted.

## Payment method fees (migration 2026_11_08_000600)

A payment method can charge an optional **fee** (Payment methods → edit → *Fee*), e.g. +2 for cash on delivery. It is
a fixed amount in the primary currency at its precision (`payment_methods.fee`, nullable, `CHECK fee >= 0`); empty or
zero means none, and a save that leaves the field out keeps the saved fee. Coupons and promotions do not reduce it.

**VAT (Greece).** The fee is not a separate service with its own rate: it is an incidental expense the seller passes on
to the buyer, which belongs to the taxable value of the goods — *"παρεπόμενα έξοδα με τα οποία ο προμηθευτής επιβαρύνει
τον αγοραστή … όπως τα έξοδα προμήθειας, … μεταφοράς … ακόμη και αν αποτελούν αντικείμενο ιδιαίτερης συμφωνίας"* (Greek
VAT Code, Law 5144/2024 art. 24 par. 4(a), formerly Law 2859/2000 art. 19 par. 4; EU VAT Directive art. 78). The AADE
e-commerce guide (Dec 2023, Q11–12) applies this to charges on the same receipt as the goods and, when the goods carry
different rates, requires the charge to be **split by the value of the goods** (Π.4336/3162/Εγκ.10/10.7.1987).
`OrderPricing::quote(..., paymentFee:)` therefore splits the fee across the order's product tax groups by their value
(`OrderTotals::allocate`, exact to the cent) and taxes each share with that group's taxes (`forTaxes`): a tax included
in prices is inside the fee (2.00 at 24% contains 0.39), an added tax comes on top, a share falling on untaxed goods
carries none, and an exempt customer pays none. The fee's VAT joins the order's tax lines; the quote returns
`payment_fee` and `payment_fee_tax` (included/added). Shipping keeps its own method-selected taxes (unchanged).

**Orders.** `orders.payment_fee` (default 0, `CHECK >= 0`) stores the fee charged, and the orders value check now
guards `total = subtotal − discount + (tax_total − tax_included) + shipping_total + payment_fee`. A new order is
charged its method's current fee; an order keeps the fee it was charged when staff edit it, and later changes to the
method's fee never re-price it. Choosing another method brings that method's fee and recalculates the totals (only when
the fee differs). The admin order form's live quote, the order page, the customer's account order page, order e-mails
(`{{payment_fee}}`, and an invoice line when there is a fee) and the sales report (*Payment fees* card, detail column,
CSV `payment_fees`) show it. Both fields are audited (`AuditTables::paymentFees()`). Tests: `PaymentFeeTest`.

**Checkout contract** (implemented, see [checkout](checkout.md)). Show each offered method with its fee, re-quote with
`OrderPricing::quote(..., paymentFee: <the chosen method's fee>)` whenever the method changes so the customer sees the
total they will pay, and place the order through `OrderAdministration::create(null, …, 'checkout', …)`, which charges
the chosen method's fee again on the server (never trust a fee sent by the browser).
