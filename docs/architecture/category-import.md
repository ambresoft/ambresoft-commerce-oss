# Category CSV import — Phase 3A

This extends Categories only. No Products, generic import framework, export, queues or new database tables. Catalog owns an Application import port/handler and focused Infrastructure reader, field definition, metadata simulation, planner and transactional adapter. Admin supplies browser routes and the existing Inertia shell. Confirmation invokes the same ManageCategoriesHandler/SaveCategoryData and persistence adapter as the category editor.

## Field audit and canonical format

The audit inspected the actual Category/CategoryTranslation records, additive age migration, Form/CategoryInput, private media and category service—not the original Phase 3 proposal. CategoryImportFields is the single executable column definition, reused by header validation, mapping, the browser help and template. A regression test compares its translated fields with the actual translation record's editable fields, so adding a field requires reviewing import support.

| Current merchant field | CSV representation | Classification |
| --- | --- | --- |
| Parent category | category_path, including the current category as its last segment | Importable relationship, no raw parent_id |
| Position | position | Importable positive sibling integer |
| Status | status | Importable active / disabled |
| Own age verification | age_verification | Importable true / false / 1 / 0 |
| Own age limit | age_limit | Importable positive integer years or empty |
| Exclude from search and highlights | exclude_from_discovery | Importable true / false / 1 / 0; empty preserves |
| Customer group visibility | customer_groups | Importable existing group IDs separated by commas, semicolons, pipes or spaces; `all` removes the restriction; empty preserves. Unknown IDs fail; a disabled group can be kept but not newly added |
| Name | name.{locale} | Importable translated text |
| Description | description.{locale} | Importable translated plain text |
| Page title | seo_title.{locale} | Importable existing field, not meta_title |
| Meta description | seo_description.{locale} | Importable existing field, not a parallel meta_description |
| SEO name | slug.{locale} | Importable existing localized slug |
| Image alternative text | image_alt.{locale} | Importable translated text |
| Meta keywords | meta_keywords.{locale} | Importable translated text, including the post-Phase-3 addition |
| Age warning | age_warning_message.{locale} | Importable translated text, including the post-Phase-3 addition |
| Primary category image | image_url, remove_image | Download a public image through the existing private image service, preserve by omission/blank, or detach with remove_image=true |
| Category ID | category_id | Existing update selector only; never a supplied new primary key |
| Translation ID, category_id/locale_id on child rows | Derived from the selected category and trusted locale code | System generated, not direct CSV columns |
| lock_version, timestamps, system_key | Managed by normal operations | Non-importable; unknown/system columns rejected |
| Parent age verification/limit, ancestors/descendants/leafness | Calculated live from the tree | Derived, non-importable |
| Media IDs, disk keys, paths, MIME, dimensions, byte size | Private storage metadata | Non-importable; cannot attach an arbitrary private file |

Canonical nonlocalized columns are category_id, category_path, position, status, age_verification, age_limit, exclude_from_discovery, customer_groups, remove_image, image_url. Append the eight translated field names above with `.en`, `.el`, and each other active supported locale. The complete template has 27 columns when English and Greek are active. The runtime template/help, rather than a separately maintained static full header, is authoritative after locale additions. Names are exact, case-sensitive keys; whitespace surrounding header cells is trimmed. Duplicate, unsupported and inactive-locale columns fail validation. No fuzzy aliases or language-specific database columns.

## Merchant workflow

Categories → CSV icon (`/admin/categories/import`) → download template → select CSV/delimiters → Upload & preview → inspect summary, sample and errors → explicitly check confirmation → Import. The icon has a localized accessible name and tooltip. Upload/preview performs **no category inserts, updates, deletes, sequence allocation, events or mutation-participant calls**. It does save a private temporary file and short-lived owner-bound preview metadata. Errors block the entire import. Discard removes only that temporary upload/preview, not imported categories.

CSV uses UTF-8, optional UTF-8 BOM, comma by default or semicolon, double-quoted fields with doubled quote escaping, LF/CRLF and quoted embedded newlines. PHP's [fgetcsv](https://www.php.net/manual/en/function.fgetcsv.php) parses streams with an explicit empty escape argument; a strict record-shape/UTF-8 check rejects PHP's permissively accepted unterminated/bare quotes. UTF-16, legacy encodings, NUL, malformed rows, duplicate headers and extra/missing cells are rejected rather than guessed. Row numbers are **logical CSV records**, header = 1; embedded newlines do not increment the logical record. Blank physical records are skipped but retain their record number.

The [sample CSV](../examples/category-import.csv) is intentionally small and contains only invented example category text. The download template always includes one example row and current locale columns; do not import the example into a real catalog unintentionally. Remove unused columns for safe partial updates.

## Identity, paths and creation

- Nonempty category_id must be a canonical positive ID of an existing normal category. It is authoritative; names/paths never select another category for an update. Duplicate IDs in one file fail, even if values match. System Uncategorized always fails.
- Empty/omitted category_id creates, never upserts by name. A creation path/name already occupied is rejected and asks for the existing ID. Duplicate creation rows are rejected, including root-name collisions.
- category_path is the **full** root-through-self path in the **system default locale**, regardless of admin interface language. `///` is the configurable default separator; the form accepts a bounded punctuation-only alternative. Segments are trimmed exact names, case sensitive, nonempty, at most 200 characters and without control characters. No English/Greek simultaneous matching or translation fallback is used.
- If category_path is absent/empty on update, preserve the parent; absent on creation means root. A one-segment path explicitly moves an existing category to root. Its last segment sets the default-language name; a separately supplied nonempty name in that locale must agree. An empty default name cell with a path takes the explicit path leaf, not fallback text.
- Resolve each intermediate segment under its exact parent. Multiple sibling matches are ambiguous and fail. Missing parents are created through the shared category operation, disabled, appended, with default-language name/derived slug and safe age/image defaults, regardless of the active language count; the preview counts/warns about them. Other languages remain empty. Global locale/slug conflicts require explicit parent rows.
- Process rows in file order against a simulated evolving tree. Place explicit parent create/update/rename rows before rows referring to their new paths. A parent automatically created by an earlier row cannot later be edited without a real ID in the same file; use an explicit parent row first, or a subsequent import. Path names containing the separator cannot be escaped: use a different separator, or update by ID without a path.
- Reparenting checks self/ancestor cycles in simulation and again through CategoryRules/service/PostgreSQL at commit. No depth cap, stored product paths, recursive deletion or Product membership exists. Future before-parent-use participants can veto changes atomically.

## Partial update and empty-cell semantics

Missing columns preserve existing values, including other languages and image references. Creation defaults: disabled, append position, age verification false, age limit NULL, no image. Defaults do not overwrite existing categories.

| Supplied empty cell | Behavior |
| --- | --- |
| category_id | Create |
| category_path | Preserve parent on update; root on create |
| position / status / age_verification / exclude_from_discovery / customer_groups / remove_image | Preserve on update; use creation defaults (no restriction, not excluded) |
| age_limit | Clear to NULL; invalid if resulting own age verification remains enabled |
| Optional translated text | Clear the supplied locale field in any language mode |
| name.{locale} | Invalid for an existing or otherwise populated translation, except the explicit default path-leaf rule above |
| slug.{locale} | Regenerate from that locale's resulting name, using the normal slug normalization rules |
| All supplied fields empty for an absent locale row | Leave that locale absent/empty; never copy another language |

Supplied locale columns merge with the **exact existing locale row** before normal validation/save, preserving omitted description/SEO/alt/extra fields even though the editor's DTO uses full edited rows. Nonempty content for a missing locale requires its name; its slug derives if omitted/blank. Existing UI constraints apply: name/title/slug/alt 200, meta description 500, keywords 1,000, age warning 2,000, description 20,000 characters. Slugs remain unique by locale across the catalog and earlier planned rows. No translatable JSON or cross-language writes. Own age restrictions and derived strictest parent behavior are unchanged.

The partial CSV policy (A43) allows any subset of active languages, including one nondefault locale. A new category needs a populated name/normalized slug in at least one active locale; optional text can remain empty. A name in the system default locale is required only when using category_path, whose segments explicitly supply that locale. Without a path, a new category is a root and may have only a Greek translation even when English is the default. Missing translations remain absent normalized rows, shown as empty tabs in the editor. Do not insert blank names/slugs or copy another language. Default-locale path lookup cannot identify a category without its default-language name; update it by ID or add that translation first.

Inactive-language columns fail at the header, even with empty cells. Omitted languages/fields preserve existing content, while supplied optional blanks clear their own locale. Later CSV updates can add missing translation rows by category_id. Normal dashboard saves still enforce A42 completeness across all active languages; complete the missing tabs before saving there. Preview and confirmation use the same import-specific field policy through the shared persistence operation; its import entry point separately rechecks categories.import and create/update permissions. Changing active/default languages invalidates outstanding previews. The live template shows all active locale columns as examples; remove unused columns or leave an absent locale entirely blank.

Position uses the existing positive 32-bit sibling integer. Blank new positions append; explicit collisions shift later siblings using the normal service. File order makes duplicates deterministic; subsequent rows can shift earlier rows, so row preview positions describe their sequential requested operation rather than promise an immutable final position. Existing gaps survive. Status accepts only active/disabled; do not localize CSV codes.

## Images from URLs

The upload screen reuses the admin media drop zone, shows the selected filename and size, allows replacement/removal, and keeps delimiters together. Full format/column instructions are collapsed below the form. Uploading or dropping a file never starts confirmation.

`image_url` sets/replaces the **primary category image** using a direct public HTTP(S) URL, for example:

```csv
category_id,image_url,image_alt.en
123,https://your-image-host.example/category.jpg,Category photograph
```

Use an actual category ID and image host. Blank/omitted URLs keep the current image. `remove_image=true` detaches it and cannot be combined with a nonempty URL. Existing original-file retention remains unchanged. Block/banner images and arbitrary disk paths/media IDs are not imported.

Preview validates URL syntax and counts image rows but makes no DNS/HTTP requests. On confirmation, validated URLs download once each to temporary files **outside the catalog SQL lock**, before applying any category change. The catalog/file/permissions are rechecked after downloads. One bad image aborts the entire import with its row/column; staging files always close, and newly stored unreferenced images are discarded on SQL rollback. Normal image validation/storage and category save operations remain authoritative.

Only direct public URLs on HTTP/HTTPS standard ports are accepted: no credentials, fragments, local/private/reserved IPs, redirects or proxy use. All resolved A/AAAA addresses must be public; cURL pins a checked IP while retaining TLS hostname verification to prevent DNS rebinding. Actual image bytes must be JPEG, PNG or WebP, at most 5 MiB and 6,000 × 6,000 pixels. Streaming/header limits also abort oversized transfers. PHP cURL is required (available in the project Docker image); missing support fails closed. No new package is required.

Defaults in `config/category-import.php`: 100 image rows per CSV, 100 MiB of unique downloads, 120-second aggregate download budget; each HTTP transfer has a 5-second connection and 15-second total timeout, capped by the remaining budget. DNS uses the deployment resolver. For larger image batches, split the CSV. This remains an explicit synchronous import, not a background image queue. The downloaded image is copied into private storage, so future image display does not depend on the remote URL.

## Safety, preview, results and permissions

Every route requires the existing verified active-staff/session boundary plus **categories.import**. Creation, including every missing parent, also requires categories.create. Updates/moves require categories.update. No deletion exists; categories.delete never grants import. categories.view alone grants neither import nor writes. Definition synchronization does not grant import to existing roles; assign it deliberately through Roles. Super Admin uses the normal registered-capability bypass and still cannot edit the system category.

The preview contains source-row count, created (including automatic parents), updated, unchanged/skipped, parent warning count, first 30 planned operations and first 200 row/column/reason/severity errors. All errors are counted even after display truncation. The downloadable report contains the same bounded issues, identifiers and reasons, not all original CSV cells. Formula-leading cells are escaped for spreadsheet safety. No unrestricted CSV download/storage key is exposed.

Confirmation requires an owner-bound unpredictable token and checked confirmation, plus a per-token Redis lock shared with discard. Replaying a completed/failed/processing token is rejected. Before downloads and again before mutation, recompute validation and compare the private file SHA-256 and whole-catalog/locale fingerprint under the category SQL advisory lock before any write. An intervening category change requires a fresh preview, even if unrelated; this conservative rule avoids silently applying a stale plan. Newly revoked create/update capabilities invalidate relevant rows. Expired tokens return 404; uploads/confirmations have explicit per-user request throttles.

The whole import is one PostgreSQL transaction. A disk-spooled normalized operation plan is replayed through ManageCategoriesHandler; its nested operations reuse the same lock, validation, versions, position shifts, events and participants. Any validation/constraint/participant failure rolls back **all** import writes; completion is never partial. Preview cannot guarantee success of arbitrary transactional participants without calling them, so commit-time vetoes are reported as rollback failures. Normal create/update/move facts wait for the outermost commit. Post-commit listener errors are reported diagnostically but cannot claim a rollback or encourage duplicate category creation. No exactly-once event delivery/outbox is promised.

Results distinguish preview, processing, completed and failed; partial is deliberately impossible for this atomic importer. Failed results show zero saved creations/updates and an explanation. Unexpected exceptions are not hidden: Laravel reports them; a server failure must be investigated rather than treated as successful partial import. Preview/result metadata expires after one hour. It is not durable import/audit history.

## Scale, operations and retention

Defaults in config/category-import.php: 20 MiB upload, 10,000 logical data records, 256 KiB per record, 1-hour preview TTL. CSV/file hashes are streamed; no whole-file file_get_contents or array of full CSV rows. A batched catalog/translation scan computes the fingerprint and retains only hierarchy/name/slug/core metadata. Full descriptions are read only for edited locale rows and written to the temporary disk plan, not kept across the file. After-commit callbacks capture IDs/parent/position/facts rather than whole DTOs. Memory is O(catalog metadata + planned short identities + bounded row/chunk), not constant-memory hierarchy simulation. A very large catalog still requires tuning; do not advertise unlimited imports.

Preview caches capability checks and validation schema once and uses indexed in-memory parent/name/slug/position maps, not per-field/tree queries. Real writes intentionally invoke normal authorization/business operations; this costs more queries than unsafe bulk INSERT. A tested 1,000-category import completes atomically locally; deployment latency/size/time limits require measurement. The SQL lock serializes catalog writes during preview/commit; use off-peak imports or smaller files for heavily edited catalogs. Reverse-position imports may shift many siblings; append or parent-first/ascending positions are cheaper. There is no chunked partial-success mode or automatic queue.

Docker mounts docker/php/uploads.ini with matching upload limits; restart/recreate app when this configuration changes. Set compatible reverse-proxy/body/time limits in production; these local settings are not a production deployment recipe. Configure Laravel's scheduler (`schedule:run` every minute or `schedule:work` in development) for hourly **cms:category-import-prune**, or run it explicitly. It deletes only expired private import files after a grace period and absent cache metadata. Discard immediately deletes the specific temporary upload. Redis loss invalidates previews; files are subsequently pruned. Ensure private disk capacity, restricted access and appropriate backup exclusion/retention. No application log contains full CSV/row text or SQL bindings: success/failure metadata contains actor, filename, row counts, duration and safe error type/SQLSTATE. Application logs are not a durable audit ledger.

Future background execution can call the consumed Application port, but needs approved progress/state/timeout/idempotency design. No Product/Customer/Order import types or generic field engine are scaffolded. Category IDs, translations, leafness, ancestor paths and deletion contracts are unchanged.

## Verification

Backend coverage includes read-only preview/sequence/event behavior; all editable fields; exact en/el updates; BOM/quoted multiline/semicolon parsing; nested/missing parents; ID-authoritative moves; positions/noops; malformed/unknown/system/duplicate fields; invalid values/slugs/IDs; cycles and ambiguity; default/admin/future locale separation; permissions/ownership/CSRF; stale file/catalog/revoked capabilities; full rollback on participant veto; private image retention/removal and URL replacement; SSRF, invalid/oversized/failed downloads, stale data after downloads, and image rollback cleanup; bounded 1,000-row preview/import; cleanup; normal post-commit events and listener failure. Frontend tests cover explicit preview upload, mandatory confirmation and blocked-error reports. Full existing authentication/RBAC/category tests remain required regressions.
