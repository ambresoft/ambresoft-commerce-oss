# Company data settings

Settings → Company data (`/admin/settings/company`) stores one installation-wide business identity. It reuses `settings.manage` for both reading and saving; navigation does not grant access. The server service independently authorizes direct callers.

The existing admin design uses sticky Company, Address and Contact emails tabs with one save transaction. All six supported dictionaries provide UI labels are keyed translations; company details are shared business values, not automatically translated. All fields are optional. Company name, address, city, region/state, postal code, phone, mobile, website, online store opening year and customer/admin/order/support/newsletter-reply emails are supported. No example merchant data is seeded.

The form panels fill the tab bar width. Countries reuse the ISO allowlist and localized names; the searchable dropdown matches names and ISO codes without case or accent sensitivity, retaining the saved choice until an option is selected. Region/state is merchant-entered text because no regional catalog exists. Postal codes and telephone values stay strings, preserving leading zeros and international formatting. Website accepts only HTTP/HTTPS URLs; each populated email must be valid. Opening year accepts integers from 1900 through the server's current year. Length limits and control-character rejection run on the server. Empty strings become NULL; omitted fields retain their saved values.

Migration `2026_10_13_002100_create_company_settings.php` creates typed nullable columns, the protected singleton identity CHECK (`id = 1`), positive `lock_version`, nullable `updated_by` with SET NULL, and a UTC-aware update timestamp. It inserts a blank row, leaving general/tax settings and existing business data untouched. Company settings have their own lock version: an unrelated general-settings save cannot conflict. A row lock serializes writes; stale saves require refresh/review and never merge silently. An unchanged save produces no mutation or new audit record.

The existing PostgreSQL audit capture trigger records only reviewed company fields in the same transaction; rollback removes both changes and their success event. The ledger retains actor context and before/after business contact changes; large strings follow the existing bounded/redaction policy. Enter only business contact information. Audit history remains super-admin-only. No separate duplicate success event or authentication secret is recorded.

These values are configuration storage only. They do not configure SMTP, send emails, change storefront output, invoice identity, tax defaults or newsletter behavior. Later explicitly authorized consumers can read `CompanySettings::all()`.

Apply with `docker compose exec -T app php artisan migrate`, then rebuild with `pnpm build`. No new capability or permission synchronization is required. The existing settings capability now describes both General and Company Data. Migration rollback drops this table and its owned trigger; audit history remains.

## Store logos (Logo tab)

The Logo tab stores the store's logo for the future storefront header, plus an optional separate logo for mobile devices.

- **Store logo** is used on every device. **Mobile logo** is optional and is shown instead of the store logo on phones; without it the store logo is used. A mobile logo cannot exist without a store logo (database CHECK), and removing the store logo also removes the mobile one.
- Images reuse the private image pipeline: PNG, JPEG or WebP, at most 5 MiB and 6000 px per side, content-verified. SVG is intentionally not accepted until a sanitizing policy is authorized.
- Migration `2026_10_14_000500_add_company_logos.php` adds `logo_media_id` and `logo_mobile_media_id` (restricted foreign keys to `media_files`) to `company_settings`. Saving logos is a separate multipart endpoint (`POST /admin/settings/company/logos`) with the same optimistic `version`, `settings.manage` authorization and audit capture; the logo ID columns are audited. Replaced or removed files stay for the media retention policy; `SharedMediaDeletion` refuses deleting a file that is a current logo.
- The admin reads them through `/admin/settings/company/logo/{desktop|mobile}` (private, `nosniff`, no-store). A public delivery path belongs to the storefront phase.

## Email logo (A111)

The same Logo tab has an optional **Email logo**, intended for white email backgrounds. Every outgoing template selects it through `CompanySettings::emailLogo()`, falling back to the store logo when unset or its file is missing, then to the store name when neither image is available. This applies to staff/customer invitations, verification and password resets, order/status/invoice and gift-card mail, newsletters, templates and confirmation autoresponders. Email previews use the same selection; sent mail embeds the private file as a MIME image rather than linking to an authenticated endpoint. Previously delivered emails keep their original images.

The email logo is independent: it can exist without a store logo, and removing either does not clear the other. It reuses the existing image validation, versioned multipart save, `settings.manage`, retention and audit rules. Admin previews use `/admin/settings/company/logo/email`. Migration `2026_10_22_000300_add_company_email_logo.php` adds the nullable, indexed, restricted `logo_email_media_id` foreign key and audit field. No backfill changes existing logos; empty installations and upgraded stores immediately use the existing fallback. Rollback removes only this setting, retaining store/mobile logos, media and audit history. Apply migrations before serving the new code and rebuild the frontend.

## Payment images

The Payments tab displays numbered image cards with the shared drag grip and arrow-key ordering, a compact inline upload/name panel, a pending-image card and a dark footer preview. The existing top Save button remains in the same place and adds the selected image; **Add to list** performs that same upload. The preview includes an unsaved selection without publishing it. Names remain optional alt text. The existing image pipeline, 5 MiB limit, PNG/JPEG/WebP allowlist, private delivery and maximum of 12 images remain unchanged.

Dragging or using the arrow keys saves the new order immediately through `POST /admin/settings/company/payment-icons/order`. It authorizes `settings.manage` in both controller and service, validates the complete unique ID set, and serializes ordering with additions/removals under the company row lock. A stale list fails without dropping images. Removal uses the shared confirmation dialog and preserves the original media file. Position changes are audited by the existing table trigger. No migration is required; rebuild the frontend and hard-refresh.

## Social icons

Social & apps shows the seven existing network icons as selectable cards, with an inline icon/link editor. Each network accepts an optional custom PNG, JPEG or WebP through the existing private image pipeline (5 MiB, 6000 px per side). Without an override, the admin and storefront use the same default SVG renderer. **Use default icon** clears only the override when saved; the link is preserved. Empty links hide that network from the storefront. App Store and Google Play links remain separate fields.

Links and icon changes save together using the existing top Save button and company update operation (multipart POST with PUT method override). `settings.manage`, optimistic company version and the singleton row lock protect the entire transaction. Failed validation/stale writes change neither links nor icons; failed new uploads are compensated. Replaced files remain for retention. `company_social_icons` has one unique allowlisted network, a restricted unique media foreign key and audited before/after changes. Admin image delivery is authenticated; storefront delivery checks the current icon owner/media relationship, so detached media cannot be fetched through that route.

Apply `2026_11_06_000600_create_company_social_icons.php` and rebuild the frontend. Existing links/default icons remain unchanged. Rollback removes only custom-icon assignments, retaining media files and audit history.
