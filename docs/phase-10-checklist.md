# Phase 10 implementation checklist — Payment Methods, Built-in COD, Bundled PayPal Add-on & Add-on Architecture

Roadmap JSON: not present; `docs/architecture/development-phases.md` records Phase 10 (payments and the add-on architecture move forward; pricing becomes unscheduled).

- [x] Inspect: contract-1 module loader (config allow-list, graph, provider registration; zero modules, no lifecycle/UI), Order write path (`OrderAdministration`, A82 left payment columns out), no storefront checkout or customer sessions, existing settings/secret, permission, navigation, translation and audit conventions.
- [x] Adaptation recorded: payments attach to staff-created orders; redirect providers use signed customer payment links (A86).
- [x] Add-on architecture (A85): contract-2 Composer manifest, bundled (`modules/`) and uploaded (`storage/addons`) sources, `addons` state table, install/enable/disable/upgrade/uninstall, `cms:addons-sync`, `cms:addon-validate`, `payment-methods` extension point, staged ZIP installer with path/link/encryption/type/size/ratio protection, transactional migrations with file compensation, retained data on uninstall.
- [x] Payment context: framework-free contracts, registry with owners, encrypted write-only settings, verification fingerprints, `order_payments` with single open attempt, atomic claims, idempotency keys and unique references; payment status separate from order status; status after payment applied once through `OrderAdministration`.
- [x] Cash on delivery in core (not an add-on): names/descriptions per language, order, enabled state, status after payment; orders stay unpaid until staff record collected money.
- [x] PayPal bundled add-on `ambresoft/paypal` (Orders v2 redirect flow, Sandbox default, credential verification, currency precision guard), separate add-on and method activation.
- [x] Example third-party add-on `docs/examples/addons/acme-bank-transfer` and developer guide `docs/addons.md`.
- [x] Admin: top-level Payment methods (list, order, switch, editor with schema-driven settings, verify), Add-ons (list, lifecycle actions, upload), order form method choice, order payment card (status, records, record payment, payment link, check status); sidebar "coming soon" placeholder removed.
- [x] Capabilities `payment-methods.manage`, `orders.payments`, `addons.manage`, `addons.upload`; add-on capabilities granted to no reference role.
- [x] Migration 2026_10_16_000100 (reversible), privacy-reviewed audit capture (no secrets, references or notes).
- [x] Translations: `payments`, `addons` groups and new order keys in all six languages; PayPal add-on catalog in six languages.
- [x] Full check (2026-10-13): Pint, PHPStan level 6 (app, modules, example add-on), **605 PHPUnit tests / 73,749 assertions** (73 new: payments, add-on manager and ZIP security, PayPal with mocked API, contract/constraint units), TypeScript, ESLint, Prettier, **186 Vitest tests**, production build, `composer validate --strict`, `git diff --check`. Migration applied to the local dev database; `cms:permissions-sync` and `cms:addons-sync` run (PayPal installed, disabled). New screens and the public payment page reviewed as rendered output; no live PayPal transaction was made.

Not built: storefront checkout/customer accounts, refunds, webhooks, voiding manual records, payment status in the order list, payment-link email variable, add-on UI assets, marketplace/signatures, additional providers.
