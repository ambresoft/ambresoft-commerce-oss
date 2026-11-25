---
title: Frontend
parent: Developer guide
nav_order: 8
---

# Frontend
{: .no_toc }

1. TOC
{:toc}

---

## How pages are rendered

The frontend is **server-driven** with [Inertia 3](https://inertiajs.com): a Laravel controller decides what to show and
returns `Inertia::render('Admin/Taxes/Form', $props)`. Inertia loads the React component
`resources/js/pages/Admin/Taxes/Form.tsx` and passes the props. There is no separate API for the screens and no
client-side router of its own.

- **Entry point:** `resources/js/app.tsx` (`createInertiaApp`); pages are resolved from `resources/js/pages` by the
  `@inertiajs/vite` plugin.
- **Build:** Vite (`vite.config.ts`) with the React and Tailwind CSS 4 plugins. `pnpm build` writes `public/build`;
  `pnpm dev` serves live reload on port 5173.
- **Styling:** Tailwind CSS 4 from `resources/css/app.css`, which also defines the colour tokens (for example
  `--color-primary`).
- **Server-side rendering** is not used; storefront SEO tags are produced by the server and placed in the document head.

## Page trees

| Folder | Contents |
| --- | --- |
| `resources/js/pages/Admin/…` | One folder per admin area (Products, Orders, Settings…) |
| `resources/js/pages/Auth/…` | Sign-in, registration, password reset, verification, passkeys |
| `resources/js/pages/Customer/…` | Customer account pages |
| `resources/js/pages/Storefront/…` | Public storefront pages |
| `resources/js/pages/Error.tsx`, `StoreClosed.tsx` | Error page (403, 419, 429) and the closed-store page |
| `resources/js/storefront/themes/Default` | The bundled storefront theme: header, listings, product page, cart, checkout, account… |
| `resources/js/layouts/admin`, `layouts/auth` | Admin shell and authentication layouts |

Admin and storefront are **independent trees**. They share only suitable primitives and types.

## Shared props

Every Inertia response carries shared props from `App\Http\Middleware\HandleInertiaRequests`, including:

| Prop | Content |
| --- | --- |
| `auth` | The signed-in user (id, name, email, verified) and whether they may enter the administration |
| `capabilities` | A map of permission → `true`/`false` for the current user (presentation only) |
| `i18n` | Current locale, text direction, the translated interface strings and the active languages |
| `links` | Area-aware links (sign-in, account, dashboard…) |
| `authArea` | `admin` or `customer` |
| `flash` | Status messages |
| `preferences` | Date, time and calendar formats, timezone and other admin display preferences |
| `storeBrand` | Store logo and colours on customer-facing pages |

{: .important }
> `capabilities` only hides or shows controls. Every action is authorized again on the server.

Read translations with the `useTranslation()` hook from `resources/js/lib/useTranslation.ts`:

```tsx
const t = useTranslation();
return <h1>{t('taxes.title')}</h1>;
```

## The admin design system

Admin screens must reuse the shared components in `resources/js/components/admin/` instead of page-specific markup:

| Component | Use |
| --- | --- |
| `EditorHeader` | The header of every list, detail, editor, settings, report and add-on screen: icon or thumbnail, title, metadata and right-aligned actions. **New/Create** actions go in its `actions` slot with a plus icon; **Delete/Trash** goes in its three-dot `menu` slot |
| `EditorStatusSelect` | The single editable status pill in the header (Products, Categories and Brands are the reference) |
| `AdminCard` | Every form and detail section: divided heading, green icon tile, action slot and padding |
| `AdminTabs` / `AdminTab` | Tabbed editors, with a compact sticky bar; `UnderlineTabs` delegates to them |
| `ContentLanguageSelector` | The one language switcher of a translatable editor, in the tab bar's `language` slot |
| `EditorActions`, `EditorActionButton` | Save, Duplicate, Preview |
| `ConfirmDialog`, `ConfirmDeleteDialog`, `FeatureImpactDialog` | Confirmations; never `window.confirm` |
| `HelpPopover` | Status help and explanations |
| `CsvImportUpload` (`CsvImportUpload`, `CsvImportSteps`, `CsvImportInstructions`) | Every CSV import screen (Categories and Regions are the reference) |
| `RichText` | Every rich-text field (Tiptap with Visual/HTML modes); the server sanitizes again |
| `UrlSlugInput` | SEO name fields with their fixed URL prefix |
| `CountrySelect`, `Select`, `Switch`, `DateInput` | Form controls |
| `form/fields.tsx` (`Field`, `CheckField`, `SubSection`) | Stacked settings-style fields |
| `list/` (`FilterBar`, `BulkBar`, `StatusBadge`, `DragGrip`) | List screens |

Conventions:

- Back navigation is the shared small link below the breadcrumbs, always labelled with `common.back`.
- A row's name links to the same destination as its Edit action, with the same permission guard.
- Mark required fields with `RequiredMark` and `aria-required`.
- Editors keep unsaved values and every language's draft when switching tabs or languages.

## The storefront

The storefront is organized as **layouts → 16-column grid → blocks**: each page type (home, category, product, cart,
checkout, search…) has a layout made of grids, and each block fills its grid cell. Merchants edit layouts in
**Website → Layouts**. The bundled theme lives in `resources/js/storefront/themes/Default`.

- Banner compositions use one shared renderer for the admin preview and the storefront, so backgrounds, texts, masks,
  buttons and device overrides look identical.
- Shoppers' events are announced on `window` as `cms:storefront-event` for analytics add-ons.
- Add-ons cannot inject scripts. They contribute through typed slots: stylesheets, sandboxed frames and analytics
  configuration rendered by core (see the [Add-on reference](../addon-reference/#storefront-contributions)).
- Merchants can add their own CSS and JavaScript in **Website → Code editor** (trusted code, its own permission).

## Accessibility

Use semantic HTML and the shared components, which already provide keyboard navigation, focus handling and ARIA roles
(tabs, comboboxes, dialogs). Provide translated accessible labels for icon buttons and status pills.
