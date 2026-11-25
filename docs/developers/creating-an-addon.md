---
title: Creating an add-on
parent: Developer guide
nav_order: 11
---

# Creating an add-on
{: .no_toc }

This tutorial builds a small add-on from scratch, **Bank transfer**: an offline payment method with an account holder
and an IBAN that customers see at checkout. It is the same package as the example in the repository,
[`docs/examples/addons/acme-bank-transfer`](https://github.com/ambresoft/ambresoft-commerce-oss/tree/master/docs/examples/addons/acme-bank-transfer).
Then it shows how to add settings, permissions, routes, an admin menu entry, migrations and scheduled work.

1. TOC
{:toc}

---

## How add-ons work

- An add-on is a folder named `{vendor}/{name}` with a Composer-style `composer.json` **manifest**, PHP code in **your own
  namespace**, and optional translations and migrations.
- **Bundled** add-ons ship with the platform in `modules/{vendor}/{name}`. **Uploaded** add-ons are ZIP files uploaded in
  **Add-ons** and extracted to `storage/addons/{vendor}/{name}`. Both use the same manifest, lifecycle and contracts.
- An add-on's service provider runs **only while the add-on is enabled**.
- Add-ons extend the platform through **published contracts** and **extension points**. They never change core files
  and never call core controllers, Eloquent records or Infrastructure classes.

{: .warning }
> Add-on code runs with the application's full privileges. The installer validates packages but does not sandbox them.
> Install only add-ons from developers you trust.

## Step 1: create the folder

```text
acme-bank-transfer/
├── composer.json
├── lang/
│   └── en/
│       └── bank.php
└── src/
    └── Infrastructure/
        ├── BankTransferServiceProvider.php
        └── BankTransferPaymentMethod.php
```

## Step 2: write the manifest

`composer.json`:

```json
{
    "name": "acme/bank-transfer",
    "description": "Example third-party add-on: an offline bank transfer payment method.",
    "version": "1.0.0",
    "type": "cms-addon",
    "license": "proprietary",
    "authors": [{ "name": "Acme" }],
    "autoload": {
        "psr-4": { "Acme\\BankTransfer\\": "src/" }
    },
    "extra": {
        "cms-module": {
            "contract": 2,
            "title": "Bank transfer",
            "cms": "^1.0",
            "provider": "Acme\\BankTransfer\\Infrastructure\\BankTransferServiceProvider",
            "requires": [],
            "contributes": {
                "payment-methods": {
                    "acme.bank-transfer": "Acme\\BankTransfer\\Infrastructure\\BankTransferPaymentMethod"
                }
            }
        }
    }
}
```

The important parts:

| Key | Meaning |
| --- | --- |
| `name` | `vendor/name` in lowercase. The folder must be called the same |
| `version` | Semantic version; each upgrade must increase it |
| `autoload.psr-4` | Your own namespace. The `App\` namespace, the platform's libraries and other add-ons' namespaces are refused |
| `extra.cms-module.contract` | The add-on API version your code needs: `2`, `3` or `4` (see [contracts](../addon-reference/#contract-versions)) |
| `extra.cms-module.cms` | Which platform versions you support, in Composer syntax. The add-on API version is shown on the **Add-ons** page (currently `1.0.0`) |
| `extra.cms-module.provider` | Your Laravel service provider, inside your namespace |
| `extra.cms-module.contributes` | What you plug into: here one payment method with the identifier `acme.bank-transfer` |

The full list of keys is in the [Add-on reference](../addon-reference/#the-manifest).

## Step 3: the service provider

`src/Infrastructure/BankTransferServiceProvider.php`:

```php
<?php

namespace Acme\BankTransfer\Infrastructure;

use Illuminate\Support\ServiceProvider;

/** Registered only while the add-on is enabled; it only loads the add-on's own translations. */
final class BankTransferServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'acme-bank-transfer');
    }
}
```

Use `register()` for container bindings and `boot()` for translations, routes, menu entries and permissions. A provider
must **never** migrate, install or write store data.

## Step 4: the payment method

`src/Infrastructure/BankTransferPaymentMethod.php` implements the published `PaymentMethod` contract:

```php
<?php

namespace Acme\BankTransfer\Infrastructure;

use App\Payment\Application\Command\PaymentAmount;
use App\Payment\Application\Command\SettingField;
use App\Payment\Application\Contract\PaymentMethod;

final class BankTransferPaymentMethod implements PaymentMethod
{
    public function id(): string
    {
        return 'acme.bank-transfer';
    }

    public function provider(): string
    {
        return 'Acme';
    }

    public function defaultText(string $locale): array
    {
        return ['name' => __('acme-bank-transfer::bank.name', [], $locale), 'description' => __('acme-bank-transfer::bank.description', [], $locale)];
    }

    public function settings(): array
    {
        return [
            new SettingField('account_holder', 'text', 'acme-bank-transfer::bank.account_holder', required: true, maxLength: 120),
            new SettingField('iban', 'text', 'acme-bank-transfer::bank.iban', 'acme-bank-transfer::bank.iban_hint', true, maxLength: 34),
        ];
    }

    public function validate(array $values): array
    {
        $iban = strtoupper(str_replace(' ', '', $values['iban'] ?? ''));

        return $iban !== '' && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/D', $iban) !== 1 ? ['iban' => __('acme-bank-transfer::bank.iban_invalid')] : [];
    }

    public function supports(PaymentAmount $amount, array $values): bool
    {
        return true;
    }
}
```

| Method | Purpose |
| --- | --- |
| `id()` | The identifier declared in the manifest: `[a-z][a-z0-9_.-]{1,59}`, prefixed with your vendor |
| `provider()` | Who provides the method, shown to staff |
| `defaultText($locale)` | The default customer-facing name and description in a language. Staff can change them per language |
| `settings()` | The fields staff fill in. Labels are translation keys. Types: `text`, `textarea`, `secret`, `select`, `boolean`, `date`, `integer`, `decimal`, `order_status`, `payment_method` |
| `validate($values)` | Rules across fields: return `field => message` for each problem |
| `supports($amount, $values)` | Whether the method can take this amount and currency (the amount is `"0"` when only listing) |

The platform does the rest: it stores the settings (a `secret` field is encrypted and never sent to the browser),
renders the editor under **Payment methods**, enforces the enabled state, records payment attempts, prevents duplicates
and applies the order status after payment. **Your method never writes payment or order data.**

{: .note }
> A method where the customer pays at an external provider implements `RedirectPaymentMethod` instead, with `verify()`,
> `start()`, `complete()` and `refresh()`, and optionally webhooks. The bundled **Stripe Checkout** add-on in
> `modules/ambresoft/stripe-checkout` is a complete example.

## Step 5: translations

`lang/en/bank.php`:

```php
<?php

return [
    'name' => 'Bank transfer',
    'description' => 'Transfer the order total to our bank account; we ship once it arrives.',
    'account_holder' => 'Account holder',
    'iban' => 'IBAN',
    'iban_hint' => 'Shown to customers who choose bank transfer.',
    'iban_invalid' => 'Enter a valid IBAN.',
];
```

Add the same file in `lang/el`, `lang/es` and so on for every language you support. Keys are used as
`acme-bank-transfer::bank.iban`.

## Step 6: validate the package

Run the installer's checks without installing or executing anything:

```sh
docker compose exec -T app php artisan cms:addon-validate docs/examples/addons/acme-bank-transfer
```

The path is read inside the container, so use a path inside the project folder (or a ZIP file there).

## Step 7: package it as a ZIP

`composer.json` must be at the root of the archive (or inside a single top-level folder):

```sh
cd acme-bank-transfer && zip -r ../acme-bank-transfer-1.0.0.zip . -x '.*'
```

Limits: 20 MB compressed (configurable with `ADDON_UPLOAD_MAX_KB`), 64 MB uncompressed, 2,000 files, 16 MB per file.
Hidden files, symbolic links, path traversal, encrypted entries and PHAR archives are refused. See the full
[packaging rules](../addon-reference/#packaging).

## Step 8: install and enable

1. Sign in to the administration as a user with the **Upload add-on packages** (`addons.upload`) and **Manage add-ons**
   (`addons.manage`) permissions. Both belong to Super Admins by default.
2. Open **Add-ons**, choose the ZIP under **Upload an add-on** and click **Upload and install**. The package is
   validated and installed; installed add-ons start **disabled**.
3. Click **Enable** on your add-on. Your provider starts running and your payment method is registered.
4. Open **Payment methods**. *Bank transfer* is listed and starts disabled: open it, fill in the account holder and
   IBAN, check the name and description in each language, and enable it.
5. Restart the background services so long-running processes load the add-on: `docker compose restart worker scheduler`.

Uploading can be switched off entirely with `ADDON_UPLOADS=false`, for stores where add-on code may only arrive
through deployment.

## Going further

### Store your own settings

Declare a settings class in the manifest:

```json
"contributes": { "settings": "Acme\\Reminders\\Infrastructure\\ReminderSettings" }
```

and implement `App\Shared\Application\Contract\AddonSettings`:

```php
use App\Shared\Application\Command\SettingField;
use App\Shared\Application\Contract\AddonSettings;

final class ReminderSettings implements AddonSettings
{
    public function settings(): array
    {
        return [
            new SettingField('delay_hours', 'integer', 'acme-reminders::ui.delay_hours', required: true, default: '24', min: '1', max: '720'),
            new SettingField('api_key', 'secret', 'acme-reminders::ui.api_key'),
        ];
    }

    public function validateSettings(array $values): array
    {
        return []; // field => message for rules across fields
    }
}
```

The platform adds a **Settings** button to the enabled add-on in **Add-ons** (for staff with `addons.manage`), stores
the values (secrets encrypted) and returns them to your code through
`App\Shared\Application\Contract\AddonSettingValues::values('acme/reminders')`.

With contract 3 you can add status lines and buttons above the form (`AddonSettingsPanel`) and forms whose values belong
to an outside system (`AddonRemoteSettings`). See the [reference](../addon-reference/#settings).

### Add permissions

Register namespaced capabilities in `boot()`. A declaration never grants anything: staff receive it through
**Users → Roles**.

```php
use App\Authorization\Infrastructure\PermissionRegistry;

$this->app->make(PermissionRegistry::class)->add('acme.reminders.manage', 'acme-reminders::ui.permission_manage');
```

Then run `php artisan cms:permissions-sync` (the platform also synchronizes new capabilities when the add-on is
enabled).

### Add routes

Register routes in `boot()` and protect admin routes exactly like core does. This is how the bundled Sitemap add-on
does it:

```php
Route::prefix('admin/sitemap')
    ->middleware(['web', 'auth', 'auth.session', 'verified', 'can:access-admin', ActiveSitemap::class, 'can:ambresoft.sitemap.view'])
    ->name('module.ambresoft.sitemap.admin.')
    ->group(function (): void {
        Route::get('/', [SitemapController::class, 'index'])->name('index');
        Route::post('/generate', [SitemapController::class, 'generate'])->middleware('can:ambresoft.sitemap.manage')->name('generate');
    });
```

- Name admin routes `module.{vendor}.{name}.admin.…`.
- `ActiveSitemap` is the add-on's own middleware that refuses requests while the add-on is disabled. A provider can
  outlive a disable in a long-running process, so check `App\Shared\Application\Contract\AddonStatus::isEnabled()`
  in your routes.
- Public routes that must never be claimed by SEO redirects belong under the reserved `/storefront/…` prefix.

### Add a menu entry

```php
use App\Admin\Navigation\AdminNavigation;

$this->app->make(AdminNavigation::class)->add(
    'ambresoft.sitemap',                       // unique namespaced ID
    'cms-sitemap::ui.title',                   // label (translation key)
    'module.ambresoft.sitemap.admin.index',    // an admin route name
    'ambresoft.sitemap.view',                  // the capability that shows the entry
    64,                                        // position
    'core.website',                            // menu group (here: Website)
);
```

Core groups you can use: `core.orders`, `core.catalog`, `core.catalog-products`, `core.customers`, `core.access`,
`core.reports`, `core.marketing`, `core.newsletter-menu`, `core.blog-menu`, `core.website`, `core.shipping-taxes`,
`core.logs` and `core.settings`.

{: .important }
> Uploaded add-ons cannot ship React pages: the platform never loads JSX at runtime. Their user interface is what the
> platform renders for them (settings forms, the settings panel, payment and shipping editors). **Bundled** add-ons can
> have their own screen through a trusted build-time bridge file in `resources/js/pages/Admin/Addons/`, as Sitemap,
> Product feeds and GDPR Cookies do; such screens reuse the shared admin form components.

### Own database tables

Declare a migrations folder in the manifest (`"migrations": "database/migrations"`) and name each migration
`YYYY_MM_DD_HHMMSS_{vendor}_{name}_*.php`, for example
`2026_01_01_000000_acme_bank_transfer_create_references.php`. Prefix your tables with your vendor and name. Migrations
run in one transaction together with the installation record; if anything fails, everything is rolled back.

On uninstall, tables and data are **kept** (so old orders stay readable and a reinstall reconnects). List tables that
must be emptied, such as credentials, under `uninstall-clears`.

### Run on a schedule

```php
use Illuminate\Console\Scheduling\Schedule;

$this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
    $schedule->call(fn () => $this->app->make(Reminders::class)->sendDue())->everyFifteenMinutes()->name('acme-reminders')->withoutOverlapping();
});
```

Restart the scheduler after enabling or disabling the add-on. `modules/ambresoft/cart-reminders` is a complete example.

### Use platform services

| Contract | Gives you |
| --- | --- |
| `App\Storefront\Application\Contract\StorefrontCatalog` | What an anonymous visitor may see: product search and details, the category tree, published pages, guest prices and availability |
| `App\Storefront\Application\Contract\StorefrontUrls` (contract 4) | Canonical, indexable storefront URLs |
| `App\Cart\Application\Contract\AbandonedCarts` | Idle carts of signed-up customers |
| `App\Shared\Application\Contract\StoreMailer` | Send an email in the store's layout from the store's sender |
| `App\Shared\Application\Contract\AddonStatus` | Whether an add-on is enabled right now |
| `App\Shared\Domain\Service\Decimal` | Exact decimal arithmetic for money |

Never compute prices, stock or taxes yourself and never query catalog tables directly: use the amounts and data the
platform gives you.

## Testing your add-on

Integration tests can install the package into a temporary add-ons path, enable it and exercise the platform with your
code registered. See `tests/Feature/AddonManagerTest.php` and `tests/Feature/StripeCheckoutTest.php` (the Stripe API is
faked; no network).
