# Checkout (A118)

`/checkout` is a single-page checkout for guests and signed-in customers. It adds no pricing, shipping or payment
engine. It reads the cart, prices it with `OrderPricing`, asks the Shipping context for charges, offers the payment
methods the products allow, and places the order through `OrderAdministration::create(null, …, 'checkout', …)`.

## Flow

1. **Cart → checkout.** The cart's summary shows **Proceed to checkout** once a line can be bought. An empty cart
   sends `/checkout` back to `/cart`.
2. **The page.** It renders the built-in **Checkout** layout (`page_type = checkout`, migration
   `2026_11_09_000100`). The layout has a protected main content block and is private (`no-store`, `noindex`). Its
   sections, in order:
   - **Contact.** Guests type an e-mail; customers order under their account e-mail. Phone is required for delivery.
   - **Delivery address.** Customers pick a saved address or type a new one, optionally saving it to their address
     book. Guests type one.
   - **Billing.** "Same as delivery" is on by default.
   - **Delivery method.** Shown only when the store has shipping methods.
   - **Payment method.** Shows each method's fee and description.
   - **Order notes.**
   - **Summary.** Lines, products total, discounts, shipping, payment fee, added tax, total, gift card and amount to
     pay.
   - **Header.** The layout opens with the **Checkout header** block (`checkout_header`, migration
     `2026_11_09_000200`): *Back to cart* on the left, the logo in the middle (the Company e-mail logo, made for light
     backgrounds, when one exists, else the store logo) and a dotted "secure checkout" label with a lock on the
     right. White by default (the block's *Header background color* changes it). It can be moved or configured but
     not removed. The store's own header blocks (identity, top panel, menu) hide themselves there through their
     existing *Hide on these pages* setting, which the migration fills with `/checkout`; staff can change it. The
     footer stays.
   - **Icons.** Each section heading carries an outline icon from the account icon set.
   - **Codes.** The summary has the cart's coupon / gift card field and lists the entered codes with a remove button
     (the cart's `POST/DELETE /cart/codes` routes, same rules and switch); a change asks for a new quote.
   - **Place order.** The button stays disabled until every required step is filled in (contact, a saved or complete
     typed address, billing when it differs, a delivery method when the store has any, a payment method). A note
     under it names what is missing. The server checks everything again.
3. **Live quote.** `POST /checkout/quote` (JSON, 60 a minute) answers the order as it would be placed with the current
   choices. The client sends only addresses (saved IDs or typed fields) and the chosen method IDs, 450 ms after the
   last change; every amount comes back from the server. The first delivery method that delivers is preselected; a
   payment method is always the shopper's own choice. A delivery method that accepts only some payment methods
   narrows the payment choices.
4. **Placing.** `POST /checkout` (Inertia form, 10 a minute) validates and places the order. It also clears the
   ordered lines and the cart's codes, then either:
   - redirects an online method straight to the provider through `OrderPayments::start()`, or
   - redirects to the confirmation.
5. **Confirmation.** `GET /checkout/complete/{order}` is a temporary signed URL (30 days, application key HMAC), so
   an order ID alone shows nothing (403). It shows:
   - the number, e-mail, lines, totals, address and delivery method;
   - the payment state;
   - an offline method's description (e.g. the bank deposit's IBANs);
   - for an unpaid online method, **Pay now** through the existing signed `/pay/{order}` page;
   - a link to the account's order page for customers.

## Require an account

Settings → General → Catalog → Prices → **Require customers to log in or register before placing an order**
(`general_settings.checkout_requires_account`, default off, audited, shown only while the cart is enabled).

- **Off.** Guests check out with an e-mail address.
- **On.** Guests see only **Sign in** and **Create an account**, and `POST /checkout` refuses the order without a
  signed-in active customer (`account` error). The rule lives where the order is placed, not only on the page.

Sign-in, registration, e-mail verification, profile completion and social sign-in return to `/checkout` when the visitor
came from it: `/login?return=checkout` and `/register?return=checkout` set a session flag, which is read once. Only that
fixed value is accepted, so it cannot be used as an open redirect. The guest cart merges into the customer's on sign-in
(existing `CurrentCart` behaviour).

## Guests and customers

**Guest orders**
- They have `customer_id` NULL and the typed e-mail (lower-cased).
- They are never attached to a registered customer with the same e-mail.
- They are reached only through the signed confirmation URL and the signed payment link, both carrying high-entropy
  signatures.

**Customer orders**
- They use the session's active customer profile and its account e-mail.
- Browser `customer_id` and `email` are ignored for customers.
- A saved address is looked up by ID **and** customer, so another customer's address ID is refused
  (`shipping_address_id` / `billing_address_id` error).

## Addresses

- **Shared rules.** Typed addresses use the customer address rules (`CustomerInput::addressRules`), including region
  and postal code.
- **Delivery country.** The delivery country must be a shipping country when any are configured.
- **Snapshots.** Order addresses keep the house number in the street line (`line1 + street_number`), as staff orders
  made from saved addresses do.
- **Delivery details not copied.** Floor, doorbell name and delivery notes stay on saved addresses and are not copied
  into the order. The order note covers delivery instructions.
- **Digital-only carts.** No delivery is needed when every product is `digital`: the single address is the billing
  address and no delivery method is asked for.

## Amounts, shipping and payment

**Server-decided amounts.** The browser never supplies a price, quantity, shipping charge, fee, status, customer or
internal note. `CheckoutController::INPUT` lists the only fields read, and `Checkout::place()` builds the order input
from the cart and the quote.

- **Lines.** Lines and quantities come from `CartPage::data()`, the lines the visitor can see.
- **Blocked lines.** A line with a problem (unavailable, out of stock, a quantity its rules no longer allow) stops the
  order with "review your cart" and leaves the cart untouched.
- **Pricing.** `OrderPricing::quote()` covers sales, customer group prices, promotions, the cart's coupon (re-checked
  against the real addresses; one that no longer applies says why and is left out), taxes, the shipping charge and the
  payment fee. Its VAT follows the products (A117).

**Shipping.** For each active method the server calls `OrderShipping::quote()` (manual rates, carriers such as the ACS
add-on), with the address, lines, subtotal and chosen payment method. Only available methods are offered.
`OrderAdministration::create()` calculates the charge again when the order is placed. A store without shipping methods
takes orders without one.

**Payment.** The checkout offers a payment method only when it is:
- active and configured (`PaymentMethodChoices`);
- not excluded by the products (`PaymentMethodRestrictions::forProducts()`);
- allowed by the delivery method.

`create()` checks the amount limits again. Online methods use `OrderPayments::start()`, which is idempotent: it reuses
a fresh redirect. Coming back from the provider lands on the signed payment page, which captures and verifies
server-side; returning alone never marks an order paid. Webhooks are unchanged.

**Gift card.** The cart's gift card pays what its balance covers. `GiftCardRedemptions::redeemAtCheckout()` is a
trusted non-staff path in the checkout's transaction: it locks the card, takes the balance, records a captured
`gift_card` payment and the card transaction with no staff actor. If the card can no longer pay, the order rolls back
with the reason.

## Idempotency, retries and the cart

- **Submission key.** The page generates one `submission_key` (UUID) per visit. `OrderAdministration` stores
  `sha256(customer or e-mail | key)` with a fingerprint of the validated input; the same key with different data is
  refused.
- **Repeats.** Before doing anything, `Checkout::place()` asks `OrderAdministration::placed()` whether this submission
  already placed an order and answers with it.
- **One transaction.** In one transaction `place()`:
  1. locks the cart row, so a concurrent submission of the same cart waits and then finds the order;
  2. creates the order;
  3. redeems the gift card;
  4. deletes the ordered lines and the cart's coupon and gift card.
- **The cart.** Nothing leaves the cart unless the order exists.
- **Optional address copy.** Saving a new address to the account runs after the commit; a refusal (e.g. the address
  book changed in another tab) never undoes the order.
- **Retries.** A retried request with the same key reaches the same order and the same open provider attempt.

## Stock

Placing an order checks each line against the product's stock rule at that moment (`Carts::problem`). The order starts
in the merchant's initial status (`is_initial`, by default *Open* with stock action *none*), so stock is reserved or
deducted only when staff move it to a status that does so, as for staff-created orders. Two shoppers may therefore both
order the last unit; staff resolve it when processing. Reserving stock at placement would mean an initial status that
decreases stock and is a separate decision.

## Security

- **Request protection.** CSRF (web middleware; the quote call sends `X-XSRF-TOKEN`), rate limits on quote, place and
  confirmation.
- **Visibility.** Customers only ever see their own addresses.
- **Confirmation links.** Signed, with the global `Referrer-Policy: no-referrer`, so a confirmation link is not leaked
  to linked sites.
- **Switches.** *Disable cart and checkout* answers every checkout route with 404, and a closed store shows the
  closed page.

## Accessibility and localization

- **Structure.** Every section is a labelled region with real `<label>`s, `fieldset`/`legend` radio groups, `aria-invalid`
  and `role="alert"` errors, focus on the first invalid field after a refused submit, and `aria-busy` while
  re-quoting.
- **Autofill.** `autocomplete` tokens are set for contact and address fields.
- **Translations.** All storefront text is in `storefront.checkout_*` keys (en, el, es, fr, it, nl); the setting's
  label is in `settings` (en, el).

## Not built

- Choosing which order statuses hold stock at placement.
- A gift card that covers the whole order still asks for a payment method.
- Payment method logos at checkout (their images are served only to the admin).
- Terms acceptance.
- Guest account creation during checkout.
- Order-confirmation e-mail content beyond the existing status e-mails.

## Invoice VAT verification (A158)

Settings → General → **Checkout** configures invoice eligibility. It starts disabled; the checkout block's existing
**Show invoice request** flag must also be on. Apply migrations (including `2026_11_12_104155_create_checkout_invoice_verification`
and `2026_11_12_111100_add_checkout_vat_provider_choices`) and rebuild
assets before enabling. Staff need `settings.manage`. Credentials are encrypted with the application key and write-only:
blank fields preserve stored values; explicit Clear controls remove them. Both AADE fields must be configured together.
Provider selection is separated into **General options**, **Greek companies** and **Other countries** subtabs; unrelated
credentials stay hidden and unsaved drafts survive tab switches. Provider edits use optimistic locking and invalidate existing verification. Audit records omit credentials, VAT numbers
and business names; validation redirects do not flash credentials into the session.

- **Free VIES (default for both country groups):** use the European Commission's keyless
  [VIES SOAP service](https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl). It covers EU registrations
  for intra-EU trade, not all domestic businesses and not Great Britain or other non-EU countries. Greece is normalized
  from GR to the service's EL prefix. A false result, mismatched response, service fault or outage never grants approval;
  there is no automatic switch to a paid provider. See [the Commission's explanation of invalid results](https://europa.eu/youreurope/business/finance-and-tax/vat/check-vat-number-vies/index_en.htm).
- **Greek customer companies (VAT registration country GR), optional AADE:** use the AADE **Basic Business Registry Information**
  service, not myDATA invoice submission. Obtain its special service username/password through
  [AADE's registry service](https://www.aade.gr/anazitisi-basikon-stoiheion-mitrooy-epiheiriseon), not a TAXISnet password.
  The merchant must enrol and satisfy its service terms, including end-user identification; the checkout requires a
  signed-in active customer for Greek lookups. A valid checksum alone is insufficient: the provider must return the same
  AFM, an active business record and no cessation date. A business outside the normal VAT system is not automatically
  excluded from invoice requests. The merchant's own country and shipping country do not determine the provider.
- **Greek AFM format/checksum only, optional:** explicitly reuses `TaxIds::greek`, the existing Company → Legal check.
  It is free, offline and requires no customer sign-in or credentials. It does not prove registry existence, active business
  status or ownership. Choosing it deliberately accepts invoice requests based on checksum alone; the stored method is
  `local` and shopper/staff presentation identifies the lower assurance instead of claiming registry verification.
- **Other supported registrations, optional VAT Sense:** use [VAT Sense](https://vatsense.com/documentation#validate), with the merchant's
  own API key (account/plan required). Coverage is non-Greek EU members, Great Britain (GB), Australia, Norway,
  Switzerland, South Africa and Brazil. This is not worldwide tax-ID coverage; other countries and Northern Ireland's
  separate XI identifier are currently unsupported. No account, subscription or live provider request is created by
  installation. Missing credentials, unsupported registrations, invalid numbers and provider failures never approve an
  invoice. The merchant selects one method for Greek registrations and one for other countries. VIES needs no credentials;
  AADE or VAT Sense credentials are required only when their respective method is selected and invoice requests are enabled.

Selecting **Request invoice** opens a country/VAT dialog. It selects the checkbox only after the server verifies the
number; cancelling leaves it unselected, and editing removes selection until reverified. Greek guests are directed to
the existing checkout sign-in dialog only when AADE is selected; VIES and checksum-only checks also work for guests. Ordinary receipt checkout stays available after a provider failure.

`POST /checkout/invoice/verify` uses web-session/CSRF protection, the current nonempty cart and open-store checks, a
six-per-minute visitor throttle and a 60-per-minute shared provider budget. All external integrations use fixed HTTPS endpoints,
no redirects, a three-second connection timeout and a 15-second total timeout, without automatic billable retries.
SOAP parsing rejects DTD/entity declarations and external network resolution. Raw provider bodies and credentials are
never reported in errors. Responses are private/no-store.

A successful result is held server-side for 15 minutes, tied to the cart UUID, current customer (or guest), normalized
country/VAT and settings version. A matching repeat lookup reuses only that session's result. The browser sends the
country/VAT with its invoice request, but cannot supply approval or company details. Order placement checks the proof
again and atomically creates one `order_invoice_verifications` snapshot with the provider, verification time, country,
VAT and returned business name; staff see these details in the shared **Invoice details** card above Products, with labelled business name, VAT number,
registration country, validation method and check time. The header keeps only the compact invoice-request indicator. An idempotent order retry returns the existing
order without another lookup. Old orders are not backfilled. The snapshot follows the order's existing access/retention
rules and cascades with order deletion.

Registry providers verify a registry number for an invoice **request**; checksum-only policy checks syntax instead. It does not verify that the shopper owns that company,
issue a fiscal invoice, submit to AADE/myDATA, change VAT calculation or grant a VAT exemption. The existing merchant
invoicing workflow remains responsible for its billing and issuance obligations.

Verification: `CheckoutTest`, `CheckoutSettingsTest`, localization and general-settings tests; frontend
`Checkout.test.tsx`, `CheckoutInvoiceDialog.test.tsx` and `GeneralSettings.test.tsx`. Provider contracts use isolated
HTTP fakes; real credentials and live service availability require merchant setup.

Local verification (2026-11-12): the complete backend check passed (1,513 tests / 161,125 assertions, Pint and PHPStan);
all 656 frontend tests, TypeScript, Prettier and the production build passed. ESLint reported no errors and two existing
carousel hook-dependency warnings in `storefront/themes/Default/parts.tsx`. The rebuilt Greek admin page was checked
in the browser: three subtabs, free VIES defaults and no unrelated credential fields. Both additive migrations were
applied locally; invoice requests remain disabled until the merchant enables them. Provider responses were faked in
tests; no live tax registry lookup or real credential was used.

UI follow-up (2026-11-12): the VAT modal uses the shared searchable country selector and a compact right-aligned
Submit button with its existing checking state. Order invoice details have their own shared AdminCard. The follow-up
passed 99 backend tests (11,084 assertions), 30 targeted frontend tests, PHPStan, TypeScript, lint, formatting and build.

Document/account follow-up (2026-11-12): invoice identity now appears in the shared printed/emailed order-summary
renderer and the owner's account order detail, with the document's plain typography and the account page's existing
panel styling. Empty/legacy requests have no invented identity; validation methods stay admin-only. Verification covered
68 backend tests, including cross-account access denial, 16 targeted frontend
tests, PHPStan, TypeScript, targeted ESLint, Pint and production build. The Greek demo summary was visually checked.
Local order #25 has no invoice request or VAT snapshot; demo #100026 retains the fictional saved registry response.

### Invoice countries (2026_11_12_170048)

Checkout settings also have a **Supported countries** tab. Its searchable country table uses the shared status badges
and switches, keeps drafts while changing tabs/search, and saves with the parent settings form. The additive migration
copies the shop's currently enabled `shipping_countries` into `checkout_invoice_countries`, so those countries are already
selected on the first visit. Thereafter invoice coverage is independent: changing shipping countries never overwrites a
merchant's invoice choices. Country codes come from the shared ISO catalog; a unique primary key and uppercase code
check protect storage. Selection changes are audited and share the checkout settings lock/version and transaction.

The checkout VAT selector contains only the intersection of selected invoice countries and the current provider's country
coverage. Greek registrations still use the Greek method; other registrations use the international method. Admin rows
explain when a selected country lacks provider coverage. Clearing all countries hides invoice requests but keeps receipt
checkout available. Both VAT verification and final order placement enforce the allowed countries on the server, including
previously approved proofs after the merchant changes settings. Existing order invoice snapshots remain historical data.

Apply the additive migration and rebuild assets. Rolling it back removes the invoice country choices only; it does not
modify shipping countries, orders, credentials or saved VAT evidence.

Verification: checkout/provider, settings, localization and audit regressions passed, including default shipping-country
selection, independently saved/cleared lists, invalid/unauthorized writes, forged verification countries and revoked
session proofs. The settings/checkout/modal frontend tests, PHPStan, TypeScript, targeted ESLint, Pint, formatting and
production build passed. The local migration copied Greece from the shipping list; the Greek admin tab showed it selected.
Provider responses remained isolated HTTP fakes; no real registry calls or credentials were needed.
