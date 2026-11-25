# Brand CSV import

Brands → Import brands and Imports / Exports → Import brands open `/admin/brands/import`. The shared CSV upload, settings, progress and collapsed instructions match category/product imports. Download the template, upload UTF-8 CSV, review the sample and row errors, then explicitly confirm. Preview never writes brands or downloads assets. Any error prevents confirmation; a failed confirmation rolls back the batch. Tokens belong to the uploading staff member, expire in one hour and cannot be replayed after confirmation.

## Fields and updates

`code` is the upsert key, normalized to lowercase and matched through the existing unique `brands.code` index. Duplicate codes in one file are rejected. The import cannot rename a code. New rows require `code,status,position` and `name.<locale>` for every active language, following the brand editor's completeness rules. Status is `active` or `disabled`; position is a nonnegative integer. Optional fields are `banner_link`, `description.<locale>`, `image_alt.<locale>` and `banner_alt.<locale>`. Inactive/unknown language columns are rejected.

For existing brands, include just the key and fields to change:

```csv
code,position
existing-brand,5
```

Omitted columns and blank cells preserve current values, including translations and assets. `__CLEAR__` clears optional text or the banner link; required names/status/position cannot be cleared. Scalar flags accept `true/false` or `1/0`. Arbitrary IDs, timestamps, media references and lock versions are not accepted from CSV.

## Images and attachments

- `image_url` replaces the logo. `banner_image_url` replaces the banner image. Images use the editor's JPG/PNG/WebP validation, with a 5 MiB / 6000×6000 pixel limit.
- `attachment_url` appends one document per row. `attachment_name` optionally supplies its filename, including extension; otherwise the URL path filename is used. The editor's private attachment storage and validation apply: PDF, ZIP, TXT, JPG/JPEG, PNG or WebP, at most 10 MiB with the default configuration, and at most 50 attachments per brand. MIME content must agree with the filename extension. Duplicate brand codes in the same file remain invalid; add further attachments in another import or the editor.
- `remove_image=true` or `remove_banner_image=true` removes the corresponding image and cannot be combined with its replacement URL. `remove_attachments=true` clears all brand documents; together with `attachment_url` it replaces the document set with that download. Blank asset cells preserve everything.

```csv
code,image_url,attachment_url,attachment_name
existing-brand,https://example.com/logo.png,https://example.com/download/manual,manual.pdf
```

URLs must be direct public HTTP(S) links, on standard ports, without credentials or fragments. Downloads share the existing image transport's public DNS/IP checks, pinned destinations, disabled proxies, TLS verification, no redirects, bounded streaming and timeouts. Each downloaded file is validated before saving. Downloads happen outside database transactions, only after confirmation. The batch allows 100 asset references, 100 MiB total downloads and a 120-second download budget by default. Repeated same-kind URLs are downloaded once, while each brand receives independently owned files.

Confirmation rechecks the upload hash, relevant brand/translation/attachment state, active locales and permissions under the catalog advisory mutation lock, then uses the same `Brands::save` operation as the editor. Audit, sanitization, optimistic locks, ownership, file cleanup and storefront cache invalidation remain authoritative. Newly stored assets are compensated if a later row fails; existing assets remain intact.

## Deployment and limits

Run `php artisan cms:permissions-sync` and build frontend assets. Intended staff require `brands.import` and `brands.manage` to apply rows; super administrators inherit access. No schema change or additional code index is required. Keep the scheduler running for hourly `cms:brand-import-prune` cleanup of expired private uploads.

`config/brand-import.php` sets the 20 MiB upload limit, 10,000 logical row limit, 256 KiB record limit, expiry and asset budgets. Preview samples show at most 50 actions and 500 issues. Error CSVs escape spreadsheet formulas. Confirmation is synchronous, like other imports; split large feeds and configure request/proxy timeouts for the selected limits. Coverage: `BrandImportTest`, shared `RemoteImageDownloadTest`, `BrandFilesTest` and `resources/js/tests/BrandImport.test.tsx`.
