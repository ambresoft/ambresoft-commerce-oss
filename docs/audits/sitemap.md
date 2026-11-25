# XML Sitemap delivery and verification

Date: 2026-11-03. Scope: Tasks 14–23 and the Sitemap regression review in Task 29. Implementation: `modules/ambresoft/sitemap`, version 1.0.1. [Design and operating instructions](../architecture/xml-sitemap.md).

## Delivered

- Existing add-on framework: Composer manifest, owned migrations, install/enable/upgrade/disable lifecycle, namespaced capabilities and translations, Website navigation, and the standard **Ambresoft icon**. No second extension framework or dependency upgrade.
- Seven separate providers: homepage, products, categories, Pages, brand landings, Blog landings and published Blog posts. They consume a read-only core contract that reuses existing URL builders and guest visibility rules. Default-language canonicals follow the existing session-language architecture; no translated routes are invented.
- Database-backed section settings and schedules; paginated URL management with source identity, true lastmod, warnings, nullable inclusion overrides and validated URL overrides. Overrides cannot change a content slug or create a new route.
- Separate Generate/Refresh Draft, Save Draft and confirmed Publish actions. Optimistic versions reject stale saves. Refresh preserves manual overrides and retains diagnostics for removed/invalid sources.
- Immutable published snapshots and XML files in PostgreSQL, switched with one pointer only after complete validation. Failures roll back, retaining the previous publication. Old versioned part links remain valid.
- Anonymous `/sitemap.xml`, XML content type, UTF-8 escaping, standard namespace, conditional ETags, and a sitemap index when required. Splitting checks both 50,000 URLs and 52,428,800 actual serialized bytes. No priority/changefreq elements.
- Per-section hour/day/week/month/year/Never schedules through the existing Laravel scheduler/queue. Automatic work starts from the published selection, never an unfinished manual draft. Tokens, version checks and a PostgreSQL advisory transaction lock protect concurrent work. Failures expose safe messages and retain successful timestamps/output.

The existing HTML `/sitemap`, catalog data, variation selection, product pricing, search, cart and checkout flows are preserved. Public XML requests do not generate content. `robots.txt` is outside the feature: it was not opened, read, generated, edited or managed; its test suite was deliberately excluded. Existing reserved-path constants remain intact.

## Migrations and boundaries

1. `2026_11_27_000100_ambresoft_sitemap_create_sitemap.php`: six prefixed tables for section settings/status, draft rows, singleton state, immutable versions, version rows and XML files. Foreign keys, uniqueness, due-work indexes, count/size/status/frequency checks and singleton constraints are applied in PostgreSQL.
2. `2026_11_27_000200_ambresoft_sitemap_retain_invalid_url_diagnostics.php`: retain overlong discovered URLs as diagnostics and constrain section snapshots to JSON objects. Rollback refuses truncation.

The follow-up migration preserves the locally installed initial version; no tables or existing CMS data were dropped. Both up/down paths were tested in the isolated test database. Add-on uninstall retains its data under the existing lifecycle.

Core changes are limited to `StorefrontUrls`/`IndexableUrl`, the visibility/canonical adapter and binding, batch URL helpers reused by existing page builders, contract-4 compatibility, and reservation of XML sitemap paths from SEO redirects. Contracts 2/3 remain supported. The bundled admin screen uses a build-time Inertia bridge and Tailwind source entry; uploaded runtime React code is not introduced. Authorization remains server-side with separate view/manage/publish capabilities.

## Automated verification

Commands run from the repository root, using `docker compose -p cms-foundation exec -T app` for PHP. Backend tests use the configured **cms_test** database, separate from local **cms**. Results are actual executions, not inferred coverage.

| Check | Result |
| --- | --- |
| Before-change `AddonManagerTest` + `StorefrontSeoTest` | 34 passed, 472 assertions |
| Final `php artisan test tests/Feature/SitemapTest.php tests/Feature/SitemapProvidersTest.php tests/Unit/SitemapXmlTest.php tests/Feature/AddonManagerTest.php` | 76 passed, 706 assertions |
| Broad backend regression command below | 319 passed, 1 existing failure, 12,983 assertions; 138.81 seconds |
| `pnpm exec vitest run resources/js/tests/Sitemap.test.tsx` | 6 passed |
| Full `pnpm test` | 464 passed, 119 failed in 19 existing admin test files; existing mock failure described below |
| `pnpm types` | Passed |
| `pnpm lint` | Passed, with two existing hook-dependency warnings in unchanged Default theme `parts.tsx` |
| `pnpm format:check` | Fails on unchanged `.mcp.json` and `boost.json` only |
| `composer lint` (Pint, entire PHP tree) | Passed, 1,468 files |
| Prettier scoped to changed frontend files | Passed |
| Focused PHPStan on the module, core contract/DTO and canonical adapter | Passed, no code errors |
| Full `composer analyse` | 34 existing errors; no Sitemap code errors |
| Architecture baseline and final test | Same existing failure in unchanged `StorefrontSeo.php` importing presentation middleware; final run 1,051 assertions |
| `pnpm build` | Passed; final production build 1.14 seconds |
| `git diff --check` | Passed |

Broad regression command (the entire result is reported, including the unrelated failure):

```sh
docker compose -p cms-foundation exec -T app php artisan test \
  tests/Feature/SitemapTest.php tests/Feature/SitemapProvidersTest.php \
  tests/Unit/SitemapXmlTest.php tests/Feature/AddonManagerTest.php \
  tests/Feature/AddonSettingsPanelTest.php tests/Feature/StorefrontCatalogTest.php \
  tests/Feature/StorefrontCatalogSearchTest.php tests/Feature/StorefrontSeoTest.php \
  tests/Feature/ProductPageTest.php tests/Feature/CategoryListingTest.php \
  tests/Feature/PageTest.php tests/Feature/BrandTest.php tests/Feature/BlogTest.php \
  tests/Feature/DirectoryPagesTest.php tests/Feature/GeneralSettingsTest.php \
  tests/Feature/GeneralPreferencesTest.php tests/Feature/LocalizationTest.php \
  tests/Feature/CartTest.php tests/Feature/CheckoutTest.php \
  tests/Feature/SeoRedirectTest.php tests/Unit/RedirectRulesTest.php
```

New coverage includes all content types and visibility rules, translations/default-language canonicals, category ancestry, purchasability/backorders, real lastmod, legacy brand options, duplicate/invalid/private URLs, source removal/restoration, override preservation, paginated saves, permissions, schema integrity/rollback, stale edits, queued failures, confirmed publication, anonymous XML, ETags, versioned indexes, immutable prior parts and failed-publication rollback. Scheduling tests cover every frequency, Never cancellation, published overrides, manual-draft isolation and a lock held by a separate PostgreSQL connection. The serializer tests actually process **50,001 URLs** and a **27,000-URL payload exceeding 50 MB**; the production ceilings are exercised, alongside smaller HTTP split-publication fixtures.

Frontend tests exercise explicit settings/draft saves, dirty-state protection, Generate without Publish, publication confirmation/cancellation, read-only access and server errors. Browser testing caught and fixed missing admin shell props; the backend now asserts navigation/breadcrumb projection as well.

### Existing quality-check failures

- `LocalizationTest::test_react_literal_translation_keys_exist_in_the_server_dictionary` mistakes the existing dynamic prefix `packages.by_` in `Admin/ProductPackages/Index.tsx` for a complete literal key. The test and component are byte-for-byte unchanged from HEAD.
- The 119 frontend failures share `AdminLayout.tsx` reading `.startsWith` from a missing `usePage().url` in old test mocks. Running unchanged `Categories.test.tsx` alone reproduces the failure (6 failed, 6 passed); both it and AdminLayout are byte-for-byte unchanged from HEAD. All six new Sitemap frontend tests pass.
- Full PHPStan reports existing issues in catalog seeders/hierarchy/queries/persistence, product companions, customer self-service/social sign-in, reports, CategoryLanding, LayoutRenderer, ProductPage and StoreBrand. The ProductPage issue is the same pre-existing collection-invariance call moved by the new batch helper. No ignores were added to project configuration. Focused analysis uses a temporary config disabling unmatched-ignore reporting only, because the existing addon-config-specific ignore does not apply to that narrower set of files.
- `ArchitectureTest` failed before implementation on the unchanged StorefrontSeo-to-HTTP-middleware import. Its original baseline log is retained locally. Formatting failures concern unchanged AI tooling JSON files. These were not rewritten to make this task appear green.

Local raw logs are under `/tmp/cms-sitemap-*.log`, including `final-focused`, `regressions-final`, `frontend-focused`, `frontend-final-all`, `existing-frontend-failure`, `static-full`, `static-final`, `architecture-baseline`, `types-final`, `lint-final`, `format-check` and `build`.

## Local Docker/browser verification

The add-on is **enabled locally at 1.0.1**. Its prior installed state was preserved and upgraded. The existing worker and scheduler were restarted to load the provider/autoloader. The actual Greek admin overview, product URL pagination and native publication confirmation were exercised through Chrome. The first delivery left publication pending review; the subsequent request to resolve the public 404 was handled through the normal confirmed Publish workflow.

Generate/Refresh Draft completed through the real local queue with **200 eligible draft URLs**: homepage 1, products 129, categories 49, Pages 3, brands 8, Blog landings 2 and Blog posts 8. These 200 URLs are now published locally. An anonymous HTTP request to `/sitemap.xml` returned **200** with `application/xml; charset=UTF-8`. Parsing the actual response confirmed the standard namespace, 200 unique absolute canonical URLs, and no private paths or query parameters. Chrome also displayed the XML tree. Public successful publication/index/headers/failure behavior is additionally verified by Docker HTTP feature tests in cms_test.

The Add-ons list visibly renders the same Ambresoft mark as the other bundled add-ons for Sitemap. No existing add-on was disabled or reconfigured.

### Follow-up: Add-ons Settings shortcut

The requested Settings gear now appears on the enabled Sitemap add-on row and links directly to `/admin/sitemap`, reusing the existing Settings action renderer. Server-side projection requires `ambresoft.sitemap.view`; disabled add-ons and staff without that capability do not receive the link. The destination retains its independent route authorization. No React changes, new translations or schema changes were needed.

`php artisan test tests/Feature/SitemapTest.php tests/Feature/AddonManagerTest.php tests/Feature/AddonSettingsPanelTest.php`: **60 passed, 795 assertions**, 25.56 seconds. Includes the new enabled/disabled/view-permission projection checks. Pint passed for both changed PHP files; `git diff --check` passed. The gear and its href were verified in the actual Add-ons screen. The broader project checks were not repeated for this three-line projection change; their earlier results above remain applicable as historical results.

### Follow-up: catalog exclusion shortcuts

Products and categories now have **Additional → Exclude from XML sitemap**, saved by their existing Save action and update permissions. Category inheritance excludes descendants and their products during XML discovery without changing child records. Direct child exclusions survive parent re-inclusion; moving a category changes its inherited exclusion. A sitemap-table Include override cannot force an excluded source into XML, and existing draft overrides remain intact. Public catalog visibility, page robots directives and the Sitemap settings UI are unchanged. Catalog saves do not publish XML; the normal draft/publish and automatic refresh workflows apply.

Core migration `2026_11_28_000100_add_catalog_sitemap_exclusions.php` adds non-null boolean preferences with false defaults to products/categories. It was applied locally, preserving existing inclusion choices and the published snapshot. The worker/scheduler were restarted to load the rules. CSV columns remain unchanged and updates preserve omitted preferences; product duplication retains its explicit preference.

Verification for this follow-up:

- Product/category editor suites: **33 UI tests passed**, including the two new controls, draft preservation across tabs, no automatic save, and explicit submitted values. Their old `usePage` mocks now provide the real URL field required by AdminLayout.
- Final targeted backend checks: **7 passed, 149 assertions**, covering HTTP persistence/validation/permissions, omitted values, duplication, parent/deep descendant inheritance, direct child restoration, category moves, public visibility, source-level exclusions versus manual Include, draft/live separation, migration rollback/defaults, and unchanged CSV headers/preserved preferences.
- Broad backend run: **221 passed, 3 failed**, 4,107 assertions. One was the CSV test's former assumption that every fillable category field belongs in its CSV template. Its assertion now explicitly excludes the editor-only sitemap field and verifies the template is unchanged; the corrected check passes in the final targeted run. The other two failures reproduce with the original HEAD test files: CategoryTest's old migration round trip omits a later variation-card translation dependency, and ProductFacebookTypeTest's social metadata string assertion fails. These unrelated application/test issues were not rewritten. Original-test reproduction: 2 failed, 10 assertions.
- TypeScript, ESLint, changed-file Prettier, and full Pint (**1,469 files**) passed. ESLint retains the two documented Default theme warnings. The production build passed (1.17 seconds).
- Full PHPStan remains at the previous **34 errors** after adding types for the new persisted fields; no project ignore was added. `git diff --check` passed.
- Chrome visual verification was attempted but interrupted by a changed/disconnected browser connection and concurrent native-window changes. No exclusion was saved through that browser session. Automated editor tests and the production build passed; a final visual screenshot of the new controls was not obtained.

Logs: `/tmp/cms-sitemap-exclusions-{focused,final-focused,regressions,baseline,ui,types,lint,prettier,static,pint-check,build}.log`. No crawler-directive files were opened or modified. No first-party sitemap settings or existing catalog exclusions were reset.

## Changed-file inventory and review

- Add-on: manifest/icon; two migrations; English/Greek dictionaries; trusted React admin page; Domain URL/XML services; Infrastructure provider registry, seven concrete providers and their shared adapter, Sitemap service, two jobs and service provider; three HTTP presentation classes.
- Core: new `app/Storefront/Application/Command/IndexableUrl.php`, `Application/Contract/StorefrontUrls.php` and `Infrastructure/IndexableStorefrontUrls.php`; additive URL helpers in ProductPage/StorefrontPages/StorefrontBlogs; StorefrontServiceProvider binding; ModuleManifest contract support; RedirectRules sitemap-path protection; the subsequently requested Settings link in `app/Admin/Controllers/AddonController.php`.
- Build/UI integration: `resources/js/pages/Admin/Addons/Sitemap.tsx`, `resources/css/app.css`, `eslint.config.js`, `package.json` scripts. Dependencies and lockfiles are unchanged.
- Tests: `tests/Feature/SitemapTest.php`, `SitemapProvidersTest.php`, `tests/Fixtures/MutableSitemapUrls.php`, `tests/Unit/SitemapXmlTest.php`, `resources/js/tests/Sitemap.test.tsx`.
- Documentation: README; add-on developer guide; architecture decision register, module-system and XML Sitemap design; development guide; this report.

The complete tracked diff and new-file inventory were reviewed for ownership, migrations, authorization, routing, translations, stale writes, keyboard access, XML serialization, publication failure and Docker operation. Deleted tracked media fixtures left by existing tests were restored. The later authorized catalog-exclusion follow-up changes the product/category forms and persistence flow, their request/DTO/projection/type contracts, English/Greek labels, one additive core migration, discovery predicates and targeted tests. Production credentials/configuration were not modified. No Git commit was created.

## Remaining operational limits

- First publication requires the explicit review/Publish workflow. Configure the intended canonical `APP_URL` and keep existing queue/scheduler services running; restart them after lifecycle changes.
- Old snapshots/parts are retained indefinitely to preserve immutable links. High-volume installations need a deliberate retention policy; no destructive purge was introduced.
- Large-catalog publication is synchronous in the admin request. Hosting request limits and the existing queue reservation/timeout configuration need realistic catalog sizing. Public XML reads are independent of discovery. Boundary serialization is tested; production catalog throughput, memory capacity and proxy limits were not benchmarked.
- Legacy feature-option brand records lack reliable update timestamps, so lastmod is omitted. Homepage lastmod uses known layout updates; automatically changing block content does not receive a fabricated timestamp.
- Other sections retain their published state until their own refresh interval. Eligibility is checked during discovery and manual publication, rather than on every public request.
- Google Search Console submission/external validation and production-domain crawling were not performed. No SEO score or promise of indexing is claimed. The whole repository is not green for the existing reasons above.
