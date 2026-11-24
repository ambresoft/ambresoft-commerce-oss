# Shipping methods, carriers and shipments — Phase 11

The `Shipping` context owns shipping methods, their rates, carrier configuration (settings and named accounts) and
the carrier shipments of orders. Carriers are add-ons implementing one provider contract; ACS Courier is the first,
`ambresoft/acs-courier`, a paid add-on distributed separately and installed by ZIP upload (A146). Decisions A89 (shipping) and A90 (ACS Courier).

## Adaptation to the current order lifecycle

There is no storefront checkout yet (A82), so shipping attaches to staff-created orders, like payments (A86):

- The order editor offers the active methods. The charge is always computed on the server — by the quote endpoint
  ("Calculate") and again at save time — never taken from the browser. Staff may enter a charge instead (for example
  while a carrier is unreachable); the order then stores both the entered and the calculated amount.
- Carrier prices are requested **before** the order transaction (no remote call while rows are locked) and cached for
  15 minutes per exact context (method and version, account and version, carrier settings version, destination,
  weight, item count, value, currency, payment method).
- Orders store the method, its name and a JSON snapshot (calculation, weights, rate or carrier quote, taxes, override)
  next to `shipping_total`; later method, rate or carrier changes never re-price existing orders. Editing an order
  recalculates shipping only when something that affects it changed (method, address, contents, payment method,
  override) or when staff tick "Recalculate shipping".
- A future checkout calls the same `OrderShipping` port.

## Ownership

| Layer | Types |
| --- | --- |
| Domain | `ManualRates` (area/bracket selection, base + per-unit), `ShippingWeights` (physical/chargeable weight, per-product costs, kg conversion), `ShipmentTransitions` (status-once rules) |
| Application | Contracts `ShippingProvider` + capabilities `QuotesRates`, `VerifiesAccounts`, `CreatesShipments`, `CancelsShipments`, `PrintsLabels`, `TracksShipments`, `ManifestsShipments`, `MapsOrderStatuses`; exceptions `ShippingProviderRejected` (the carrier refused; its message is shown) and `ShippingProviderUnavailable` (outcome unknown / try later); commands `CarrierAccount`, `Destination`, `RateRequest`, `CarrierQuote`, `ShipmentDraft`, `CreatedShipment`, `ShipmentReference`, `ShippingDocument` (PDF only), `TrackingResult`, `Manifest`, `ManifestResult`; `ShippingProviderRegistry` |
| Infrastructure | `CarrierSettings` (rows, encrypted secrets, issues), `ShippingCarriers` (settings/account writes, verification), `ShippingMethods` (admin writes), `ShippingQueries`, `ShippingCalculator`, `ShippingCharges` (implements Order's `OrderShipping` port), `Shipments`, `Manifests`, `ShippingProviderExtensionPoint`, `ShippingServiceProvider` |
| Presentation | Admin `ShippingMethodController`, `ShippingCarrierController`, `OrderShipmentController`. Navigation: **Shipping & Taxes → Shipping methods** and **→ Carriers** |

Shared: `App\Shared\Application\Command\SettingField` and `App\Shared\Infrastructure\Settings\FieldValues` (typed,
schema-driven fields; secrets encrypted and write-only) are used by payment methods and carriers alike, so carrier
add-ons need no frontend code.

## Persistence (migration 2026_10_17_000100)

| Table | Purpose |
| --- | --- |
| `shipping_methods` | calculation `manual`/`carrier`, optional provider + account (composite FK `(account_id, provider)`), enabled, position, weight limits, free-shipping rule, carrier amount `gross`/`net`, surcharge, lock version |
| `shipping_method_translations` | name, description, delivery time per locale |
| `shipping_method_rates` | manual rates: rate area, weight bracket `[from, to)`, base, per unit |
| `shipping_method_areas` | optional delivery-area restriction of carrier methods |
| `shipping_method_taxes`, `shipping_method_payment_methods`, `shipping_method_customer_groups` | taxes on the charge; allowed payment methods; allowed customer groups (empty = all) |
| `shipping_carrier_settings` | one row per provider: general settings JSON + encrypted secrets |
| `shipping_carrier_accounts` | named accounts per provider (unique name per provider), settings + encrypted secrets, verification fingerprint |
| `order_shipments` | one row per carrier shipment: state `creating`/`created`/`failed`/`cancelled`, unique `request_key`, at most one `creating` per order (partial unique index), account used, tracking number and extra numbers, pickup date, weight, parcels, COD amount, label printed, pickup list, tracking state/events, applied status events |
| `shipping_manifests` | issued pickup lists (provider, account, number, date, unique `request_key`) |
| `orders` | `shipping_method_id` (restrictive FK), `shipping_method_name`, `shipping_details` (snapshot) |

Migration 2026_10_17_000200 adds an optional `image_media_id` (private media file, unique, restrictive FK) to
`shipping_methods` and `payment_methods` — e.g. a carrier or provider logo for the future storefront. The image is
uploaded with the editor's normal save (multipart, update method-spoofed), validated like other images (JPG/PNG/WebP,
≤ 5 MB, ≤ 6000 px), stored before the row changes and discarded if the save fails; replaced files are kept for the
media retention policy. Admins see it through `/admin/{shipping|payment}-methods/{id}/image`; public delivery belongs
to the storefront phase.

All monetary columns are exact decimals in the primary currency. A primary-currency change re-denominates rates and
surcharges with the product prices and advances every method version; lowering the precision is refused while a rate
or surcharge would be rounded. Rate areas used by methods cannot be deleted. Audit capture excludes credentials,
account identifiers, tracking events and carrier documents.

## Calculation

1. The method must be enabled (or be the order's current method), allowed for the customer's groups, and the
   physical order weight must be within its limits (store weight unit).
2. **Weights**: physical = all lines with a known weight; chargeable = without free-shipping products when the method
   respects free shipping (those products add no weight and no per-product shipping cost); an order of only such
   products ships free.
3. **Manual**: the most specific matching rate area (region > country > default; ties: lowest ID) with a bracket
   containing the weight; charge = base + per unit × (weight − bracket start). No bracket → not offered (never a
   fallback to a less specific area).
4. **Carrier**: optional area restriction; enabled provider with a complete account; the carrier quote (weight
   converted to kg only at this boundary) in the order currency; charge = gross or net + surcharge.
5. Per-product shipping costs × quantity are added; the selected taxes are calculated on the charge with the order's
   address and tax method and merged into the order taxes.
6. Unavailable methods return a reason (shown in the editor); the save is refused unless staff entered a charge.
   Payment restrictions are checked at save time.

## Shipments

- **Create**: the carrier's declared fields are prefilled from the order and editable; the address on the order never
  changes. A claim row (`creating`, unique request key) is inserted in its own nested transaction **before** the
  non-idempotent carrier call. A refusal marks it `failed`; a timeout or unknown outcome keeps the claim with an
  explanation, blocking further attempts until staff checked at the carrier and released it ("Mark as failed"). The
  same request key never calls the carrier twice; a second voucher for an order needs explicit confirmation.
- **Follow-up** operations (label, tracking, delete) always use the account that created the shipment.
- **Labels and lists** are served only when the content is a real PDF, with `nosniff`, `no-store` and a sandbox CSP.
- **Pickup lists** are serialized per provider, account and date (cache lock), idempotent per request key; vouchers
  found on a list are marked and can no longer be deleted from the CMS.
- **Status events**: `shipment_created`, `delivered`, `returned` map to statuses configured per carrier. Each is
  applied once per shipment through `OrderAdministration::changeStatus` (stock and emails as usual) and only while the
  order still has the status the shipment expects — a status changed by staff is never overwritten. Tracking is
  refreshed by staff; no state is ever inferred beyond what the carrier reports.

## Permissions

| Capability | Grants |
| --- | --- |
| `shipping-methods.view` | Shipping methods list |
| `shipping-methods.manage` | Create, edit, enable, order and delete methods |
| `shipping-carriers.manage` | Carrier settings and account credentials (super admin only by default) |
| `orders.shipments` | Create vouchers, print labels, update tracking, delete vouchers, pickup lists |

The Carriers page is visible with either `shipping-carriers.manage` or `orders.shipments`. Reference roles: store
admin gets shipping-methods view/manage and `orders.shipments`; order manager gets `orders.shipments`.

## ACS Courier (add-on `ambresoft/acs-courier`, installed separately)

Implements every capability against the ACS Web Services (specification "ACS Rest API Web Services", April 2026):
`POST https://webservices.acscourier.net/ACSRestServices/api/ACSAutoRest` with `{"ACSAlias", "ACSInputParameters"}`,
the account's API key in the `ACSApiKey` header and `Company_ID`, `Company_Password`, `User_ID`, `User_Password` in
every call.

- **Errors**: HTTP 403 = API key refused; 406 = more than 10 calls per second (calls are throttled to 8/s per key);
  other HTTP 200 answers are successful only when `ACSExecution_HasError` is false and the operation has no
  `Error_Message`. Read-only calls are retried once on timeouts, 406 and 5xx; voucher creation, deletion and pickup
  lists are never retried automatically. Logs (`acs_call`) contain the alias, account ID, status, duration and outcome —
  never inputs, credentials or keys.
- **Prices** (`ACS_Price_Calculation`): Greece only — ACS cannot price Cyprus or abroad, so those destinations get no
  quote (never a guessed one). Origin station = the two letters of the billing code; destination station from
  `ACS_Area_Find_By_Zip_Code` (town matched against the area names, cached a day per account and postcode), else
  `ACS_Address_Validation` for Greek addresses, else "destination ambiguous". `REM` is added for inaccessible areas
  (ΔΠ), `COD` when the order's payment method is the configured COD method. Weight: product weights when enabled and
  known, otherwise the default weight; minimum 0.5 kg; above 100 kg no online price. Net and VAT amounts come from
  `Total_Ammount` and `Total_Vat_Ammount`.
- **Vouchers** (`ACS_Create_Voucher`): Greece only (Cyprus needs content declarations, not supported). Fields:
  recipient, company, street and number (split only when the address line clearly contains a number), postcode,
  area, mobile/landline (Greek numbers, 69… = mobile), email, weight 0.5–999 kg, parcels 1–99 (explicit; the
  additional parcel numbers come from `ACS_Get_Multipart_Vouchers`), pickup date (not past, not Sunday), cash on
  delivery of the outstanding amount (EUR orders; cash or cheque) and delivery notes (≤ 250 characters, prefilled from
  the customer note if configured). `Reference_Key1` = order number, `Reference_Key2` = `order-{id}`,
  `Charge_Type` 2, `Acs_Station_Branch_Destination` 1 as in the specification's example.
- **Labels** `ACS_Print_Voucher` (laser A4 = 2, thermal = 1); **delete** `ACS_Delete_Voucher` (ACS refuses after the
  pickup list); **pickup lists** `ACS_Issue_Pickup_List` (ACS refuses while vouchers of the day are unprinted; the
  blocking vouchers are listed with their orders), `ACS_Get_Pickup_Lists`, `ACS_Pickup_List_Display_Voucher`,
  `ACS_Print_Pickup_List`.
- **Tracking** `ACS_Trackingsummary` + `ACS_TrackingDetails`: status 4 with `delivery_flag` 1 = delivered; 6 =
  returning; 7 with `returned_flag` 1 = returned; a non-delivery reason code = exception; otherwise in transit or
  created. Nothing else is inferred.

### Configuring ACS (two accounts)

1. **Add-ons** → Upload `acs-courier-<version>.zip`, then enable ACS Courier (an upload installs it disabled). Disabling it later keeps methods,
   accounts and shipments; its methods are simply not offered.
2. **Shipping & Taxes → Carriers → ACS Courier → General settings**: default weight, calculate product weight, COD
   payment method, order status after the voucher is created, delivered and returned statuses, shipment notes.
3. **Accounts → Add account** for each ACS contract, e.g. "Athens store" and "Thessaloniki warehouse": account name,
   Company ID, Company password, ACS username (`User_ID`), ACS password (`User_Password`), Billing code
   (`Billing_Code`), API key (`ACSApiKey`). Secrets are stored encrypted and never shown again; leave them empty to keep
   them. **Test connection** performs a harmless read with the account.
4. **Shipping & Taxes → Shipping methods → New**: real-time carrier rates, carrier ACS Courier, the account to use,
   gross or net price, surcharge, taxes, optional areas/payment methods/customer groups → enable. A manual-rate method
   may also choose ACS and an account to create vouchers while charging its own rates.

### Daily workflow

Order page → **Shipping** card → *Create voucher* (check the prefilled fields) → *Print voucher* (laser or thermal) →
at the end of the day **Carriers → Pickup lists** → account and date → *Issue pickup list* (print unprinted vouchers
first) → *Print*. Use *Update tracking* on the order to read ACS tracking; delivered/returned statuses apply once.
*Delete voucher* works until the voucher is on a pickup list. If a creation was interrupted, check at ACS and use
*Mark as failed* before trying again.

## Limitations

No storefront checkout or customer method choice; no carrier webhooks or scheduled tracking (staff refresh); no
public tracking page or link; no pickup-point/locker selection; packaging boxes are not used to split parcels;
other carriers are not included.
