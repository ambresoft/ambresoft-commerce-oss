---
title: Imports / Exports
parent: System
grand_parent: Administration guide
nav_order: 4
---

# Imports / Exports
{: .no_toc }

**Imports / Exports** (`/admin/data-transfer`)

Move store data in and out with CSV files. Every import is uploaded, checked and **previewed** before you confirm any
change.

1. TOC
{:toc}

---

## What you can import and export

| Data | Import | Export | Key that identifies a row |
| --- | --- | --- | --- |
| Products | Yes | Yes | `sku` |
| Categories | Yes | Yes | `category_id` (empty creates a new category) |
| Brands | Yes | Yes | `code` |
| Regions | Yes | Yes | `region_id`, or `country_code` + `code` |

Exports use the same column names as the imports, so a file can be exported, edited in a spreadsheet and imported back.

## Importing step by step

1. Open the import (from **Imports / Exports**, or the CSV icon on the Products, Categories, Brands or Regions screen).
2. **Download the template**. It always matches your current languages. Remove its example row before importing real
   data.
3. Fill in the file and save it as **CSV, UTF-8** (comma or semicolon separated).
4. Drop the file on the upload area, check the settings (delimiter and so on) and click **Upload & preview**. Nothing
   changes yet.
5. Review the summary, the sample of planned changes and any **errors**. Any error blocks the whole import; download
   the error report, fix the file and upload again.
6. Tick the confirmation and **Import**. All rows are applied together; if anything fails, nothing is changed.

Uploads are private to the staff member who uploaded them and expire after one hour.

## Rules common to all imports

- **Only the columns you include are changed.** To update prices only, a file with `sku,price` is enough.
- An **empty cell** keeps the saved value (for products, `__CLEAR__` clears an optional value).
- Translated columns end with a language code: `name.en`, `name.el`, `description.fr`… Columns for **inactive**
  languages are rejected. Nothing is copied from one language to another.
- Imports never delete records.

## Products

- Every row needs `sku` (matched without regard to upper/lower case). A SKU of a product in the Trash is rejected.
- **New products** need at least `sku,type,status,price,category_id,name.<default language>`. The category must
  exist, be active, be a leaf and allow products.
- Prices use the primary currency's decimals. Yes/no values accept `true`/`false` or `1`/`0`.
- `tax_ids` and `customer_groups` replace the assignments with IDs separated by `|`; `__CLEAR__` removes all.
- `image_url` replaces only the **primary image** with a public JPG, PNG or WebP picture downloaded from that address
  (up to 100 image rows per file, 5 MB each); `remove_image=true` removes the primary image.
- Feature values, downloadable files, variation groups and promotions are not imported.
- Limits: 20 MB, 10,000 rows per file. Split larger catalogs.

```csv
sku,price
EXISTING-SKU,19.90
```

## Categories

- Fill `category_id` to update an existing category; leave it empty to create one.
- `category_path` is the full path in the **default language**, from the root to the category itself, with `///`
  between levels by default, for example `Phones///Smartphones`. Missing parents are created **disabled**.
- Rows are processed in file order: put parent rows before their children.
- `customer_groups` accepts group IDs, or `all` to remove a restriction. `image_url` and `remove_image` manage the
  category image.

## Brands

- `code` identifies a brand (lowercase). The import cannot rename a code.
- **New brands** need `code,status,position` and `name.<language>` for **every active language**.
- Optional: `banner_link`, `description.<language>`, `image_alt.<language>`, `banner_alt.<language>`.

## Regions

- `region_id` updates that region; without it, `country_code` + `code` finds the region or creates it.
- The country must already be enabled for shipping. New regions need a default-language name and start **disabled**
  when no status is given.

## Exporting

1. Open the export of the data you want.
2. Choose the **fields** (select all or some) and the **delimiter**.
3. **Download CSV**. The export respects your current list **filters** and your **selected rows** when you start it
   from a list.

Spreadsheet formulas in exported values are neutralized for safety.

## Permissions

*Import products by CSV*, *Import categories from CSV*, *Import brands by CSV*, and *Manage regions* for regions, each
together with the normal create/update permissions of that data. An export needs the same permission as the matching
import. The Imports / Exports menu entry appears for staff who can run at least one of them.
