# Staff authorization — Phase 2

Phase 4 adds `products.status`, `products.trash-view`, `products.restore` and `products.force-delete` alongside the existing product CRUD permissions. Run `cms:permissions-sync` to register definitions without silently widening existing role grants. [Product operation/capability matrix](products.md#authorization-and-lifecycle) is enforced server-side, including each bulk target. Shared-media/file association mutations require products.update; status changes also require products.status.

Phase 4.1 adds `currencies.view/create/update/rates/status/primary/delete`; updates require only the capabilities for the fields actually changed. Synchronization registers them without granting roles. [Currency operations](currencies.md#operations).

Authentication identifies a User; authorization grants capabilities. Customer management (Phase 9) is a separate context; customers are not identities and receive no grants. Identity owns credentials/verification; Authorization owns staff role memberships, capability grants and access-safety rules. Admin is their HTTP/Inertia presentation, not a business aggregate.

## Persistence and capabilities

FAQ administration adds `faqs.view`, `faqs.manage`, `faqs.generate` and shared `ai.logs.view` without default grants. Linking requires FAQ read access and the owner's existing edit authority; creating reviewed drafts also requires FAQ management. Generation additionally checks source-object access. AI configuration uses existing `settings.manage`, and log access is independent of catalog access. [FAQ capability contract](faqs.md#permissions).

The language-management follow-up registers `languages.manage`, the dotted equivalent of the requested illustrative `manage_languages`. It gates all system language/default activation and UI dictionary reads/edits. Ordinary editors may change only their personal supported dashboard language through the existing locale endpoint; no management permission is required for their own preference. Definitions are synchronized without auto-granting roles. See [language management](language-management.md).

The brand landing-page follow-up registers `settings.brand-landing-pages` without ordinary-role grants. Changing the public source requires it in addition to `settings.manage`; super administrators inherit it through the registered-capability bypass. Unchanged source values do not block ordinary settings saves.

The Settings → General follow-up registers `settings.manage`, which gates the General settings page and save on the server. Synchronization registers it without granting any role; assign it through Roles. See [general settings](general-settings.md).

Phase 8 registers `taxes.view`, `taxes.create`, `taxes.update` (definition, rates, status), `taxes.delete`, `taxes.assign` (bulk assignment to all products; also requires `products.update`), `rate-areas.view` and `rate-areas.manage`. Product-level tax assignment needs only `products.update`. Tax calculation settings use `settings.manage`. Synchronization registers them without granting any role. See [taxes](taxes.md).

Phase 9 registers `customers.view`, `customers.create`, `customers.update` (profile, groups, tax exemption, notes and addresses), `customers.status` (disable/restore), `customer-groups.view`, `customer-groups.manage`, `customer-blacklist.view` and `customer-blacklist.manage`, checked in controllers and again in the Application operations. A customer record never grants admin access. See [customers](customers.md).

Phase 3A registers `categories.import` without changing existing role grants. Import screens/template/preview/confirmation/error downloads require this capability in addition to verified staff access. Each create (including an automatic parent) also requires `categories.create`; each update/move requires `categories.update`. Import never deletes categories. A user with only view/import cannot bypass those boundaries; permissions are checked again on confirmation. Assign the new permission deliberately through Roles; the existing registered-capability super-admin bypass applies. See [import contract](category-import.md).

User → role_user → Role → permission_role → Permission. Multiple roles combine permissions. Roles have bigint ID, editable name, unique lowercase slug, optional description, timestamps and a protected is_super_admin system capability. Permissions have unique dotted names and timestamps. Explicit composite membership primary keys, reverse indexes and foreign keys prevent duplicate/orphan grants. No permission JSON, user-specific override hierarchy, tenant scopes or permission package is introduced.

Staff capabilities are admin.access; users.view/create/update/deactivate/assign-roles; roles.view/create/update/delete/assign-permissions. Deactivate is deliberately not named delete: staff records are retained, not hard-deleted or blanket soft-deleted. A separately requested permission-only extension also registers products.view/create/update/delete, categories.view/create/update/delete and orders.view/update. Their definitions and editable grants do not implement or expose commerce routes, models or screens.

Five reference roles are created by cms:permissions-sync. All receive admin entry; Store Admin retains staff/role viewing while management authority requires explicit super-admin delegation. The requested commerce defaults are:

| Reference role | Commerce permissions |
| --- | --- |
| Super Admin | All registered capabilities through its protected system capability |
| Store Admin | All ten product/category/order capabilities |
| Catalog Manager | Product and category view/create/update/delete |
| Order Manager | Order view/update |
| Content Manager | Category view/update; no creation, deletion, product or order grants |

These initial grants are editable, not hardcoded business checks. Content Manager uses a least-privilege editorial default. Role names/descriptions are merchant-editable configuration data, not application translation keys. Normal synchronization does not restore defaults on an existing role.

## Enforcement and super administrator

AuthorizationServiceProvider registers Laravel gates from PermissionRegistry after every enabled provider boots. The stable access-admin gate delegates to admin.access. Every admin route requires auth, auth.session, verified and can:access-admin. Each controller/Form Request additionally checks the operation capability. Frontend capabilities/navigation only affect presentation.

AccessChecker queries persisted active/verified state and role grants; no cross-request permission cache or stale Eloquent relation decides access. Revocations affect the next request. The global browser middleware refreshes the auth record and logs disabled identities out; login rejects disabled accounts using the same generic credentials error.

A protected role's is_super_admin capability grants all **registered capabilities**. It does not return true from a blanket Gate::before, override unknown abilities or bypass unrelated resource/business policies. Future policies must check the appropriate capability and still enforce resource constraints. No email, account ID or ordinary role slug confers runtime privilege. [Laravel gates and policies](https://github.com/laravel/docs/blob/13.x/authorization.md).

## Super-controlled staff management (2026-10-22)

Only super administrators have staff-management authority by default: `users.create`, `users.update`, `users.deactivate`, `users.set-password`, `users.assign-roles`, `roles.create`, `roles.update`, `roles.delete` and `roles.assign-permissions`. The one-time 2026_10_22_000200 migration removes their legacy grants from non-super roles while preserving accounts, passwords, commerce grants, role membership and super administrators. Rollback does not restore those old powers. Permission synchronization creates the new definition but never reinstates removed powers or removes later deliberate grants.

A super administrator can grant these capabilities through a role and assign it to staff. Delegated staff can perform the granted operations within the existing grant ceiling, but cannot grant/revoke protected staff authority through role definitions or changes to another user's effective capabilities. Only super administrators edit roles that contain protected staff authority. Ordinary commerce permissions can still be delegated within the actor's authority. Super-admin membership and super-admin accounts always remain super-only.

Staff lists project permitted actions for each identity. Non-super staff receive no edit URL or disable action for a super admin; the direct edit page returns 403 and the shared mutation operation rejects crafted edits/deactivation. Self-deactivation and last-active-super protection still apply to super administrators.

`users.update` allows data editing but does not imply `users.set-password`. The latter is required for administrator-set initial passwords, password changes and recovery-email changes (which could otherwise bypass the password restriction). Fields are hidden/read-only accordingly, and both HTTP and the shared persistence operation enforce the permission. Invitation acceptance, mailbox recovery and a user's own passkeys retain their authentication proof requirements independently of administrative password-setting authority. Staff with `users.create` and `users.assign-roles` can invite staff into ordinary roles without seeing or choosing their password; the reserved identity gets an unguessable internal password until the invitee chooses their own credential.

## Safe mutation boundary

Admin Form Requests construct plain SaveStaffData/SaveRoleData inputs. Framework-free ManageStaffHandler uses StaffAdministrationInterface; EloquentStaffAdministration owns supported Laravel authorization and PostgreSQL transactions. Domain AccessSafety independently enforces self-deactivation and last-super invariants. No Eloquent, HTTP or framework helpers enter Domain/Application.

All access mutations and operator bootstrap serialize through the same transaction-scoped PostgreSQL advisory lock (2142512). Recheck fresh actor authority inside that lock. Preserve at least one active verified super administrator; never deactivate yourself. The system super role cannot be edited/deleted through HTTP; only super administrators manage its membership or accounts. Changing its email is an explicit operator recovery concern, not an ordinary form action.

An actor cannot assign stronger roles, grant capabilities they lack, edit stronger roles, or reset/edit a more privileged identity to take it over. A user update also needs users.deactivate when disabling an account and users.assign-roles when submitting role assignments. Role mutations need roles.assign-permissions as well as create/update. Deletes reject assigned roles, preventing indirect user-access revocation. Unknown/disabled module capabilities are not grantable.

Password changes use Laravel's hashed cast and rotate remember tokens; existing password-session fingerprint checks force other sessions to reauthenticate. Email changes clear verification and send a new signed verification notification. Staff creation sends verification too; no browser-supplied verification timestamp is accepted. Notifications occur after the database operation, not inside its SQL transaction. Mail delivery failure leaves an existing unverified account; verification resend is the supported recovery path.

The advisory lock coordinates supported application/operator mutations, not direct SQL from a privileged DBA. Do not bypass these operations with ad hoc pivot writes in production. Last-super checks are application invariants under that lock, not a misleading cross-row CHECK.

## Bootstrap and deployment

After migrating, run:

```sh
docker compose exec -T app php artisan cms:permissions-sync
docker compose exec -T app php artisan cms:admin-grant YOUR_VERIFIED_EMAIL
```

Register and verify the chosen identity first. The explicit operator command refuses missing, unverified or inactive users, grants the protected role idempotently, and never creates credentials, resets passwords or verifies emails. Existing runtime ADMIN_STAFF_EMAILS is ignored; its configuration was removed. Migrating alone grants nobody. Upgrade operators must explicitly grant each intended administrator before ending maintenance. This installation's existing verified development administrator was granted without changing credentials.

Synchronization creates missing permission definitions/reference roles, never overwrites existing grants or automatically assigns users. Run it explicitly when enabled module capabilities change. Retained definitions/grants from disabled modules do not create gates; reconcile stale grants deliberately before module reactivation. No provider writes to the database during boot.

For an existing installation, deliberately apply the requested commerce defaults with:

```sh
docker compose exec -T app php artisan cms:permissions-sync --grant-commerce-defaults
```

This opt-in operation adds only the commerce defaults above to the existing reference-role slugs under the same advisory lock. It is idempotent, removes no grants, leaves custom roles alone and does not assign users or restore unrelated staff permissions. Review reference-role membership before running it because existing members immediately gain those capabilities. New reference roles receive their full initial defaults without the flag.

## Admin workflows and future boundaries

/admin/users provides bounded search, active/inactive and role filters, stable name/ID ordering, 15-row server pagination, create/edit/role assignment and confirmed deactivation. /admin/roles provides list/create/edit/grant assignment and confirmed safe deletion. Both links appear under one localized Access management dropdown; each child is independently permission-filtered and an empty group never renders. Only minimal projections reach Inertia; credentials/tokens never do. Forms preserve server errors, show pending state and clear transient passwords. No customer management, impersonation, public grants, roles-as-UI-elements or commerce functionality.

Future modules register unique vendor/module-prefixed dotted capability names with PermissionRegistry::add(name, translationKey) from their enabled provider, then protect routes/policies independently. Use a namespaced translation key for the label; a declaration never grants a capability. Reference synchronization is explicit deploy-time work.

Release decisions still open: invitation/temporary-password delivery UX, recent-password/MFA requirements for privileged operations, durable redacted audit history/retention and production recovery procedures. Current application logs are not a promised security audit ledger. Profile editing and customer administration remain out of scope.

## Verification

Tests cover default deny, all staff route/mutation boundaries, multiple roles, immediate revocation, super/unknown-gate behavior, staff/role CRUD, stronger-account takeover prevention, safe deactivation/demotion, assigned/protected role deletion, direct Application authority rechecks, explicit bootstrap, module registration and CSRF. Phase 1 login/logout/reset/verification/session tests remain regression coverage.


Region CSV imports use the existing `regions.manage` capability behind active verified staff access. Index, template, preview, confirmation, discard and error reports all require it; the direct Geography import port and normal write operations reauthorize the actor. Uploads are owner-bound. The Imports / Exports hub includes Import regions only when that capability is present, and derives its own access from runnable transfers. Read-only `regions.view` grants no import access. No new permission or default grant is introduced. [Region import](geography.md#region-csv-import).


Follow-up verification (2026-10-22): focused authorization/authentication/passkey/architecture checks pass (68 tests, 7,765 assertions), with the additional delegated-invitation case also passing; the 23 frontend authentication/passkey tests, changed-file PHPStan, Pint, TypeScript, ESLint (one existing storefront hook warning), Prettier and production build pass. The upgrade was applied to the running local CMS and permissions synchronized. Branded email is tested as a real rendered multipart message with embedded image through the array transport; external delivery was not exercised.

### Permission editor navigation

The role editor's Permissions tab groups the existing grantable choices into Catalog, Orders, Customers, Website, Marketing, Payments/shipping/taxes, Settings/reports, Staff/access and Add-ons/other tabs, plus an All permissions view. Counts show selected/available permissions per category. Live search matches translated labels, capability codes and category names without case/accent sensitivity, and starts across all categories. Unknown add-on prefixes remain visible in Add-ons/other. These are presentation groups only: switching tabs or searching preserves the complete selected capability array, including saved choices not currently displayed. The existing server grantability filters, request validation and access-safety checks still control every save; browsing/searching never changes persisted role grants.
