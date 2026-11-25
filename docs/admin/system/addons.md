---
title: Add-ons
parent: System
grand_parent: Administration guide
nav_order: 1
---

# Add-ons

**Add-ons** (`/admin/addons`)

Add-ons are optional extensions of the store. **Bundled** add-ons ship with the platform (Stripe Checkout, Typesense
search, Sitemap, product feeds, Google Analytics, Google Tag Manager, GDPR cookies, cart reminders, Black Friday theme);
other add-ons are uploaded as ZIP packages. See [Bundled add-ons](../../../bundled-addons/) for what each one does.

The page shows the platform's add-on version (**CMS version**) and, for each add-on, its version, author, status
(*Not installed*, *Disabled*, *Enabled*), what it requires and what it provides.

## Lifecycle

| Action | What happens |
| --- | --- |
| **Install** | The package is checked and its database tables are created. **Installing never enables an add-on** |
| **Enable** | The add-on starts working immediately; its new permissions are registered (granted to nobody) |
| **Settings** | Appears on enabled add-ons that have settings |
| **Disable** | The add-on stops working; its data and settings are kept. Refused while another enabled add-on requires it |
| **Upgrade** | Applies a newer bundled version shipped with a platform update. Settings and data are kept |
| **Uninstall** | Only for disabled add-ons that nothing depends on. Its data stays in the database (so old orders remain readable and a reinstall reconnects); an uploaded add-on's files are deleted |

{: .important }
> After enabling or disabling an add-on, ask the server operator to restart the background services
> (`docker compose restart worker scheduler`): they keep the old list of add-ons in memory until restarted.

## Uploading an add-on

Under **Upload an add-on**, drop or choose a ZIP file (up to 20 MB by default) and click **Upload and install**. To
upgrade an uploaded add-on, upload the newer version with **This is a newer version of an installed add-on: replace it**
turned on.

{: .warning }
> Add-ons run with full access to your store and its data. Install only add-ons from developers you trust. The server
> operator can turn uploads off entirely (`ADDON_UPLOADS=false`) so add-ons only arrive through deployment.

Developers: see [Creating an add-on](../../../developers/creating-an-addon/).

## Permissions

*Manage add-ons (install, enable, disable, upgrade, uninstall)* and *Upload add-on packages (runs third-party code)*,
both Super Admin only by default.
