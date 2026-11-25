---
title: Add-on reference
parent: Developer guide
nav_order: 12
---

# Add-on reference
{: .no_toc }

The complete contract for add-on developers. For a walkthrough, start with [Creating an add-on](../creating-an-addon/).

1. TOC
{:toc}

---

## Contract versions

| Contract | Adds |
| --- | --- |
| `2` | The base contract: manifest, lifecycle, permissions, payment methods, shipping providers, search providers, settings, storefront stylesheet |
| `3` | Contract 2 plus the [public catalog](#public-catalog), [storefront widgets and frame pages](#storefront-widgets-and-frame-pages), the [settings panel and remote settings](#settings), `AddonStatus` and `uninstall-clears` |
| `4` | Contract 3 plus the read-only canonical URL discovery contract `StorefrontUrls` |

The platform accepts contracts **2, 3 and 4**. Declare the lowest contract that has everything your code uses: a
platform that does not know your contract refuses the add-on at installation instead of failing at runtime.

Separately, `extra.cms-module.cms` states which **platform add-on API versions** you support (Composer constraint
syntax). The current version, `1.0.0`, is shown on the **Add-ons** page.

## The manifest

`composer.json` in Composer format; the platform's metadata lives under `extra.cms-module`:

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
| `name` | Unique lowercase `vendor/name`; the folder must be `{vendor}/{name}`. Names of bundled add-ons are reserved |
| `version` | Semantic version `MAJOR.MINOR.PATCH[-pre]`; an upgrade must increase it |
| `description`, `authors[0].name` | Shown in the Add-ons list (at most 500 / 100 characters) |
| `autoload.psr-4` | Required: your own namespaces mapped to folders inside the package. `App\`, the platform's libraries and other add-ons' namespaces are refused |
| `extra.cms-module.contract` | `2`, `3` or `4` |
| `extra.cms-module.title` | Display name (at most 100 characters) |
| `extra.cms-module.icon` | Optional package-relative image (`svg`, `png`, `jpg`, `webp`, at most 256 KB), shown on a light tile. SVGs with scripts, event handlers or embedded HTML are refused |
| `extra.cms-module.cms` | Supported platform versions in Composer syntax, for example `^1.0`, `~1.2`, `>=1.0 <2.0` or `1.*`; alternatives can be joined with a double pipe (or) |
| `extra.cms-module.provider` | A Laravel `ServiceProvider` inside your namespace. Do not use `extra.laravel.providers` (refused) |
| `extra.cms-module.requires` | Other add-ons this one needs; each must also appear in `require` with a version constraint |
| `extra.cms-module.migrations` | Optional folder of owned migrations named `YYYY_MM_DD_HHMMSS_{vendor}_{name}_*.php` (non-alphanumerics become `_`) |
| `extra.cms-module.contributes` | Declarations for extension points; unknown points are refused |
| `extra.cms-module.uninstall-clears` | Optional list of your own tables (prefixed `{vendor}_{name}_`) to empty on uninstall, for data such as credentials that must not survive a reinstall |

## Boundaries

- Depend only on published contracts: `App\Payment\Application\Contract\*` and `App\Payment\Application\Command\*`,
  `App\Shipping\Application\Contract\*`, `App\Search\Application\Contract\*`, `App\Shared\Application\Contract\*`,
  `App\Shared\Application\Command\SettingField`, the public catalog, `App\Shared\Domain\Service\Decimal`, Laravel and
  your own code. Core controllers, Eloquent records and Infrastructure classes may change without notice.
- Keep your own business rules framework-free where practical (`src/Domain`), adapters in `src/Infrastructure`.
- Never compute stock, prices or taxes yourself; use the amounts the platform gives you. Money is a decimal string.
- Own your tables (prefixed with your vendor and name) and reference core rows through deliberate foreign keys.

## The service provider

Registered only while the add-on is enabled, after its dependencies.

- `register()`: container bindings.
- `boot()`: translations (`$this->loadTranslationsFrom(__DIR__.'/../../lang', 'acme-bank-transfer')`), routes, menu
  entries (`App\Admin\Navigation\AdminNavigation::add`), capabilities (`PermissionRegistry::add`, namespaced like
  `acme.bank-transfer.manage`) and storefront contributions.
- Never migrate, install or write store data from a provider.

## Payment methods

Declare `"payment-methods": { "id": "Class" }`. Identifiers are unique (`[a-z][a-z0-9_.-]{1,59}`; prefix them with your
vendor).

**`PaymentMethod`** (offline: staff record the payment):

| Method | Purpose |
| --- | --- |
| `id()`, `provider()` | Identifier and provider name |
| `defaultText($locale)` | Default customer-facing `name` and `description` |
| `settings()` | List of `SettingField` (labels are translation keys; `credential: true` marks fields the provider verifies) |
| `validate($values)` | `field => message` |
| `supports(PaymentAmount, $values)` | Currency, precision and amount rules (`"0"` when only listing) |

**`RedirectPaymentMethod`** (the customer pays at the provider) adds:

| Method | Purpose |
| --- | --- |
| `verify($values)` | Credential check; `null` means OK |
| `start(PaymentStart, $values)` | Returns `PaymentRedirect(providerReference, url)`. Use `PaymentStart::$reference` as the provider's idempotency key and custom reference |
| `complete(PaymentCheck, $values)` | Returns `PaymentResult`: capture **and verify** server-side when the customer returns |
| `refresh(PaymentCheck, $values)` | Re-reads an unfinished payment |

Throw `PaymentProviderUnavailable` for outages so the attempt stays retryable.

Optional capabilities for asynchronous providers:

- `HandlesPaymentWebhooks::webhook(PaymentWebhook, $values)` verifies the provider's signature over the raw body and
  returns a `PaymentWebhookEvent`, or throws `PaymentWebhookRejected`. The platform serves
  `POST /payments/webhooks/{method id}` without session or CSRF token, stores each event once and applies the result
  exactly once.
- `ExpiresProviderPayments::expire(PaymentCheck, $values)` ends an unfinished provider payment when the platform cancels
  or replaces an attempt (best effort).

The platform stores settings (secrets encrypted, never sent to the browser), renders the editor, enforces the enabled
state, verification and availability, records attempts and payments, prevents duplicates and applies the status after
payment. A method never writes payment or order data. Complete example: `modules/ambresoft/stripe-checkout`.

## Shipping providers

Declare `"shipping-providers": { "id": "Class" }`. The class implements
`App\Shipping\Application\Contract\ShippingProvider`: `id()`, `name()`, `settings()`, `accountFields()`,
`validateSettings($values)` and `validateAccount($values)`. Add only the capabilities the carrier really has:

| Interface | Methods |
| --- | --- |
| `QuotesRates` | `quote(RateRequest, CarrierAccount, $settings)` → `CarrierQuote::priced(net, tax, gross, currency, details)` or `CarrierQuote::unavailable(reason, message)`; never an estimate |
| `VerifiesAccounts` | `verify(CarrierAccount, $settings)`: a harmless read; throw on refusal |
| `CreatesShipments` | `shipmentFields()`, `shipmentDefaults(ShipmentDraft, $settings)`, `createShipment(ShipmentDraft, $input, CarrierAccount, $settings)` → `CreatedShipment` |
| `CancelsShipments` | `cancelShipment(ShipmentReference, CarrierAccount, $settings)` |
| `PrintsLabels` | `labelFormats()`, `label(ShipmentReference, CarrierAccount, $settings, $format)` → `ShippingDocument` (PDF) |
| `TracksShipments` | `track(ShipmentReference, CarrierAccount, $settings)` → `TrackingResult` (`created`, `in_transit`, `delivered`, `returning`, `returned`, `exception`, `unknown`) |
| `ManifestsShipments` | `manifestLabel()`, `issueManifest()`, `manifests()`, `manifestContents()`, `printManifest()` |
| `MapsOrderStatuses` | `orderStatusFor($event, $settings)` for `shipment_created`, `delivered`, `returned` |

Throw `ShippingProviderRejected(reason, message)` when the carrier refused (the message is shown to staff) and
`ShippingProviderUnavailable` when the outcome is unknown. Weights arrive in kg; amounts as exact decimal strings. The
platform stores settings and accounts, renders all forms, prevents duplicate shipments, applies order statuses once and
records everything.

## Search providers

Declare `"search-providers": { "id": "Class" }`. The class implements `App\Search\Application\Contract\SearchProvider`:

- `settings()` / `validateSettings()` for merchant settings, rendered and stored by the platform;
- `configure()`, `health()` and `prepare()`;
- `index()` / `remove()` with an optional rebuild target;
- `search(SearchQuery)` → `SearchResult` (ranked IDs, total, facet rows);
- `startRebuild()` / `count()` / `finishRebuild()` / `discardRebuild()`;
- `indexedIds()` and `cleanup()`.

The platform decides what is indexed (`SearchDocument`) and who may see what: every `SearchQuery` carries the searchable
categories, the visitor's customer groups and the products to hide, and every hit is re-read before it is shown. Throw
`SearchUnavailable` (`unavailable`, `not_initialized`, `rejected`) instead of returning an empty result. Connection
secrets belong in deployment configuration. Complete example: `modules/ambresoft/typesense` (see
[Search with Typesense](../typesense/)).

## Settings

Declare `"settings": "Vendor\\Addon\\Settings"`. The class implements `App\Shared\Application\Contract\AddonSettings`:
`settings()` (list of `SettingField`) and `validateSettings($values)`. The platform stores the values in
`addon_settings` (secrets encrypted), shows a **Settings** button on the enabled add-on to staff with `addons.manage`
and returns the values through `AddonSettingValues::values('vendor/name')`.

**`SettingField`** (`App\Shared\Application\Command\SettingField`):

```php
new SettingField(
    key: 'delay_hours',        // [a-z][a-z0-9_]{0,39}
    type: 'integer',           // text, textarea, secret, select, boolean, date, integer, decimal, order_status, payment_method
    label: 'vendor-name::ui.delay_hours',
    hint: '',
    required: true,
    options: [],               // select: value => label translation key
    default: '24',
    credential: false,
    maxLength: 255,            // 1–2000
    min: '1',
    max: '720',
);
```

**Settings panel** (contract 3): implement `AddonSettingsPanel` on the settings class to add read-only lines
(`panelRows()` → `AddonPanelRow`) and buttons (`panelActions()` → `AddonPanelAction(key, label, confirm, destructive)`,
run by `runPanelAction($action)` after the platform checked `addons.manage` and CSRF, and audited). Return an
`AddonPanelSecret(label, hint, value)` to show a value once, such as a new credential: it is never stored in the
session, a flash message, a page prop or the audit log.

**Remote settings** (contract 3): implement `AddonRemoteSettings` for settings that belong to another system.
`remoteForm()` returns an `AddonRemoteForm` of choices (`AddonRemoteChoice`), each with its own fields; the platform
stores none of it and passes each submission to `submitRemoteForm($choice, $values)` after checking `addons.manage`,
CSRF and field types. Submissions answer with JSON, so values are never flashed into the session.

## Other published contracts

| Contract | Purpose |
| --- | --- |
| `App\Storefront\Application\Contract\StorefrontCatalog` | The [public catalog](#public-catalog) |
| `App\Storefront\Application\Contract\StorefrontUrls` | Canonical, anonymously indexable storefront URLs (contract 4) |
| `App\Cart\Application\Contract\AbandonedCarts::idleSince($before, $afterId, $limit)` | Carts of active customers with an account that hold products and were last changed before a moment |
| `App\Shared\Application\Contract\StoreMailer::send($to, $subject, $html, $text, $locale)` | One email in the store's layout, from the store's sender, through the delivery method in Settings → General; returns `null` when sent |
| `App\Shared\Application\Contract\AddonStatus::isEnabled($name)` | Whether an add-on is enabled right now |
| `App\Order\Application\Event\OrderPlaced` | Laravel event dispatched once after a checkout order commits (never for admin orders): order number, currency, totals and lines, no personal data |

### Public catalog

`StorefrontCatalog` returns what an anonymous visitor may discover: product search and details, the category tree,
published pages, storefront prices and availability, as plain data objects with guest prices only. A product that is
not discoverable does not exist for it, even by ID. Never query catalog tables yourself.

## Storefront contributions

All registered from the provider's `boot()` through `App\Storefront\Application\Service\StorefrontRegistry`.

### Storefront stylesheet

`StorefrontRegistry::stylesheet($addonName, $css)`: one trusted CSS stylesheet per add-on, applied to storefront,
customer sign-in and closed-store pages (never the administration), and removed on the next visit when the add-on is
disabled. `documentPalette($addonName, 'dark', '#12151b')` additionally sets the page background before JavaScript
runs. Example: `modules/ambresoft/black-friday-theme`.

### Storefront widgets and frame pages

Contract 3. `StorefrontRegistry::widget($addonName, $src, $title)` shows **one** external `https` document in a fixed,
sandboxed `<iframe>` on the home, product, category, content, search, collection, brand, sitemap, comparison and cart
pages. Admin, sign-in, account, checkout, payment and order-tracking pages never get a frame.
`framePage($addonName, $slug, $src, $title)` adds one full page at `/{slug}` that keeps the store header.

```php
public function boot(StorefrontRegistry $storefront): void
{
    $storefront->widget('acme/chat', 'https://widgets.acme.test/frame?store=public-id', 'Shop assistant');
}
```

- `$src` may contain `{locale}`, `{primary}` and `{on_primary}`, filled per visit.
- Serve the document from **your own host** (a frame on the store's host is refused), never put a secret in its
  address, and protect it with `Content-Security-Policy: frame-ancestors`.
- Why a frame and never a script: a script from another host would run with the store's origin and could read the
  visitor's account pages; a cross-origin frame cannot.

The frame may send two `postMessage` messages to the store's exact origin, nothing else:

```json
{ "protocol": "cms-widget/1", "type": "resize", "state": "open" }
{ "protocol": "cms-widget/1", "type": "navigate", "url": "https://shop.example/some-product" }
```

### Google Tag Manager, Google Analytics and cookie consent slots

- `StorefrontRegistry::googleTagManager($addonName, fn (): ?array => [...])` returns the container (`GTM-…`), data layer
  and event names. Core renders the tag itself and validates every value. The storefront announces
  `cms:storefront-event` on `window` (`add_to_cart`, `remove_from_cart`, `add_to_wishlist`, `add_to_comparison`,
  `call_request`), never with personal data.
- `StorefrontRegistry::googleAnalytics($addonName, fn (): ?array => ['measurementId' => 'G-…', 'ecommerce' => true])`
  works the same way; listen to `OrderPlaced` to report purchases.
- `StorefrontRegistry::consentManager($addonName, fn (): ?array => [...])` registers the cookie banner; core tags wait
  for consent. Other add-ons ask `StorefrontConsent::granted($request, 'statistics')` on the server or `useConsent()` in
  the storefront.

Each slot has one owner. Bundled implementations: `google-tag-manager`, `google-analytics`, `gdpr-cookies`.

## Lifecycle

| Operation | What happens |
| --- | --- |
| Discover | Bundled packages are listed from `modules/`; nothing runs |
| Install | Manifest, platform compatibility, files, migration names, namespaces, dependencies and contributions are checked; owned migrations run in one PostgreSQL transaction with the installation record. A failure rolls everything back and removes uploaded files. Installed add-ons start **disabled** |
| Enable | Requires matching files and version, compatibility and enabled dependencies; the provider and contributions are registered immediately; new capabilities are synchronized (granted to nobody) |
| Disable | Refused while an enabled add-on requires it. Contributions stop at once; data stays |
| Upgrade | Bundled: applied by `php artisan cms:addons-sync` or the **Upgrade** button. Uploaded: upload the newer ZIP with *This is a newer version of an installed add-on: replace it* turned on; pending migrations run, and the previous files are restored if anything fails |
| Uninstall | Only for disabled add-ons without installed dependents. Removes the installation record (and an uploaded add-on's files). Tables, settings, payment records and migration history are **kept** |

Long-running workers and the scheduler pick up enable and disable changes only after a restart.

## Packaging

- `composer.json` at the archive root or inside one top-level folder.
- Limits: 20 MB compressed (`ADDON_UPLOAD_MAX_KB`), 64 MB uncompressed, 2,000 entries, 16 MB per file.
- Allowed: PHP, JSON, Markdown and text, JS/CSS/TS, images, fonts, CSV/YAML/XML/NEON, `LICENSE`/`README`-style names.
- Refused: path traversal, absolute or backslash paths, hidden files and folders (except ignored `.DS_Store`,
  `.gitignore`, `.gitattributes`, `.editorconfig`), symbolic links, encrypted entries, PHAR and other executable archive
  types, duplicate paths (case-insensitive) and suspicious compression ratios.

```sh
docker compose exec -T app php artisan cms:addon-validate path/to/addon-or.zip
```

## Configuration

`config/modules.php`:

| Key | Environment variable | Default |
| --- | --- | --- |
| `bundled_path` | — | `modules/` |
| `addons_path` | `ADDONS_PATH` | `storage/addons` (keep it persistent and outside the web root) |
| `uploads` | `ADDON_UPLOADS` | `true`; `false` disables code uploads entirely |
| `upload_max_kb` | `ADDON_UPLOAD_MAX_KB` | `20480` |

Uploading requires `addons.upload`; managing add-ons requires `addons.manage`. Both are Super Admin only by default.
