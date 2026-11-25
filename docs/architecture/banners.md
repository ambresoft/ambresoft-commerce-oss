# Marketing → Banners (advanced banners) — administration only

Status: implemented for the admin (accepted direction). No storefront, banner block, layout position or public media route exists yet.

The behavior reference is the public UniTheme2 documentation for its "Advanced banners" designer (docs.cs-cart.abt.team, reviewed 2026-10-12). We reproduce the documented feature set and admin fields; the schema, code, CSS class names and UI are our own and follow this CMS's design system. There is a single banner type, so no "Type" selector is shown.

## What an advanced banner holds

- General: names per active language (default language required), optional group, position, status, editable creation date.
- Block: colour scheme (light/dark; drives automatic text, button and mask colours), vertical and horizontal content alignment, internal paddings (1–4 values in px or %), content on full width (otherwise 50%).
- Title: per-language title with presets (above-title line, another colour, thin font, highlight, new row), font size, colour (automatic or custom), weight (thin/normal/bold/heavy), HTML tag (div, h1–h6), shadow.
- Description: per-language short text, font size, colour, background colour.
- Object inside: image (per language), YouTube video (per language; ID or URL, autoplay muted, hide controls) or products (grid / small items / thumbnails, columns, rows, up to 24 selected products), with vertical/horizontal object position.
- Background: image or MP4 video (per language), cover/contain, colour, vertical/horizontal position.
- Button: show, per-language text, style (standard/outline/text), text and button colours.
- Additional: mask (none, semi-transparent, blurred, smooth dimming, colour) with position, transparency, shading direction and colour; user CSS classes with seven ready-made presets; link (http(s), /path or #anchor) and same/new window; availability period; customer groups (empty = everyone).
- Tablet and phone: "Use own settings" adds a device tab. Every device-dependent field (block alignment, paddings, full width, title/description size, title shadow, background type/size/vertical position, button, mask, CSS classes) has an explicit Override switch; texts and images left empty use the PC value of the same language.

Groups only organise the admin list (create, rename, disband, bulk move). Clone copies everything, sharing the stored media, as a disabled banner whose names start with `[CLONE]`. Bulk actions: enable, disable, move to group, remove from group, delete.

A banner is *live* when active and inside its optional availability window (start and/or end, date and time; e.g. all of Black Friday). Before the start it is Scheduled, from the end on it is Expired, so it disappears without anyone switching it off; the stored status stays Active and no scheduled job is involved. The window is the authoritative rule that future storefront reads must apply (`available_from <= now < available_until`).

The editor shows and accepts the window in the administrator's own browser timezone (named under the fields) and sends ISO 8601 instants; the server stores them as UTC `timestamptz` and returns UTC instants. The application timezone (UTC) therefore never shifts a merchant's local window.

## Preview

The editor renders a live preview of the unsaved form for PC (1200×450), tablet (768×420) and phone (375×520) in the chosen content language, including chosen-but-unsaved files, title presets, mask, alignment and the CSS presets. Heights are fixed preview canvases: the final size will be decided by the storefront block that shows the banner. YouTube previews use the youtube-nocookie embed.

## Persistence

Migration `2026_10_14_000200_create_banners.php` (context `Marketing`):

- `banners`: device-independent settings, optimistic `lock_version`, availability window.
- `banner_translations`: names per locale.
- `banner_layouts`: one row per device; the PC row is complete (CHECK), tablet/phone columns are NULL when inherited. A device row without overrides is deleted.
- `banner_contents`: per device and locale: title, description, button text, YouTube ID (PC only, CHECK) and media references.
- `banner_products` (ordered), `banner_customer_groups`, `banner_groups` (unique case-insensitive name).
- `media_videos`: private MP4 files (content-sniffed `ftyp` box, at most 16 MiB). Images reuse `media_files` and `PrivateImageStorage` (PNG/JPEG/WebP, 5 MiB, 6000 px).

PostgreSQL enforces enumerations, colour format, padding grammar, CSS class grammar, required PC values and the availability order. Media references restrict deletion; `SharedMediaDeletion` knows banner references. Deleting a banner never deletes files (existing retention policy). The upload request limit is 22 MB, so very large combined uploads must be saved in steps.

## Security and audit

`banners.view` reads lists, editors and the owned media routes (`/admin/banners/{id}/media/{media}`, `/admin/banners/{id}/videos/{video}` with Range support); `banners.manage` authorizes every write in the service as well as the controller. Titles keep only the preset markup (server `BannerTitleSanitizer`, mirrored in the browser for the preview). URLs use the menu URL policy. Writes are captured by the central audit ledger (long texts and URLs redacted by the existing safe-value policy).

## Not included

Storefront rendering, banner blocks/templates (including "multiple banners in a row"), layout placement, per-device block visibility and scheduled automatic status changes.

## Sample hero banners

`php artisan cms:banners-demo <actor-id>` (local/testing only) creates the group "Homepage hero (sample)" with two hero banners in Greek and English: kraft paper bags (dark scheme, left content, gradient mask, phone overrides) and free shipping (light scheme, centred, coloured word). Backgrounds are generated gradients, so no third-party images are used. Reruns create nothing and keep edits.

## Enable

```sh
docker compose exec -T app php artisan migrate
docker compose exec -T app php artisan cms:permissions-sync
pnpm build
```

Assign `banners.view` / `banners.manage` to roles; super administrators already have them.
