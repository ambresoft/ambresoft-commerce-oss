# Product CSV import

Products → Import products and Imports / Exports → Import products use `/admin/products/import`. The shared `CsvImportUpload`, progress steps and collapsed CSV instructions match category/region imports. Download a template, upload a UTF-8 CSV, review row errors and a bounded sample, explicitly confirm, then receive the completion report. Preview never changes products or downloads images. Failed confirmation rolls back the entire batch. Failed or completed tokens cannot be replayed; upload again to retry.

## Identity and fields

Every row requires `sku`. Matching is case-insensitive and uses the existing PostgreSQL partial unique expression index `products_sku_unique` on `lower(sku)` (`sku IS NOT NULL`). No duplicate index is added. Existing active, hidden and disabled products match; a SKU reserved by Trash is rejected, never recreated or restored. Duplicate SKUs in one CSV are rejected using PostgreSQL lower-case semantics. SKU renaming is outside this import contract.

New products require `sku,type,status,price,category_id,name.<default locale>`. Prices use the primary currency precision. The category must already exist, be active, be a leaf and permit products. Other scalar metadata is optional, including brand/identifiers, stock/order limits, shipping/packaging dimensions, release date and sitemap/Facebook settings. Translated columns are the Product editor's `ProductInput::TEXT` fields suffixed with an active locale code. Non-default language content is optional under the Product language policy; inactive or unknown columns are rejected. Required active-product features, digital delivery files and variation-group rules remain authoritative. This does not import downloadable files, feature definitions/values, variation-group definitions, promotions or gallery ordering.

Existing products can be updated with just the key and one column:

```csv
sku,price
EXISTING-SKU,19.90
```

Omitted columns and blank/whitespace-only cells preserve saved values, including translations. `0` and `false` are meaningful updates. `__CLEAR__` clears an optional text/number value; it cannot clear required fields. Boolean values accept `true/false` or `1/0`. `tax_ids` and `customer_groups` replace their assignments using IDs separated by `|`; `__CLEAR__` removes all assignments. Headers and field names are explicit allowlists. No arbitrary model attributes, foreign media IDs, timestamps or audit/version values are accepted from CSV.

## Images

`image_url` accepts an optional direct public HTTP(S) link to JPG, PNG or WebP. It replaces only the primary image, preserving other gallery images. `remove_image=true` removes only the primary image and cannot be combined with a URL. Blank means keep the gallery. Remote images use the existing `RemoteImageDownload`: DNS/IP validation, pinned public destinations, no private/internal targets, no redirects/credentials/fragments, standard ports, MIME/signature/dimension checks and bounded network budgets. Limits match category imports: 100 image rows, 5 MiB and 6000×6000 pixels per image, 100 MiB downloads and 120-second batch deadline. Repeated URLs download once per batch but become separately owned product media.

Downloads are staged outside database transactions. The planner then rechecks file hash, relevant product snapshots, category/localization/currency/feature state and permissions under the existing catalog advisory mutation lock. Confirmation invokes the same `ProductAdministration::draft/save` operations as the editor: it preserves audit/price history, image ownership, validation, stock rules, feature reconciliation and variation safeguards. Remaining business constraints are checked again at save; confirmation can fail even after a clean preview. New private assets are compensated after rollback, while old image deletion is deferred until commit. Cache invalidation uses existing catalog mutation triggers.

## Access and operations

`products.import` is a separate persisted capability, alongside `products.create` for new rows, `products.update` for saves and `products.status` for status transitions/activation. Files/tokens belong to the uploading actor; other staff cannot read or confirm them. Tokens expire in one hour. Confirmation/cancellation use an atomic per-token cache lock and explicit consumed states. Uploads are private. The hourly `cms:product-import-prune` command removes expired uploads after the safety margin; keep the Laravel scheduler running.

Limits are in `config/product-import.php`: 20 MiB, 10,000 logical CSV rows, bounded record size, 50 sample actions and 500 displayed issues. Any issue prevents confirmation. CSV error downloads escape spreadsheet formulas. Confirmation is synchronous like category imports; deployment request/proxy timeouts must allow the configured batch/image budget. Very large feeds should be split; no durable background import worker is introduced here.

Deployment requires no new database schema/index. Run `php artisan cms:permissions-sync`, assign `products.import` to intended staff and build frontend assets. Super administrators inherit access. Tests use `cms_test`; no live merchant records are imported during implementation.
