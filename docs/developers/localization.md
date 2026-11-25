---
title: Localization
parent: Developer guide
nav_order: 9
---

# Localization

There are **two kinds of text**, and they are translated in completely different ways.

| | Interface text | Merchant content |
| --- | --- | --- |
| Examples | Button labels, menu items, validation messages, emails' fixed wording | Product names and descriptions, category slugs, page bodies, banner texts |
| Stored in | `lang/{locale}/{group}.php` files (keyed), plus database overrides | One translation table per entity with one row per language |
| Edited by | Developers; staff can override wording in **Settings → Translations** | Staff, in each editor's language selector |

## Languages

The shipped language pool is fixed: **English (`en`), Greek (`el`), Spanish (`es`), French (`fr`), Italian (`it`) and
Dutch (`nl`)**. `config/languages.php` is the allowlist; the `locales` table stores which languages are **active** and
which one is the **default**. Staff with `languages.manage` change these in **Settings → Languages**. At least one
language stays active and exactly one active language is the default. Deactivating a language keeps its content.

Adding a seventh language is a deliberate product change (a new dictionary and allowlist entry), not something a
merchant can do in the administration.

## Interface translations

- Every string is keyed: `__('taxes.saved')` in PHP and `t('taxes.saved')` in React (`useTranslation()`).
- Add new keys to `lang/en/{group}.php` **and** the five other language folders, keeping the same `:placeholders`.
- No hard-coded English in React and no separate frontend dictionary: the server sends the strings in the shared
  `i18n` prop.
- Staff overrides from **Settings → Translations** are stored in `ui_translation_overrides` and merged over the files
  by a loader decorator, for both `__()` and the React strings. The source files are never changed; *restore* removes
  the override.
- Add-ons load their own namespace (`$this->loadTranslationsFrom(…, 'vendor-name')`) and can expose a group to the
  React strings and the Translations screen with `UiTranslations::addGroup('vendor-name::ui')`.

**Which language a staff member sees:** their saved preference (if that language is active) → the store's default
language → English. Each staff member picks their own language from the admin header; it changes only their view.

**Which language a shopper sees:** the language they chose with the storefront language switcher (`/locale/{code}`,
kept in their session) if it is active; otherwise a signed-in user's saved language; otherwise the store's default
language.

## Merchant content translations

- Each translatable entity has its own translation table (for example `product_translations`,
  `category_translations`) with a unique (entity, locale) pair, never `name_en`/`name_el` columns or JSON.
- Slugs are unique **per language** and per resource type.
- Editors show one `ContentLanguageSelector` and keep every language's draft until you save.

**Completeness rules differ per entity, on purpose:**

- **Categories**: with two or more active languages, the name, SEO title, meta description, slug, image alt text and
  meta keywords are required in **every active language** in the editor.
- **Products**: the name in the default language is required; other languages are optional.
- **CSV imports** may contain any subset of the **active** languages (partial translations are allowed where the import
  documents it). Columns for an inactive language are rejected.

Nothing is ever copied automatically from one language to another.
