# Shipping countries and regions

Status: implemented (accepted direction). The storefront checkout is not built yet; this phase prepares the data.

## Model

- `shipping_countries` holds the countries the store ships to, chosen on **Shipping & Taxes → Countries** from the ISO 3166-1
  allowlist in `config/geography.php`. Country names come from ICU in the admin's language; they are never stored.
- `regions` holds the subdivisions of a country (Greek *nomoi*): `country_code`, a unique `code` per country
  (1-10 letters/digits, for example `ATT`), `status` (active/disabled) and an optimistic `lock_version`.
- `region_translations` holds the merchant-visible name per active language (the default language is required).
- Regions survive a country being removed from the shipping list; they are only hidden until it is enabled again.
  A region can only be created for a country that currently ships.

## Boundaries

- Context: `app/Geography` (Application contracts, Infrastructure persistence). Admin controllers are presentation.
- `ShippingGeography` is the read port for checkout and shipping rules: shipping countries and the active regions of a
  shipping country, named in the requested language with fallback to the default language.
- `GeographyAdministration` is the write port; every write is authorized by capability (`countries.view|manage`,
  `regions.view|manage`) and recorded centrally (`shipping_country`, `region`, `region_translation`).
- Tax rate areas and customer addresses still validate against the country allowlist; they do not yet require a
  shipping country or a stored region. Linking them is a separate, explicitly authorized step.

## Greek nomoi

`php artisan cms:geography-greece <actor-id>` enables Greece and adds the 51 nomoi with Greek and English names
(codes such as `ATT`, `THS`). It is reference data, not demo data, so it also runs outside local environments. It is
idempotent and never overwrites edited regions.


## Region CSV import

Open **Imports / Exports → Import regions** at `/admin/data-transfer`, or the CSV icon on Regions. The workflow uses the existing CMS UI: download the current template, upload a UTF-8 CSV (comma or semicolon), review a bounded preview, explicitly confirm, then inspect success counts or a downloadable error report. Preview allocates no region IDs and changes no regions. Any validation error blocks the entire import; confirmation applies all rows in one transaction. No region export or storefront is added.

Canonical columns are `region_id`, `country_code`, `code`, `status` and `name.<locale>` for every **active** CMS language. The [small CSV example](../examples/region-import.csv) contains invented region data; the downloadable template follows enabled languages. Remove example rows before importing real data.

- A populated `region_id` selects that existing record, allowing code/name/status updates. IDs remain stable; a region cannot move countries.
- With no ID, `country_code` + `code` selects an existing region or creates it if absent. Country and region codes are trimmed and uppercased. Matching never uses translated names. Reimporting unchanged rows skips them without advancing their version.
- Countries must already be enabled for shipping. Import never enables countries, deletes regions, changes tax areas or rewrites addresses.
- New regions require a default-language name and default to **disabled** when status is blank or absent. Region codes and names follow the same server validation as manual editing.
- Omitted columns preserve existing values. Blank country/code/status cells preserve existing values on ID updates. A supplied empty non-default name removes that active-language translation; the default-language name cannot be cleared. Missing translations are never copied or translated. Inactive saved translations remain untouched, while inactive-language CSV columns are rejected.
- Duplicate target IDs/pairs, code collisions, unknown/system headers, missing IDs, invalid countries/statuses/names, invalid UTF-8/NUL/quotes, row-width errors and exceeded limits block confirmation.

Geography owns `RegionCsvImportInterface`, its planner and importer; the importer calls `GeographyAdministration` for actual mutations. Both this importer and category import use the same hardened streaming `Shared/Infrastructure/Import/CsvReader`; each context retains its own columns and semantics. The plan holds bounded operations for at most 10,000 rows, with only 30 preview rows and 200 error details sent to the browser. Upload limit is 20 MiB and logical-record limit is 256 KiB (`config/region-import.php`). No additional dependencies, schema migrations, database tables or permission definitions are needed.

All endpoints and direct import calls require active verified staff access and existing `regions.manage`; read-only region staff cannot import or inspect another user's upload. The Imports / Exports hub derives access from available transfer capabilities. On region-import routes the hub navigation entry is active. CSRF and existing preview/confirmation throttles apply. Private uploads and owner-bound previews expire after one hour; a cache lock prevents simultaneous confirmation and used/failed previews cannot run again. Error CSV cells are escaped against spreadsheet formula injection. Do not log CSV content or publish upload files.

Confirmation revalidates the file hash and the snapshot of region IDs/versions/codes/statuses/translations, enabled shipping countries and active/default languages. Geography writes now share the existing catalog/language transaction advisory lock **2142513**, coordinating region CRUD, imports and language changes. Manual region editing retains optimistic versions and row locks. A stale preview fails and requires a new upload/review instead of merging concurrent edits. Normal region/translation audit triggers capture actor and field differences; explicit `region.import_previewed`, `region.import_completed`, `region.import_failed`, `region.import_cancelled`, `region.template_downloaded` and `region.import_errors_read` events describe the import lifecycle. A rolled-back batch retains no success mutation/completion events; audit failures propagate.

The hourly `cms:region-import-prune` removes only expired temporary files with no live cached preview, after the same grace period as category imports. Run the scheduler in deployed environments. Manual cleanup: `docker compose exec -T app php artisan cms:region-import-prune`. Keep the app's existing upload/proxy/PHP limits consistent with the configured CSV size and measure synchronous imports before raising limits.

## Address regions (A100)

Customer and order addresses now choose their region from this catalog. `ShippingGeography::regionOptions()` returns the active regions of every shipping country in one query (named in the current language, default-language fallback, sorted by name) for the address forms; `regionNames()` resolves stored pairs for display whatever the region's status. Validation (`Customer\Infrastructure\AddressRegion`) accepts an empty region, a listed region of the address's country, or the address's own saved value while unchanged; a country with no active regions takes none. Disabling or deleting a region, or removing a country from shipping, therefore never rewrites addresses or orders, but new choices must come from the list. Seed the Greek nomoi with `php artisan cms:geography-greece <actor-id>` or import regions; addresses saved earlier keep their codes until edited.
