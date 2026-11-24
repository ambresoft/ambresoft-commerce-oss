# Website → Pages — administration only

Status: implemented for the admin and served on the storefront through a linked layout (see [Storefront](#storefront)). Page tabs, comments, form rendering and submissions, and e-mail delivery do not exist yet.

Behavior reference: the CS-Cart page editor (screenshots provided 2026-10-12). Schema, code and UI are our own.

## What a page holds

- General → Information: parent page (tree, top level by default), name per language (default language required), description (HTML with the page editor), position, status: **Active** (listed and reachable), **Hidden** (reachable by its address only), **Disabled** (not reachable).
- General → SEO / Meta data: page title, meta description, meta keywords per language.
- General → Availability: customer groups ("All" = empty selection), creation date, optional availability period (start/end date and time in the administrator's timezone, stored as UTC instants; scheduled/expired pages are not live).
- Additional → Facebook object type (Open Graph type, grouped list) and SEO name per language (`/privacy-policy`). An empty SEO name is generated from the name (Greek is transliterated) and made unique per language with a numeric suffix; an explicit duplicate is refused.

The admin list shows the page tree with SEO name, position and state, bulk Activate / Hide / Disable / Delete, add sub-page, edit and delete. The editor header has a searchable page switcher (instead of a permanent side list) to jump between pages.

## Form builder (Φόρμα tab)

Modelled on CS-Cart's form builder. A page can define one form:

- **Show a form on this page** (enabled), and its **position**: before the content, after the content, or at the `[form]` marker inside the default-language description (refused if the marker is missing; a button appends it).
- **E-mail subject**: the form name (page name), the value of one input / e-mail / select / radio field, or a custom per-language subject.
- **Form submission text** (per language, page HTML policy) shown after sending, and **E-mail to**: up to 10 validated addresses.
- **Fields**: position, per-language name, type (input, text area, select box, radio group, multiple checkboxes, checkbox, date, e-mail, number, phone, countries list, regions list, file, header, separator), required, active/disabled, delete. Choice types expand to options (position, per-language label, add, clone, delete) and need at least one option while active. Headers and separators are never required.
- An enabled form needs recipients and at least one active field that collects a value; a disabled form may be saved incomplete. Saving the page without the form part leaves the stored form untouched.

Tables (migration `2026_10_14_000400_create_page_forms.php`): `page_forms` (one per page; the subject field is a same-page composite foreign key), `page_form_translations`, `page_form_fields` (+ translations) and `page_form_variants` (+ translations). Field IDs are kept across edits; options are rewritten. Everything is audited.

Not included: rendering the form on the storefront, receiving and storing submissions, file upload handling and sending e-mail. Those depend on the storefront and the e-mail settings phase.

## Editor and HTML policy

`RichText` gained a `page` profile (the product/category `basic` profile is unchanged): paragraph and headings 1–4, bold, italic, underline, strikethrough, lists, quote, alignment, text colour, horizontal line, tables (insert, add/delete rows and columns), links (http(s), mailto, tel, /path, #anchor), images by address (https or /path), YouTube videos (youtube-nocookie embed), clear formatting, undo/redo and an HTML source mode.

`Website/Infrastructure/PageContentSanitizer` is authoritative on save: an element/attribute allowlist (Symfony HtmlSanitizer) followed by a CSS property allowlist (`text-align`, `color`, `background-color`, `width`, `min-width`), YouTube-only iframes, numeric dimensions and `rel="noopener noreferrer"` on new-window links. Scripts, styles, forms, event handlers, data URLs and other embeds are removed. The browser mirrors the policy (`sanitizePageContent`) for paste and source mode. Uploading images into page content waits for public storefront media.

## Persistence

Migration `2026_10_14_000300_create_pages.php`: `pages` (self-referencing parent with restricted deletion, position, status, availability, Open Graph type, `lock_version`), `page_translations` (name, sanitized content ≤ 500 000 characters, SEO name unique per language with a format CHECK, SEO fields) and `page_customer_groups`. Tree changes run under an advisory lock; cycles are refused by the application, self-parenting by a CHECK. A page with sub-pages cannot be deleted alone; a bulk delete including the whole branch deletes leaves first. Writes are audited (page HTML and long meta texts as redacted lengths).

SEO names are unique among pages only. Uniqueness across products, categories and pages belongs to the future storefront routing.

## Sample pages

`php artisan cms:pages-demo <actor-id>` (local/testing only) creates "Privacy policy", "Returns and exchanges" and "Contact us" in Greek and English with headings, lists, links, a table and right-aligned text. "Contact us" carries a ready form (first name, last name, phone, e-mail, topic select as the e-mail subject, message) shown after the content, sent to info@example.test once e-mail delivery exists. The texts are generic examples, not legal advice. Reruns create nothing and keep edits.

## Security

`pages.view` reads; `pages.manage` authorizes every write in the controller and the service. Run `cms:permissions-sync` and assign the capabilities through Roles.

## Storefront

A page is served at `/{SEO name}` (any of its languages' SEO names) once a merchant-added layout is linked to it
(Website → Layouts → layout settings → linked page). Without a linked layout it is not on the storefront.
`Storefront\Infrastructure\StorefrontPages` resolves it after category landing pages and before products:

- **Visitors** see an **active** or **hidden** page inside its availability period; a page limited to customer groups
  only shows to members. Disabled, scheduled or expired pages answer 404.
- **Staff preview**: the page editor shows **Preview** (new tab) once a layout is linked. It opens the page with
  `?preview=1`, which staff holding `pages.view` see whatever the page's state; previews are sent with `noindex`.
- The layout's **main content** block shows the page: trail, title (left out when the content opens with its own
  `<h1>`), the content (sanitized again on output with `PageContentSanitizer`) and the page's active FAQs. The
  page's SEO title, meta description and keywords win over the layout's; the canonical address is the SEO name.

The page form is not shown yet: receiving submissions and e-mail delivery come first.
