# Stripe Checkout (bundled add-on `ambresoft/stripe-checkout`)

Stripe's hosted payment page for one-time payments, built on the shared payment method contracts ([payments](payments.md)) plus a generic, provider-neutral webhook path in the `Payment` context. Decision A93.

Out of scope by design: subscriptions, Stripe Billing, Connect, Elements/Payment Element, custom card forms, stored cards and off-session payments. Card data never touches Ambresoft. A publishable key is not needed and no Stripe.js is loaded.

## How it fits the CMS (and what there is not yet)

There is no storefront checkout or cart yet (A82): orders are created in the administration, and the customer pays through the signed **payment link** (`/pay/{order}`) that staff share. That page is where "checkout" happens today; a future storefront calls the same `OrderPayments` operations, so nothing in this add-on changes when it arrives. Orders always exist before payment, so a Stripe callback can never create or duplicate an order.

| Concern | Behaviour |
| --- | --- |
| Add-on vs method | The add-on (Add-ons) and the payment method (Payment methods → Stripe) are separate switches. Bundled add-ons are installed **disabled** by `cms:addons-sync`; the method starts disabled until configured and verified. Disabling the method hides it from customers but keeps configuration, payments and webhook history. Disabling the add-on stops new payments; core orders and the other methods are unaffected |
| Where Stripe code lives | Only in `modules/ambresoft/stripe-checkout` (method, currency rules, client factory, translations). Core knows nothing about Stripe: it offers `HandlesPaymentWebhooks` / `ExpiresProviderPayments` contracts, the `payment_webhook_events` table, `PaymentWebhooks`, `OrderPayments::applyProviderResult` and the public endpoint |
| SDK | The official `stripe/stripe-php` (root `composer.json`, MIT, see `THIRD_PARTY.md`), Checkout Sessions API, `mode = payment`. Connection failures are retried by the SDK reusing the idempotency key |

## Flow

1. Staff pick **Stripe** as an order's method and share the payment link. The customer opens it (signed, 30 days) and presses **Pay**.
2. `OrderPayments::start` creates one open attempt for the **outstanding** amount (`orders.total` − captured money, exact decimals) in the **order's currency**, then calls the method. Browser input is never read.
3. The method creates a Checkout Session with: `mode=payment`, `client_reference_id` = our attempt reference, metadata (attempt reference, order number), one line item labelled "Order {number} at {store}" with `unit_amount` in minor units, `expires_at` one hour ahead, the customer's language when Stripe supports it, `success_url` = our signed return URL + `session_id={CHECKOUT_SESSION_ID}`, `cancel_url` = our signed cancel URL, and the idempotency key `cms-create-{attempt reference}`. **No** automatic tax, promotion codes, shipping options or negative lines: the order pipeline already applied discounts, taxes and shipping, and the total is sent as one honest line (a faithful breakdown is not possible when only part of an order is outstanding).
4. The Session ID is stored as the attempt's provider reference; the customer is redirected to `https://checkout.stripe.com/…` (the host is checked).
5. Stripe notifies `POST /payments/webhooks/stripe_checkout` (below). When the customer returns, the server also reads the Session from Stripe (never trusting the URL): paid → recorded, still processing → "being confirmed", open → nothing is paid. Whichever of webhook and return comes first wins; the second sees the final state.
6. The PaymentIntent ID is stored as the **capture reference** (`pi_…`), the Session ID as the provider reference; both are shown in the order's payment history and are what a future refund integration needs.

## Money

Amounts are exact decimal strings end to end. `StripeCurrencies` shifts by the currency's exponent (zero-decimal JPY/KRW…, two-decimal, three-decimal KWD/BHD… limited to two decimals as Stripe requires a zero last digit) and only when exact: an amount with more precision than Stripe accepts makes Stripe **unavailable for that order** instead of being rounded. Eight-digit maximum, positive only. ISK, UGX, HUF, TWD and currencies not on the supported list are not offered. The order currency is never converted. Stripe's per-currency **minimum charge** is not pre-checked: Stripe refuses it and the attempt fails with `provider_rejected`.
Every recorded payment must equal the attempt's amount and currency (`OrderPayments` compares the Session's `amount_total`/`currency`), otherwise it is stored as `failed` / `verification_failed`.

## Webhook endpoint

`POST /payments/webhooks/stripe_checkout`, registered in `routes/webhooks.php` **outside the browser middleware group**: no session, cookie or CSRF token, throttled. Authenticity is Stripe's signature, verified by the method over the **raw body** with the official `Webhook::constructEvent` (5-minute tolerance); a missing/invalid signature, stale timestamp or malformed body answers 400 and changes nothing. No stored signing secret → 503 (Stripe retries). Add-on disabled → 503 so no event is lost while it is off. Bodies over 1 MB → 413.

| Event | Result |
| --- | --- |
| `checkout.session.completed` | `payment_status = paid` → captured; `unpaid` (delayed method) → pending |
| `checkout.session.async_payment_succeeded` | captured |
| `checkout.session.async_payment_failed` | failed (`async_payment_failed`); the customer may pay again with a new Session |
| `checkout.session.expired` | attempt cancelled (`session_expired`); the order is **not** cancelled |

Other events, other session modes and events of the other mode (test event on a live configuration or vice versa) are acknowledged and ignored.

Processing (`PaymentWebhooks`): one database transaction stores the verified event (unique per method and Stripe event ID, identifiers and outcome only, never the payload), finds the attempt by Session ID, checks that the event's `client_reference_id` equals our own attempt reference (metadata alone is never trusted), applies the result through `OrderPayments`, and commits. Outcomes: `applied`, `unchanged`, `ignored`, `unmatched` (no such session), `mismatch` (reference disagreement). A transient failure rolls everything back and answers 500, so Stripe redelivers and the retry applies exactly once; a redelivery of a processed event answers 200 `duplicate` and writes nothing (so retries create no audit noise). Order effects (stock, status after payment, customer emails) run once when an order first becomes fully paid, through `OrderAdministration`, exactly as for staff.

Out-of-order and late events: a captured payment is final, so a later failure/expiry/unpaid event cannot undo it. If Stripe reports a **paid** session for an attempt the CMS had already ended (cancelled by the customer, expired, or replaced), the money is real: it is recorded as captured with detail `late_capture`, or `duplicate_payment` when the order was already fully paid (staff must refund the duplicate; the CMS never hides received funds). When the CMS cancels or replaces an attempt it also asks Stripe to expire the old Session so it can no longer be paid.

## Settings and secrets

| Field | Notes |
| --- | --- |
| Mode | `test` (default) or `live`; the key must match (`sk_test_`/`rk_test_` ↔ test, `sk_live_`/`rk_live_` ↔ live) and test events never act on a live configuration |
| Secret API key | Encrypted with `APP_KEY`, write-only (empty keeps it, "remove" clears it), never in props, HTML, audit records, logs or errors. Saving changed credentials verifies them once with a harmless read (`GET /v1/checkout/sessions?limit=1`); the method can be enabled only afterwards. No Stripe call happens when admin pages load |
| Webhook signing secret | `whsec_…`, encrypted and write-only; required |
| Name / description / order / status after payment | The common payment-method fields, per language |

## Configure and test locally

1. Add-ons → **Stripe Checkout** → Enable (or `php artisan cms:addons-sync` installs it disabled on deploy).
2. Stripe Dashboard (test mode) → Developers → API keys → copy the secret key. Payment methods → **Stripe** → Mode *Test*, paste the key.
3. Webhook signing secret, either way:
   - **Local, Stripe CLI** (recommended): `stripe listen --forward-to http://localhost:8000/payments/webhooks/stripe_checkout`; it prints a `whsec_…` secret for that listener. Paste it as the signing secret.
   - **Deployed**: Dashboard → Developers → Webhooks → Add endpoint `https://your-store/payments/webhooks/stripe_checkout`, select exactly `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, and copy that endpoint's signing secret. Production URLs must use HTTPS.
4. Save (credentials are verified), set the method **Enabled**.
5. Create an order with Stripe as its method (Orders → New), open the payment link (Orders → the order → payment link) in a private window, press Pay.

Try: success card `4242 4242 4242 4242`; declined `4000 0000 0000 0002`; cancel by pressing the back arrow on Stripe's page ("payment cancelled", retry works); expiry — `stripe trigger checkout.session.expired` or wait out the hour (the attempt becomes `cancelled` / `session_expired`); delayed methods (for example SEPA debit with a test account) stay "being confirmed" until `checkout.session.async_payment_succeeded`/`async_payment_failed` arrives. Verify in Orders → the order → **Payment**: state, amount, the Session (`cs_…`) and PaymentIntent (`pi_…`) references and the short detail code; Stripe's Dashboard shows the same IDs. Replaying an event (`stripe events resend evt_…`) changes nothing.

## Going live

1. Complete the Stripe account activation and use the **live** secret key (`sk_live_…`/restricted `rk_live_…` with Checkout Sessions write and read).
2. Create the live webhook endpoint (step 3 above, live mode) and paste its **live** signing secret.
3. Switch Mode to *Live* and save the live key and secret together (the mode and the key must match, and the new credentials are verified). Run one real small payment and refund it in the Stripe Dashboard before announcing the method.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Stripe is not offered on the payment page | Add-on enabled, method **Enabled**, no issues listed in Payment methods (missing/unverified credentials), currency supported, amount exactly representable |
| "Credentials were rejected" on save | Key typo, wrong mode, or a restricted key without Checkout Sessions permissions |
| Webhook shows 400 in Stripe | Wrong signing secret (each endpoint and each `stripe listen` has its own), a proxy that rewrites the body, or a clock more than five minutes off |
| Webhook shows 503 | Add-on disabled or no signing secret saved |
| Customer paid but the order is unpaid | The webhook was not delivered; open the order → Payment → **Check status** (re-reads the Session). `late_capture` / `duplicate_payment` details mean money arrived after the attempt ended |
| Payment page says "could not be reached" | Stripe unreachable or the key revoked; nothing was charged; retry. Server logs record the exception class and Stripe error code, never secrets or payloads |

## Tests

`tests/Feature/StripeCheckoutTest.php` (faked Stripe HTTP layer in `tests/Fakes/FakeStripeHttpClient.php`, no network or credentials) covers add-on discovery and independent switches, credential validation/verification/encryption, session parameters and amount/currency mapping, idempotency and double submits, provider failures, return/cancel handling, delayed payments, signature and malformed-event rejection, idempotent and out-of-order events, late and duplicate payments, mismatches, a simulated database outage with retry, and secret non-disclosure; `tests/Unit/StripeCurrenciesTest.php` covers minor-unit conversion. An optional live path is the Stripe CLI steps above against a test account; the ordinary suite never needs live credentials.

## Not included

Refunds (the PaymentIntent reference is stored so a future refund integration can call Stripe's Refunds API against it; the add-on never claims a refund happened), saved cards, subscriptions, Stripe tax/promotion codes, multi-line order breakdown, Stripe-hosted invoices, per-currency minimum-charge checks, Stripe account country/currency capability checks, and webhook history screens (events are stored in `payment_webhook_events` for diagnosis).
