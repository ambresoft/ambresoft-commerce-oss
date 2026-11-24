# Social login providers — administration only (A92)

Website → Social login configures the sign-in providers a store will offer its customers. The providers configured here power the customer sign-in button (see below). Only **Google** is supported; the provider catalog is a single class, so adding another provider later means declaring its fields, not changing the schema.

The behavior reference is CS-Cart's social login providers screen (a provider list with a "New provider" dialog: *Provider*, *Name*, an instruction to use the ID and secret created in the provider's project, *ID*, *Secret key*, *Status*, and a *Callback URL* tab). The schema, code and wording are our own.

## What an administrator can do

- List providers with their status and whether credentials are complete; switch one on or off from the list.
- Create or edit a provider: Provider (Google), Name, Client ID, Client secret and Status. The Client ID must be a Google client ID (`….apps.googleusercontent.com`); both credentials are required.
- Read the **Callback URL** tab: the address to register as an authorized redirect URI in the provider's console (for Google: Google Cloud Console → APIs & Services → Credentials → OAuth client). It is derived from `APP_URL` as `/auth/social/{provider}/callback`; the storefront serves that path (A102).
- Delete a provider, which removes its stored credentials.

## Rules

- **Secrets** reuse the shared setting-field contract (`FieldValues`): the client secret is encrypted with the application key in `social_login_providers.secrets`, never sent to the browser (the editor only learns that one is stored), kept when the field is left empty and replaced when a new value is typed. It is never captured by the audit log.
- **One active provider per type.** A partial unique index (`WHERE is_active`) gives the storefront one unambiguous client per provider; a second active Google provider is refused until the first is disabled. Names are unique case-insensitively.
- **Stale edits** are refused through `lock_version`, as for other administered records.
- **Authorization:** `social-login.view` (list, open) and `social-login.manage` (create, edit, switch, delete) are persisted capabilities enforced by the server; the navigation entry appears only with `social-login.view`.
- **Audit:** `social_login_providers` is captured by the central audit trigger (provider, name, status and the public client ID) — see `AuditTables::socialLogin()`.

## Architecture

`Website/Infrastructure/SocialLoginProviders` is the provider catalog (fields, validation, callback and console URLs); `SocialLoginAdministration` owns validation, authorization, locking and audited transactions; `SocialLoginQueries` owns the read models. `Admin/Controllers/SocialLoginController` and `resources/js/pages/Admin/SocialLogin/{Index,Form}.tsx` are the presentation. Migration `2026_10_19_000100_create_social_login_providers.php` adds the table, its integrity constraints and the audit trigger. UI texts are keyed in `lang/{locale}/social_login.php` for all six shipped languages.

## Not included

## Customer sign-in with Google (A102)

An active provider with complete credentials adds a **Continue with Google** button to the customer `/login` and `/register` pages (`SocialSignIn::buttons()`, shared as `socialProviders`); nothing shows otherwise. `GET /auth/social/google/redirect` starts the authorization-code flow (one-time `state` and PKCE `S256` kept in the session) and `GET /auth/social/google/callback` completes it. The token exchange and profile read happen server side with the stored secret; Google must report `email_verified`.

The account is the shared `users` identity matched by e-mail, so no link table exists: a new customer is created already verified (no password is known; a reset sets one) and signed in; an existing customer is signed in; an existing but unverified registration is verified and its password replaced, so whoever pre-registered the address cannot use it. Staff identities and deactivated users get the neutral failure message, and a disabled customer profile is refused as on `/login`. Administration is unaffected (`/admin` still needs a staff role and the staff password minimum).

## Not included

No additional providers (Facebook, Apple…), per-store providers or "test connection" — each needs the storefront or a separate authorization.
