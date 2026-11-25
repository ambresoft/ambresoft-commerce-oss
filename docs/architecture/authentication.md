# Authentication and admin access

## Admin invitations and passkeys (2026-10-22)

Staff registration is private. `/admin/register` GET and POST return 403 without a valid invitation, including on a fresh installation. The admin login page offers password and passkey sign-in below the existing fields and does not offer public signup. Password sign-in rejects identities without persisted staff roles with the same generic failure as invalid credentials. Customer `/login` and `/register` retain their existing behavior.

The operator runs `php artisan cms:permissions-sync`, then `php artisan cms:admin-setup owner@example.com --name="Owner"` for a new installation. This reserves the first super-admin identity and emails its setup link; it never lets the first anonymous visitor claim ownership. Repeating setup for that same sole, still-unverified super admin resends the email and invalidates the previous link, allowing recovery from expiry or delivery failure. Other existing staff assignments prevent setup. Existing verified staff and the `cms:admin-grant` recovery command are preserved.

Access management → Users → Create defaults to an email invitation for staff who can assign roles. Choose roles, then save. The existing direct creation path remains available by disabling the invitation checkbox and supplying a staff-strength password. An unverified user's Security tab can resend their invitation subject to the same grant ceiling. Invitations use Laravel relative signed URLs with a seven-day expiry and a purpose-specific HMAC bound to user ID, email and password hash. The identity and roles are reserved by authorized staff; acceptance ignores submitted identity, active status and role fields. Acceptance locks the staff mutation boundary and identity, requires an active unverified identity with persisted roles, sets a password (or an unguessable random password for passkey-only registration), verifies mailbox ownership and rotates the session. The same link cannot be consumed twice, including concurrent password/passkey submissions. Password/email changes, verification, deactivation and removing all roles invalidate it. Production access logs must redact the invitation query string.

Invited staff can choose a passkey instead of a password on registration. Existing staff add or remove their own passkeys through the account menu → Passkeys, confirming their current password. Another staff member cannot enroll or revoke keys through this endpoint. A passkey-only user can set a password through existing mailbox recovery before managing keys; password recovery remains an available sign-in fallback. Passkeys are alternative authentication, not mandatory second-factor authentication.

`web-auth/webauthn-lib` verifies WebAuthn ceremonies. `staff_passkeys` stores the owning user foreign key, globally unique credential ID and the library's public credential record; no private keys or biometric data reach the CMS. Discoverable credentials and user verification are required. The relying-party host and exact allowed origin (including port) come from `APP_URL`, never the request Host. HTTPS is required in production; localhost is supported for development. Use one stable canonical origin and retain `APP_KEY`. The browser must access that exact configured origin.

Challenges are random, session-bound, purpose-bound and expire after five minutes. Enrollment is also bound to the identity and its password fingerprint. Atomic cache consumption prevents concurrent replay; credential counters update under transaction locks. Login checks live staff access, activity, verification and the staff credential policy before starting a session. Device assertions cannot grant roles. Password fingerprints continue to revoke existing sessions on password changes. Passkeys remain independent credentials until explicitly removed or account access is revoked. Registration/revocation have bounded audit events with no credential payloads. CSRF protection and existing no-store/no-referrer headers remain enabled. Passkey login options are IP-limited; login completions reuse the login limiter. Management operations each allow five requests per minute per staff identity.

Verification includes real generated ES256 keys/signatures, invitation acceptance and replay/expiry/tampering, invalid origin/signature/challenge/user handle/verification, disabled/demoted staff, current-password enrollment and browser serialization/cancellation. This is application-level verification, not an external security certification or a physical authenticator/device compatibility certification.

Authentication notification emails use the current company name and configured storefront palette. All templates select the optional email logo, falling back to the store logo and then the store name. The private logo is embedded as a MIME image; no authenticated image URL is required. Laravel's shared mail-channel adapter applies this to staff/customer invitations, verification and password resets without changing notification classes or their signed URLs. HTML and plain-text messages retain the original action URL. Existing delivered emails remain unchanged; resend an invitation to receive the new branding. See [email-logo settings](company-settings.md#email-logo-a111).

The migration is additive, requires no backfill and preserves existing users/passwords/grants. Run migrations before serving the new code and rebuild the frontend. Rolling back the passkey table removes registered public credentials; staff must use password/mailbox recovery and enroll again. Never roll back to public admin signup on an exposed installation.

Local verification: 89 focused authentication/authorization/audit/customer-login tests (2,360 assertions), 10 identity/architecture checks (6,764 assertions), 21 authentication/passkey frontend tests, TypeScript, ESLint (one existing storefront hook warning), Prettier, Pint, Vite build, changed-file PHPStan, Composer validation/audit and `git diff --check` pass. Full frontend: 335 pass, one pre-existing GeneralSettings label test fails (reproduced on HEAD). Full PHPStan has pre-existing errors in unrelated code (42 reproduced on HEAD; this slice also fixes the staff login callback type). Broader backend checks found existing region-fixture failures in CustomerTest/CustomerSelfServiceTest and dictionary parity gaps; those files are outside this change. The default `make check` service startup collides with the existing cms-foundation Mailpit port, so checks ran against that running project. Browser review confirmed the Greek admin passkey button and unauthenticated registration denial. Physical device enrollment and external email delivery were not exercised; notifications were faked in tests.

## Historical Phase 1 baseline

Phase 1 baseline implemented 2026-10-08. Phase 2 now adds persisted staff authorization and localization without replacing the browser identity/reset/verification architecture. No customer, commerce, API or profile editing exists. Current access rules: [authorization](authorization.md); locale rules: [localization](localization.md).

## Architecture

Use Laravel's `web` session guard, Eloquent provider and supported password broker/verification facilities. `App\Identity\Domain\Model\User` and UserId are plain PHP, not Laravel authentication records or Customers. `App\Identity\Infrastructure\Persistence\UserRecord` maps the existing users table and implements Laravel authentication/verification; UserMapper returns plain Domain identities. Framework-free Application/Command handlers depend on Domain repositories and Application/Contract ports; Infrastructure adapters own Laravel Registered/PasswordReset events and the broker. Context browser controllers, Form Requests and authentication URL/rate policy live in Identity/Presentation/Http; global Inertia middleware remains in App/Http. Admin is presentation. [Context-first DDD](layered-ddd.md) (A29) supersedes A28; routes, database schema, credentials and security behavior are unchanged.

The existing Phase 0 foundation is not a fresh starter-kit project. Integrate the [supported Laravel authentication facilities](https://laravel.com/docs/13.x/authentication), [password broker](https://laravel.com/docs/13.x/passwords) and [email verification](https://laravel.com/docs/13.x/verification) without importing a starter kit's unrelated profile, two-factor, settings or UI framework. No Fortify, Sanctum, Passport or custom cryptography is needed for this slice.

## Routes and flow

| Method     | URL                                | Contract                                                                   |
| ---------- | ---------------------------------- | -------------------------------------------------------------------------- |
| GET / POST | `/login`                           | Guest form / rate-limited session login                                    |
| GET / POST | `/register`                        | Guest form / rate-limited registration                                     |
| POST       | `/logout`                          | Authenticated, session-checked, CSRF-protected logout                      |
| GET / POST | `/forgot-password`                 | Guest recovery form / generic response                                     |
| GET        | `/reset-password/{token}`          | Guest reset form, email in query string                                    |
| POST       | `/reset-password`                  | Guest broker-based reset, password confirmation                            |
| GET        | `/email/verify`                    | Authenticated verification notice; verified accounts continue              |
| GET        | `/email/verify/{id}/{hash}`        | Authenticated owner, expiring path signature, rate limited                 |
| POST       | `/email/verification-notification` | Authenticated verification resend, rate limited                            |
| GET        | `/access-pending`                  | Verified identity without a staff grant; explains access and offers logout |
| GET        | `/admin`                           | `auth`, `auth.session`, `verified`, `can:access-admin`                     |

Registration sends Laravel's Registered event (one verification notification), signs in and rotates the session. Login rotates the session and sends unverified identities to verification; verified staff enter `/admin`; verified non-staff see access pending. Authenticated users cannot use guest-only forms. Logout invalidates the session, rotates CSRF and clears encrypted Inertia history. GET logout is not supported.

Redirects use fixed named local destinations, not user-controlled return URLs or arbitrary intended URLs. When opening a verification link in another signed-out browser, sign in and reopen the email link. Links expire after 60 minutes; resend is available. Verification checks the authenticated identity and email hash as well as the Laravel signature. Email URLs use canonical `APP_URL`, never the incoming Host header; verification uses matching relative-signature generation/validation. Set APP_URL correctly and serve HTTPS in production.

## Admin grant (Phase 2 supersedes the interim allowlist)

Preserve A21: registration and verified email are not administration grants. Phase 2 supersedes A25 with active verified identity plus persisted admin.access capability behind the unchanged access-admin gate. ADMIN_STAFF_EMAILS/config/admin.php are no longer used. Explicit operator bootstrap grants an existing verified identity; no first-user shortcut, hardcoded privileged email, public grant endpoint or seeded password exists. [Authorization](authorization.md) owns bootstrap, recovery, revocation and super-admin rules. Non-staff login still leads to access pending; direct admin access is forbidden. Module navigation is never an authorization grant.

## Persistence and session strategy

- `users`: bigint identity, name (120), normalized unique email (254), password hash, nullable verified timestamp, remember token and UTC timezone-aware timestamps. PostgreSQL CHECK enforces lowercase/trimmed email; uniqueness resolves concurrent attempts. Persist only validated name/email/password on registration; hide password/remember token from serialization.
- `password_reset_tokens`: Laravel's email-keyed broker storage, hashed token and created timestamp. No identity FK is imposed because broker cleanup/recovery owns these short-lived records. No customer, permissions or database-session tables are added.
- Redis encrypted sessions retain Phase 0's HttpOnly, SameSite=Lax, 120-minute idle lifetime. Production requires secure cookies/HTTPS; local HTTP alone uses `SESSION_SECURE_COOKIE=false`. Laravel's optional remember cookie is supported; it is not an API token.
- Seed the framework's HMAC password-session fingerprint on login/registration. `auth.session` checks authenticated routes, invalidating old-password sessions on their next protected request. Reset rotates remember tokens, changes the hash, emits PasswordReset and does not auto-login or verify email.
- Reset links are 60-minute, single-use, email-bound tokens stored as hashes through Laravel. Broker issuance throttles the same account for 60 seconds. Schedule `auth:clear-resets` during production operations to prune expired rows; no scheduler infrastructure is introduced here.

## Security decisions and review

Use Argon2id through Laravel, 64 MiB memory / four iterations / one thread by default; reduced cost exists only in forced test configuration. Require 15–128-character passphrases with confirmation on registration/reset, allowing spaces/Unicode rather than arbitrary composition rules. This follows [OWASP authentication guidance](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html); production hash cost and abuse controls must be load-tested before release. No password confirmation endpoint is needed because this phase has no authenticated sensitive editing operation.

Limits per minute: login 5, registration 3, forgot password 3 and reset 5 per normalized-email/IP fingerprint, plus 20 per IP **per endpoint**, preventing identity rotation from bypassing every limit. Verification/resend share 6 per authenticated identity. Fingerprints hash email/IP instead of retaining raw emails in limiter keys. Redis backs runtime limiters; tests use array cache. Limits count all submissions, not just failures. They are deliberately configurable in one provider; distributed attacks may require deployment-level controls later.

Review completed against:

| Risk                          | Control / evidence                                                                                                                                                                          |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| CSRF                          | Unmodified Laravel web protection, no auth exemptions; production-mode tests check rejection and valid-token logout                                                                         |
| Session fixation/logout       | Regeneration/invalidation/CSRF rotation tests; no GET logout                                                                                                                                |
| Stale sessions                | Password fingerprint middleware plus remember-token rotation; changed-password session test                                                                                                 |
| Password/token exposure       | Laravel hashing/broker, hidden model fields, explicit shared projection; passwords and reset `token` excluded from flashed input; form secrets reset after submission                       |
| Browser history/cache         | Inertia history encryption enabled, logout clears its key/history; private no-store responses and no-referrer policy                                                                        |
| Validation/mass assignment    | Form Requests, normalized email, length/confirmation rules, explicit persisted fields and database constraints                                                                              |
| Authorization                 | Verified identity plus explicit staff gate, default deny; no customer grants or frontend authority                                                                                          |
| Open redirects/Host poisoning | Fixed redirects, canonical security email URLs, production trusted-host middleware; hostile intended URL/Host tests                                                                         |
| Enumeration/abuse             | Generic invalid-login/reset responses and request throttles; registration uniqueness still provides conventional account-existence feedback, not a claim of complete enumeration resistance |
| Injection/XSS                 | Bound Eloquent queries, React escaping, no raw HTML rendering or custom cryptography                                                                                                        |

Inertia shares only app name, user ID/name/email/verified boolean, the admin-entry capability, flash status, framework validation errors and relative links. Navigation and environment are admin page props; environment is exposed only in local/staging. DevTools prop recording remains disabled. Never log request bodies, cookies, passwords or reset/verification URLs; redact token-bearing paths/query strings in production proxy access logs. Mailpit is local-only, not a production delivery provider.

## Verification and open work

Backend coverage includes registration/injection/validation, login/invalid credentials/throttles, logout and session rotation, guest/verified/non-staff boundaries, reset notification/hash/expiry/reuse, signed verification ownership/expiry/tampering/resend and CSRF. Frontend tests use the real Inertia form state with a stubbed transport to exercise pending/errors/secret clearing, reset confirmation/token, resend, logout and navigation/dialog controls. A test-only jsdom dialog shim does not substitute for native browser keyboard review.

Desktop and 375px mobile browser review used an isolated local server/test identity/test database, real Redis sessions and Mailpit. Mobile Escape returns focus to Menu. No default staff credentials or fake business data are seeded.

Phase 2 implements persisted grants, permission tooling and explicit operator bootstrap/recovery. Still open for release: mandatory MFA policy, branded multilingual mail/deliverability/provider and production deployment/cookie/retention/monitoring controls. Profile editing remains deferred. No ecommerce implementation is authorized by these documents.

## Customer accounts (A99)

Staff and customers share the `users` identity and the `web` session guard (A21) but use separate pages:

| Area | Pages | Route names |
| --- | --- | --- |
| Staff | `/admin/login`, `/admin/register`, `/admin/forgot-password`, `/admin/reset-password/{token}`, `/admin/email/verify…`, `/admin/logout`, `/admin/access-pending` | `admin.login`, `admin.register`, `admin.password.*`, `admin.verification.*`, `admin.logout`, `admin.access.pending` |
| Customer | `/login`, `/register`, `/forgot-password`, `/reset-password/{token}`, `/email/verify…`, `/logout`, `/account`, `/account/profile` | `login`, `register`, `password.*`, `verification.*`, `logout`, `account`, `account.profile` |

- `AuthArea` decides the area from the path (`admin`, `admin/*`). Guest redirects, signed-in redirects, the inactive-identity logout and the `links` shared with the auth pages follow it, so Login, Forgot/Reset password and Verify e-mail are one set of pages for both areas. Staff admin routes use `verified:admin.verification.notice`.
- `SessionLifecycle` holds the session hardening for both areas (regeneration, password fingerprint, intended-URL removal, audit events, logout invalidation and history clearing).
- Customer passwords: 8–128 characters. Staff: 15–128 (A26). The reset page applies its area's rule.
- `users.staff_password` is recomputed by `UserRecord` whenever a plain password is set (registration, reset, staff administration; rehashed values keep it). `access-admin` requires it, so an identity with a customer-length password cannot enter administration even if a role is granted; the access-pending page explains the reset. Existing identities were all created under the staff rule and start `true`.
- Reset and verification e-mails link to customer pages for customer identities (linked or pending profile) without `admin.access`, and to staff pages otherwise.
- Rate limits (`login`, `register`, `password-email`, `password-reset`, `verification`) apply to both areas; profile completion is limited to 10 per minute. All auth and account pages are `noindex`.
