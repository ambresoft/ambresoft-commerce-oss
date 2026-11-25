---
title: Languages and translations
parent: Settings
grand_parent: Administration guide
nav_order: 4
---

# Languages and translations

## Languages

**Settings → Languages** (`/admin/settings/languages`)

The platform ships in six languages: **English**, **Greek** (Ελληνικά), **Spanish**, **French**, **Italian** and
**Dutch**. Here you choose:

- the **Active languages**: the languages your store's content is written in and that staff and shoppers can pick;
- the **Default system language**: the main language. Required fields (for example a product's name) must be filled in
  this language, and it is used when a translation is missing.

At least one language stays active, and the default language must be active. **Deactivating a language keeps all its
content**; it comes back when you activate the language again.

{: .important }
> With **two or more active languages**, every translatable category field (name, page title, meta description, slug,
> image alternative text, meta keywords) becomes required in **every** active language when a category is saved.
> Products only require the name in the default language. Nothing is ever translated or copied automatically.

After activating a new language:

1. Fill in the new language in your categories, brands and other content (or import translations by CSV).
2. Rebuild the search index in **Settings → Search** so products can be found in the new language.

Each staff member chooses their own administration language in the top bar; this screen does not change it.

## Translations

**Settings → Translations** (`/admin/settings/languages/translations`)

Change the wording of the administration and storefront **interface** (buttons, labels, messages) for your store, for
example to say *Basket* instead of *Cart*.

1. Choose the language and search by key or text.
2. Edit the text. Keep every placeholder that starts with a colon (for example `:count`) exactly as in the original.
3. Save. Customized strings are marked *Customized*; **Restore original** brings back the shipped wording.

Changes affect only your installation and survive upgrades. Your own content (products, pages, banners…) is translated in
its editors, not here.

## Permission

*Manage system languages and UI translations* (`languages.manage`).
