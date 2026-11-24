# Add-on developer guide (contract 4)

Add-ons extend the CMS without changing core files. First-party add-ons ship with the CMS in `modules/{vendor}/{name}`
(for example Stripe Checkout); third-party add-ons are uploaded as ZIP packages in **Add-ons** and extracted to `storage/addons/{vendor}/{name}`.
Both use the same manifest, lifecycle, permissions and extension points. A complete minimal example is
[`docs/examples/addons/acme-bank-transfer`](examples/addons/acme-bank-transfer). Architecture: [module system](architecture/module-system.md), decision A85.

Contract 3 is contract 2 plus the [public catalog](#public-catalog), [storefront widgets](#storefront-widget-contribution),
the [settings panel](#settings-panel), `AddonStatus` and `uninstall-clears`. Declare `3` if you use any of them: a CMS without them then refuses your add-on instead of failing when it runs. Contract 4 adds the read-only canonical URL discovery contract described in [XML Sitemap](architecture/xml-sitemap.md#core-boundary). Add-ons that require `StorefrontUrls` must declare contract 4 so older hosts reject them before loading. The CMS accepts contract 2 and 3 add-ons unchanged.

Add-ons run with the application's privileges. Administrators should only install add-ons from developers they trust;
the installer validates packages but does not sandbox code.

## Package layout

```
acme-bank-transfer/
├── composer.json            # the manifest (required)
├── src/                     # PSR-4 code in your own namespace
│   └── Infrastructure/
│       ├── BankTransferServiceProvider.php
│       └── BankTransferPaymentMethod.php
├── lang/{locale}/*.php      # optional, loaded by your provider under your namespace
└── database/migrations/     # optional, owned migrations
```

## Manifest

`composer.json` in Composer format; CMS metadata lives in `extra.cms-module`:

```json
{
    "name": "acme/bank-transfer",
    "description": "Offline bank transfer payments.",
    "version": "1.0.0",
    "authors": [{ "name": "Acme" }],
    "autoload": { "psr-4": { "Acme\\BankTransfer\\": "src/" } },
    "require": { "acme/core": "^1.2" },
    "extra": {
        "cms-module": {
            "contract": 3,
            "title": "Bank transfer",
            "icon": "icon.svg",
            "cms": "^1.0",
            "provider": "Acme\\BankTransfer\\Infrastructure\\BankTransferServiceProvider",
            "requires": ["acme/core"],
            "migrations": "database/migrations",
            "contributes": {
                "payment-methods": { "acme.bank-transfer": "Acme\\BankTransfer\\Infrastructure\\BankTransferPaymentMethod" }
            }
        }
    }
}
```

| Key | Rule |
| --- | --- |
| `name` | Unique `vendor/name` (lowercase); the folder must be `{vendor}/{name}`. Names of bundled add-ons are reserved |
| `version` | Semantic version `MAJOR.MINOR.PATCH[-pre]`; upgrades must increase it |
| `description`, `authors[0].name` | Shown in the Add-ons list (≤ 500 / 100 characters) |
| `autoload.psr-4` | Required; your own namespace(s) mapped to folders inside the package. Namespaces of the CMS (`App\`), its libraries or other add-ons are refused |
| `extra.cms-module.contract` | `2` or `3`, the add-on API your code needs. Use it to state a requirement, because a CMS refuses a contract it does not know |
| `extra.cms-module.title` | Display name (≤ 100 characters) |
| `extra.cms-module.icon` | Optional package-relative image (`svg`, `png`, `jpg`, `webp`, ≤ 256 KB) shown next to your add-on in the Add-ons list, e.g. your company's mark. It renders on a light tile, so use a dark or coloured logo. SVGs with scripts, handlers or embedded HTML are refused. Without one, a default puzzle icon is shown |
| `extra.cms-module.cms` | Supported CMS versions (Composer syntax: `^1.0`, `~1.2`, `>=1.0 <2.0`, `1.*`, `\|\|`). The CMS version is shown on the Add-ons page |
| `extra.cms-module.provider` | A Laravel `ServiceProvider` inside your namespace. Do not use `extra.laravel.providers` |
| `extra.cms-module.requires` | Other add-ons this one needs; each also in `require` with a version constraint |
| `extra.cms-module.migrations` | Optional folder of owned migrations named `YYYY_MM_DD_HHMMSS_{vendor}_{name}_*.php` (non-alphanumerics → `_`, e.g. `2026_01_01_000000_acme_bank_transfer_create_references.php`) |
| `extra.cms-module.contributes` | Declarations for published extension points (below); unknown points are refused |
| `extra.cms-module.uninstall-clears` | Optional list of your own tables (prefixed `{vendor}_{name}_`) to empty on uninstall, for data that must not survive a reinstall, such as credentials. Everything else is kept, and no add-on code runs |

## Boundaries (context-first DDD)

- Depend only on published contracts: `App\Payment\Application\Contract\*` and `App\Payment\Application\Command\*`, the
  [public catalog](#public-catalog), the shared `App\Shared\Domain\Service\Decimal`, Laravel itself, and your own code. Do
  not call core controllers, Eloquent records or Infrastructure classes; they may change without notice.
- Keep your own business rules framework-free where practical (e.g. `src/Domain`), adapters in `src/Infrastructure`.
- Never compute stock, prices or taxes yourself; use amounts the CMS gives you. Money is a decimal string, never a float.
- Own your tables (prefix them with your vendor/name). Reference core rows through foreign keys you choose deliberately.

## Service provider

Registered only while the add-on is enabled, after its dependencies. Use `register()` for container bindings and
`boot()` for translations (`$this->loadTranslationsFrom(__DIR__.'/../../lang', 'acme-bank-transfer')`), routes,
navigation (`App\Admin\Navigation\AdminNavigation::add`) or capabilities (`PermissionRegistry::add`, namespaced like
`acme.bank-transfer.manage`). A provider must not migrate, install or write merchant data.

## Extension point: payment methods

Declare `"payment-methods": { "id": "Class" }`. Identifiers are unique (`[a-z][a-z0-9_.-]{1,59}`; prefix with your vendor).
The class implements:

- `PaymentMethod` (offline: staff record the payment): `id()`, `provider()`, `defaultText($locale)` (default
  customer-facing name/description), `settings()` (list of `SettingField`: `text`, `secret`, `select`, `boolean`;
  labels are translation keys; `credential: true` marks fields the provider verifies), `validate($values)` (field →
  message), `supports(PaymentAmount, $values)` (currency/precision/amount rules; amount `"0"` when only listing).
- `RedirectPaymentMethod` (customer pays at the provider) adds `verify($values)` (credential check, `null` = ok),
  `start(PaymentStart, $values)` → `PaymentRedirect(providerReference, url)`, `complete(PaymentCheck, $values)` →
  `PaymentResult` (capture **and verify** server-side when the customer returns) and `refresh(PaymentCheck, $values)`.
  Use `PaymentStart::$reference` as your provider's idempotency key and custom reference; throw
  `PaymentProviderUnavailable` for outages so the attempt stays retryable.

Two optional capabilities for providers with asynchronous confirmation: `HandlesPaymentWebhooks::webhook(PaymentWebhook, $values)`
verifies the provider's signature over the raw body and returns a `PaymentWebhookEvent` (event ID, type, the attempt's
provider reference and our reference, and a `PaymentResult` or none), throwing `PaymentWebhookRejected` for untrusted
notifications; the CMS serves `POST /payments/webhooks/{method id}` without session or CSRF token, stores each event
once, checks the attempt and applies the result exactly once. `ExpiresProviderPayments::expire(PaymentCheck, $values)`
ends an unfinished provider payment when the CMS cancels or replaces an attempt (best effort). `modules/ambresoft/stripe-checkout`
is a complete example ([Stripe Checkout](architecture/stripe-checkout.md)).

The CMS stores settings (secrets encrypted, never sent to the browser), renders the editor, enforces enabled state,
verification and availability, records attempts and payments, prevents duplicates and applies the status after
payment. Your method never writes payment or order data.

## Extension point: shipping providers

Declare `"shipping-providers": { "id": "Class" }` (same identifier rules; one provider per identifier across the CMS).
The class implements `App\Shipping\Application\Contract\ShippingProvider`: `id()`, `name()`, `settings()` and
`accountFields()` (lists of `SettingField`; types `text`, `textarea`, `secret`, `select`, `boolean`, `date`, `integer`,
`decimal`, `order_status`, `payment_method`; labels are translation keys; `credential: true` marks account fields),
`validateSettings($values)` and `validateAccount($values)` (field → message). Add only the capabilities your carrier
really has:

| Interface | Methods |
| --- | --- |
| `QuotesRates` | `quote(RateRequest, CarrierAccount, $settings)` → `CarrierQuote::priced(net, tax, gross, currency, details)` or `CarrierQuote::unavailable(reason, message)` — never an estimate |
| `VerifiesAccounts` | `verify(CarrierAccount, $settings)` (a harmless read; throw on refusal) |
| `CreatesShipments` | `shipmentFields()`, `shipmentDefaults(ShipmentDraft, $settings)`, `createShipment(ShipmentDraft, $input, CarrierAccount, $settings)` → `CreatedShipment` |
| `CancelsShipments` | `cancelShipment(ShipmentReference, CarrierAccount, $settings)` |
| `PrintsLabels` | `labelFormats()` (value → label key), `label(ShipmentReference, CarrierAccount, $settings, $format)` → `ShippingDocument` (PDF) |
| `TracksShipments` | `track(ShipmentReference, CarrierAccount, $settings)` → `TrackingResult` (`created`, `in_transit`, `delivered`, `returning`, `returned`, `exception`, `unknown`) |
| `ManifestsShipments` | `manifestLabel()`, `issueManifest()`, `manifests()`, `manifestContents()`, `printManifest()` |
| `MapsOrderStatuses` | `orderStatusFor($event, $settings)` for `shipment_created`, `delivered`, `returned` |

Throw `ShippingProviderRejected(reason, message)` when the carrier refused (the message is shown to staff) and
`ShippingProviderUnavailable` when the outcome is unknown (timeouts, unreadable answers). Weights reach you in kg;
amounts as exact decimal strings. The CMS stores settings and accounts (secrets encrypted, never sent to the
browser), renders all forms, prevents duplicate shipments, applies order statuses once and records everything; your
provider never writes order or shipping data. The ACS Courier add-on (`ambresoft/acs-courier`, distributed separately from the CMS) is a complete example.

## Extension point: search providers

Declare `"search-providers": { "id": "Class" }` (same identifier rules). The class implements
`App\Search\Application\Contract\SearchProvider`:
- `settings()` / `validateSettings()` for merchant settings, rendered and stored by the CMS;
- `configure()`, `health()` and `prepare()`;
- `index()` / `remove()` with an optional rebuild target;
- `search(SearchQuery)` → `SearchResult` (ranked IDs, total, facet rows);
- `startRebuild()` / `count()` / `finishRebuild()` / `discardRebuild()`;
- `indexedIds()` and `cleanup()`.

The CMS decides what is indexed (`SearchDocument`) and who may see what: every `SearchQuery` carries the searchable
categories, the visitor's customer groups and the products to hide, and the CMS re-reads every hit before showing it.
Throw `SearchUnavailable` (`unavailable`, `not_initialized`, `rejected`) instead of returning an empty result when the
engine cannot answer. Connection secrets belong in deployment configuration (merge your own config file).
`modules/ambresoft/typesense` is a complete example ([search](architecture/search.md)).

## Extension point: settings

Declare `"settings": "Vendor\\Addon\\Settings"` for an add-on that needs merchant settings of its own. The class
implements `App\Shared\Application\Contract\AddonSettings`: `settings()` (list of `SettingField`) and
`validateSettings($values)` (rules across fields, field → message). The CMS stores the values in `addon_settings`
(secrets encrypted), shows a **Settings** button on the enabled add-on for staff with `addons.manage`, and returns the
values through `App\Shared\Application\Contract\AddonSettingValues::values('vendor/name')`.

Other published contracts an add-on may use:

- `App\Cart\Application\Contract\AbandonedCarts::idleSince($before, $afterId, $limit)`: carts of active customers
  with an account that hold products and were last changed before a moment (`AbandonedCart`: e-mail, first name,
  language, last change, product names and quantities, cart URL).
- `App\Shared\Application\Contract\StoreMailer::send($to, $subject, $html, $text, $locale)`: one e-mail in the
  store's layout from the store's sender, through the delivery method in Settings → General; returns null when sent.

- `App\Storefront\Application\Contract\StorefrontCatalog`: the [public catalog](#public-catalog).
- `App\Shared\Application\Contract\AddonStatus::isEnabled($name)`: whether an add-on is enabled right now. A provider
  outlives a disable in a long-running process, so routes of your own should ask.

To run on a schedule, register it in your provider's `boot()` with
`$this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->call(...)->everyFifteenMinutes())`; the scheduler
must be restarted after the add-on is enabled or disabled. `modules/ambresoft/cart-reminders` is a complete example.

### Settings panel

Requires contract 3. Implement `App\Shared\Application\Contract\AddonSettingsPanel` on your settings class to add
status lines and buttons above the form on your Settings page, for what a form cannot express. `settings()` may be empty,
and the form then disappears.

- `panelRows()`: read-only `AddonPanelRow(label, value)` lines, already translated.
- `panelActions()`: the `AddonPanelAction(key, label, confirm, destructive)` buttons on offer now. A request for any other
  action is refused.
- `runPanelAction($action)`: runs one. The CMS has already checked `addons.manage` and CSRF, and it audits the action.

Return an `AddonPanelSecret(label, hint, value)` to show a value once, such as a new credential. It reaches the browser only
in the action's response: never the session, a flash message, a page prop or the audit trail. Reloading loses it, so store a
hash if you need to check it later.

### Remote settings

Requires contract 3. For settings that belong to another system, such as a key for an outside service, implement
`App\Shared\Application\Contract\AddonRemoteSettings` on your settings class. Fields declared in `settings()` are stored by
the CMS, so a value that must not stay here needs this instead.

- `remoteForm()`: returns an `AddonRemoteForm(title, hint, choices, selected, rows)`, or null for no section. Each
  `AddonRemoteChoice(value, label, description, fields, values, guide)` is a card that reveals its own `SettingField`s. Read the
  current values from your own system. For a `secret` field, any value only means one is set: the browser never sees it.
- Give a choice an `icon`, a `data:image/` URI, to show a logo on its card. An `<img>` runs nothing, and the CMS drops any
  other kind of value.
- A page with a remote form is split into tabs, with the form first, so a long add-on stays short to navigate.
- `submitRemoteForm($choice, $values)`: receives one submission, after the CMS has checked `addons.manage`, CSRF and the field
  types. Hand it on and forget it. Return field errors keyed by field, or `form` for the form as a whole.

The CMS stores none of this. A submission answers JSON instead of redirecting, because a redirect with errors would flash the
values into the session, and the audit trail records only that it happened, and which choice. Give an `AddonPanelAction` the
`inForm: true` argument to show it beside the form, such as a connection test.

## Public catalog

Resolve `App\Storefront\Application\Contract\StorefrontCatalog` to read what an anonymous visitor may discover: product
search and details, the category tree, published pages, and storefront prices and availability. Results are plain data
objects. It takes no customer and returns guest prices only, and it is stricter than a product page: a product that is not
discoverable does not exist for it, even by ID. Never query catalog tables yourself. See the
[contract](architecture/storefront-catalog.md).

## Lifecycle

| Operation | What happens |
| --- | --- |
| Discover | Bundled packages are listed from `modules/`; nothing runs |
| Install | Manifest, CMS compatibility, files, migration names, namespaces, dependencies (installed at a satisfying version) and contributions are checked; owned migrations run in one PostgreSQL transaction with the installation record. Failure rolls everything back and removes uploaded files. Installed add-ons start **disabled** |
| Enable | Requires matching files/version, compatibility and enabled dependencies; the provider and contributions are registered immediately (a failure keeps it disabled); new capabilities are synchronized (granted to nobody) |
| Disable | Refused while an enabled add-on requires it. Its contributions stop being offered at once; data stays |
| Upgrade | Bundled: shipped with a CMS update and applied by `php artisan cms:addons-sync` (or the Upgrade button). Uploaded: upload a newer version with "replace" ticked; pending migrations run, the previous files are restored if anything fails. Configuration and data are kept |
| Uninstall | Only disabled add-ons without installed dependents. Removes the installation record (and an uploaded add-on's files). Tables, settings, payment records and migration history are **kept**, so historical orders stay readable and a reinstall reconnects |

## Package a ZIP

Put `composer.json` at the archive root (or inside one top-level folder). Limits: 20 MB compressed (configurable), 64 MB
uncompressed, 2,000 entries, 16 MB per file. Allowed: PHP, JSON, Markdown/text, JS/CSS/TS, images, fonts, CSV/YAML/XML/NEON,
`LICENSE`/`README`-style names. Refused: path traversal, absolute or backslash paths, hidden files/folders (except ignored
`.DS_Store`/`.gitignore`/`.gitattributes`/`.editorconfig`), symbolic links, encrypted entries, PHAR and other executable
archive types, duplicate paths (case-insensitive), suspicious compression ratios.

```sh
cd acme-bank-transfer && zip -r ../acme-bank-transfer-1.0.0.zip . -x '.*'
```

## Test

```sh
# Run the installer's checks without installing or executing anything:
docker compose exec -T app php artisan cms:addon-validate path/to/addon-or.zip
```

Integration tests can install the package through `App\Shared\Infrastructure\Modules\AddonManager::upload()` (or the
`/admin/addons/upload` endpoint) into a temporary `modules.addons_path`, enable it, and exercise the CMS with your
method registered — see `tests/Feature/AddonManagerTest.php` and `tests/Feature/StripeCheckoutTest.php` (provider API
faked, no network).

## Configuration and operations

`config/modules.php`: `bundled_path`, `addons_path` (`ADDONS_PATH`, default `storage/addons`; keep it persistent and outside
the web root), `uploads` (`ADDON_UPLOADS=false` disables code uploads entirely), `upload_max_kb` (`ADDON_UPLOAD_MAX_KB`).
Uploading requires the `addons.upload` capability; managing add-ons requires `addons.manage` (both super-admin only by
default). Long-running workers pick up enable/disable changes for new jobs only after a restart.

## Storefront widget contribution

Requires contract 3. An enabled provider may declare one widget with
`App\Storefront\Application\Service\StorefrontRegistry::widget($addonName, $src, $title)`. `$src` is an external `https`
document with no credentials, and `$title` is announced by screen readers.

```php
public function boot(StorefrontRegistry $storefront): void
{
    $storefront->widget('acme/chat', 'https://widgets.acme.test/frame?store=public-id', 'Shop assistant');
}
```

`$src` may hold `{locale}`, `{primary}` and `{on_primary}`, which the CMS fills for each visit with the storefront locale and
the store's colours, URL-encoded.

The CMS shows it in a fixed `<iframe>` with `sandbox="allow-scripts allow-same-origin allow-forms"` on the home, product,
category, content, search, collection, brand, sitemap, comparison and cart pages of the Default theme. Admin, sign-in,
account, checkout, payment and order tracking pages get no frame, and neither does a closed store.

**Why a frame, never a script.** A script from another host would run in the store's origin. From any page it could call
`fetch('/account')` with the visitor's session and read the answer, so no list of allowed pages could contain it, and it
would keep running after an Inertia visit. A cross-origin frame has its own origin, so it cannot read the storefront's pages,
cookies or account data. Removing the frame destroys its browsing context, so nothing of it keeps running.

- **Serve it from your own host.** A frame on the store's host is refused and logged: scripts plus `allow-same-origin` only
  sandbox a cross-origin document.
- **The address reaches every visitor's browser.** Never put a secret in it.
- **The frame may reload** when the visitor moves between allowed pages. Keep state in your own origin or on your server.
- **Protect your document.** Send `Content-Security-Policy: frame-ancestors` naming the stores allowed to embed it.
- The frame cannot navigate the store, open popups, or read the page. It knows only what its address tells it, and it can
  ask for two things with the [frame protocol](#frame-protocol).
- Enabling and disabling the add-on in **Add-ons** controls the widget, effective on the next visit.
- The CMS sends no Content-Security-Policy on these pages. A store that adds one must allow the document in `frame-src`.

[Decision A128](architecture/decisions.md).

### Frame pages

An enabled provider may also declare one full page, with
`StorefrontRegistry::framePage($addonName, $slug, $src, $title)`. The rules for `$src` and `$title` are the same, and the
frame has the same sandbox.

```php
$storefront->framePage('acme/chat', 'shop-assistant', 'https://widgets.acme.test/frame?locale={locale}', 'Shop assistant');
```

The page at `/shop-assistant` keeps the store's top panel, header and navigation, and the frame fills the rest of the window.
It has no footer, so it never scrolls, and it is sent with `noindex`. Categories and static pages keep their paths: a slug that
already means something never shows the frame. A closed store, or a disabled add-on, has no such page. While the page is
reachable, the store's header links to it beside the search, labelled with `$title`, which may be a translation key.

### Frame protocol

A widget or frame page can send its parent these messages with `postMessage`, and only these. Send them to the store's exact
origin, never `*`. The parent ignores anything else, and anything not from that frame's window and its address's origin.

```json
{ "protocol": "cms-widget/1", "type": "resize", "state": "open" }
{ "protocol": "cms-widget/1", "type": "navigate", "url": "https://shop.example/some-product" }
```

- `resize` has the state `closed` or `open`, for widgets. There are no dimensions: the CMS decides every size, from a small
  launcher to a panel, or the whole window on a phone. A transparent document shows the store through the closed frame.
- `navigate` goes to an absolute address on the store's own origin, at most 2000 characters, outside `/admin`. Anything else
  is dropped.
- Never send a token. The parent page has no need for one, and nothing else is allowed to read it there.

## Admin screens

Bundled add-ons that ship their own screen use the shared `UnderlineTabs` for tabs and the stacked `Field`, `CheckField` and
`SubSection` helpers from `resources/js/components/admin/form/fields.tsx`. That gives a label above a full-width control, a hint
below, and sections divided by rules, so every add-on looks like Settings → General ([A150](architecture/decisions.md)).

## Google Tag Manager slot

Core renders Google Tag Manager itself; no add-on script reaches the storefront. One enabled provider may call
`StorefrontRegistry::googleTagManager($addonName, fn (): ?array => [...])`, returning `container` (`GTM-…`), `dataLayer` and
`events` (storefront event → data layer event name), or null for none. The resolver runs per request. Core validates every
value, offers the container only on widget pages and turns in-app visits to other storefront pages into full page loads.
The Default theme announces `cms:storefront-event` on `window` with `type` (`add_to_cart`, `remove_from_cart`,
`add_to_wishlist`, `add_to_comparison`, `call_request`), `currency` and `items` (`id`, `name`, `sku`, `price`, `quantity`);
it never carries personal data. `modules/ambresoft/google-tag-manager` is the bundled implementation ([A147](architecture/decisions.md)).

## Google Analytics slot and placed orders

`StorefrontRegistry::googleAnalytics($addonName, fn (): ?array => ['measurementId' => 'G-…', 'ecommerce' => true])` works
like the Google Tag Manager slot: one owner, per-request resolver, validated values, public pages only. With ecommerce on,
the storefront keeps gtag's IDs in the `cms_ga` cookie (`clientId[:sessionId]`); read it with
`StorefrontTagManager::analyticsVisitor($request)`. Listen for `App\Order\Application\Event\OrderPlaced` (Laravel event,
dispatched once after a checkout order commits, never for admin orders) to report an order. It carries the order number,
currency, totals and lines as charged, and no personal data. A failing listener cannot affect the order. Queue slow work. `modules/ambresoft/google-analytics`
is the bundled implementation ([A148](architecture/decisions.md)).

## Cookie consent

One enabled provider may register a consent manager with `StorefrontRegistry::consentManager($addonName, fn (): ?array => [...])`.
The resolver returns the banner configuration (`version`, `months` and the manager's own texts, categories and scripts). Core sends
it to every storefront page, with scripts only on public pages, and makes core tags wait for consent. Other add-ons ask
`App\Storefront\Infrastructure\StorefrontConsent::granted($request, 'statistics')` on the server. In the storefront they use
`useConsent()` / `readConsent()` from `resources/js/storefront/consent.ts`, which answer for the current, unexpired choice.
Without a consent manager nothing is withheld, and consent is the merchant's responsibility. `modules/ambresoft/gdpr-cookies` is the
bundled implementation ([A149](architecture/decisions.md)).

## Storefront stylesheet contribution

Enabled providers may call `App\Storefront\Application\Service\StorefrontRegistry::stylesheet($addonName, $css)`
with their exact Composer name and trusted package CSS. One stylesheet per owner; duplicate/invalid owners throw.
Styles are emitted in provider registration order (dependency order, lexical tie-break). Core filters owners through
both loaded and enabled runtime state, excludes admin requests, and shares styles as an always-refreshed Inertia prop.
The Default storefront, customer authentication and closed-store pages render them as React text in style elements;
no raw HTML, remote assets or runtime JSX loading. Disabling removes the CSS on the next visit, including partial visits.
Already open pages update on their next navigation/reload. Providers only register styles; never modify saved colors or layouts.
A missing package stylesheet fails provider loading through the normal add-on failure handling. There is no transaction or
merchant data write in registration. This is a trusted CSS presentation slot, not a general frontend asset pipeline.

### Black Friday Theme

Bundled `ambresoft/black-friday-theme` uses this slot for a charcoal palette with gold accents. Install and enable
**Black Friday Theme** in **Add-ons**; disable it there to restore the saved storefront appearance. No customer switch,
scheduling, settings, migrations or dependencies. CSS changes colors only across the Default theme's headers, menus,
product views, cart, checkout, account pages and dialogs. Product images, swatches, stickers and authored banner
compositions retain their colors; merchant-supplied HTML styles remain authored content. Custom theme code may need
its own palette selectors. The addon does not change admin colors or write merchant theme/layout data.

A stylesheet owner may also call `StorefrontRegistry::documentPalette($addonName, 'dark', '#12151b')`.
The owner must already have a registered stylesheet; scheme is `light`/`dark` and background is a strict lowercase
six-digit hex color. The last loaded/enabled owner in registration order wins. The server applies this palette to
`html`/`body` in the initial HTML before JavaScript runs; storefront presentation keeps it synchronized through an
always-refreshed Inertia prop. Disabling clears the palette on the next visit. Admin requests receive no storefront
palette, and the browser's admin preference is independent.
