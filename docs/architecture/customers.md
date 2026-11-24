# Customers — Phase 9

Phase 9 adds administrator-facing customer management, following CS-Cart's broad model (profiles, addresses, user groups) without its code or schema. Decision A71.

## Scope and limits

Implemented: customer profiles, multiple addresses with default billing/shipping, customer groups, disable/restore, tax-exemption flag integrated with Phase 8, admin screens, capabilities, audit capture and tests.

Not implemented (later phases): customer sign-in/registration and the storefront account area, the A21 link to `users`, order history, checkout, payments, shipping and returns. A disabled customer is only a status today; when customer sign-in exists it must refuse disabled customers. Staff cannot delete customers; a customer can soft-delete their own account (see Account deletion). Retention/erasure rules belong with orders.

## Ownership

| Layer | Types |
| --- | --- |
| Application | `CustomerAdministration`, `CustomerGroupAdministration` ports; `SaveCustomerData`, `SaveAddressData`, `SaveCustomerGroupData` |
| Infrastructure | `EloquentCustomerAdministration`, `EloquentCustomerGroupAdministration`, `CustomerInput`, `CustomerGroupInput`, `CustomerQueries`, `CustomerServiceProvider` |
| Tax integration | `Tax\Application\Contract\CustomerTaxProfiles`, `Tax\Infrastructure\EloquentCustomerTaxProfiles` |
| Admin presentation | `CustomerController`, `CustomerGroupController`, `Customers/Index`, `Customers/Show`, `Customers/Form`, `CustomerGroups/Index`, `CustomerGroups/Form`, `AddressFields` |

The rules are relational and transactional, so no Domain layer is created yet (A29: only consumed types).

## Relational model (migration `2026_10_13_001900_create_customers.php`)

| Table | Integrity |
| --- | --- |
| `customers` | email `varchar(254)` unique, CHECK `email = lower(btrim(email))`; non-blank names; phone optional; status active/disabled; `tax_exempt`; internal `notes`; `lock_version`; indexes on `(created_at, id)` and `(lower(last_name), lower(first_name), id)` |
| `customer_addresses` | owned by one customer (cascade only if a customer row is ever removed); unique `(customer_id, id)`; country `char(2)`; region `^[A-Z0-9]{1,10}$` optional; postal code optional |
| defaults | `customers.default_billing_address_id`/`default_shipping_address_id` → `customer_addresses (customer_id, id)`, `ON DELETE SET NULL` of that column only |
| `customer_groups` (+ translations) | status; `is_default` partial unique and CHECK default ⇒ active; names unique per language ignoring case |
| `customer_group_members` | PK `(customer_id, group_id)`; group `RESTRICT` |

The migration is additive and reversible; it touches no staff account and creates no customer.

## Behavior

- **Emails** are trimmed and lower-cased for every caller and must be unique among customers; duplicates are refused, never merged.
- **Status**: create is active; disable/restore needs `customers.status`, a confirmation in the UI and the current version; repeating the same status is refused.
- **Addresses**: any number; none required. The first address becomes both defaults; defaults change only when chosen and must be the customer's own; deleting a default falls back to the oldest remaining address (or none). Address IDs from another customer are 404. Country comes from the shared ISO list; region and postal code are optional because not every country uses them; no region catalog exists, so regions are validated by format only and matched exactly by tax rate areas.
- **Groups**: many-to-many. Creation without an explicit choice joins the default group (if any); an explicit empty choice means no group. Disabled groups stay on members but cannot be newly assigned. Moving the default flag is atomic. Deleting: the default group is refused; a group with members needs an active target group and members move there first (existing memberships kept once); profiles of moved customers advance their version. Membership does not change prices.
- **Concurrency**: every customer change, including address changes, runs in one transaction under the customer's optimistic version; customer and group writes share an advisory lock so a membership cannot race a group being disabled or deleted.
- **Privacy**: no credentials exist on customers; admin payloads carry only profile fields; the audit ledger captures status, exemption, defaults, memberships and address country, not names, email, phone, notes or address lines.

## Admin

Customers (sidebar group with Customer groups): list with search (name, email, phone digits), status and group filters, sorting by created/name/email with ID tie-break and 25-row pagination; detail page with profile, account/groups/tax, addresses (add/edit/delete/make default) and notes sections, leaving room for order history later; create/edit form with groups, tax exemption, notes, default address selection and an optional first address created in the same transaction.

## Tests

`CustomerTest` (create/edit, validation, normalization and uniqueness, disable/restore, list search/filter/sort/pagination, capabilities, secrets and audit privacy, addresses and defaults, fallback, invalid and forged addresses, transactional first address, tax integration, migration round trip), `CustomerGroupTest` (CRUD, default rules, memberships, disabled groups, safe deletion, capability), `Customers.test.tsx`, plus the existing regression suites.

## Blacklist (Phase 9 follow-up)

Emails and phone numbers that must not place orders. Migration `2026_10_13_002000_create_customer_blacklist.php` adds `customer_blacklist_entries`: `kind` email/phone, normalized `value`, mandatory `reason`, optional source `customer_id` (`SET NULL`, so deleting the record never lifts the block), creator, and `released_at`/`released_by`. Entries are released, not deleted, so reason and history remain; a partial unique index allows one active entry per value and a released value can be added again.

- **Normalization** (`ContactNormalizer`, mirrored in SQL): emails trimmed and lower case; phones reduced to digits with an international `00` prefix removed (5–15 digits). No country is inferred, so national and international forms are different values and both can be listed.
- **Existing customers** are flagged by computation, not a stored flag: active entry matching the customer's email, profile phone or any address phone. The list shows a badge and a Blacklisted yes/no filter; the detail page shows a banner with each matching value, reason, who added it and when, and a remove action. Blacklisting does not disable the customer or change status.
- **From a customer** the stored email and/or phone are used (never request values) with a required reason; the **Blacklist** page (Customers group) adds arbitrary emails/phones, searches, filters active/removed and lifts entries.
- **Enforcement foundation:** `CustomerBlacklist::match($email, $phone)` returns the first active match (kind and reason) for the future checkout/order creation, which must refuse it. No order flow exists yet, so nothing is blocked in a purchase today.
- **Authorization:** `customer-blacklist.view`, `customer-blacklist.manage` (blacklisting from a customer also needs `customers.view`). The audit ledger records kind, source customer and release only; values and reasons are personal data and are not captured.

## Sign-in accounts (A99)

Migration `2026_10_28_000100_add_customer_accounts` adds `customers.user_id` (nullable, unique, `users` `SET NULL`) with `account_linked_at` (CHECK: both set or both null), `customer_registrations` (pending first/last names per identity, cascade with the identity) and `users.staff_password`. The customers audit trigger now also captures `user_id`; pending names are not audited.

`CustomerAccounts` (Infrastructure) is the only writer of the link and runs under the customer advisory lock (2142514) shared with staff customer and group edits:

- **Register**: identity + pending names in one transaction; the verification e-mail is sent after commit.
- **Activate** (e-mail verified, or a verified identity signing in): already linked → nothing; an unlinked profile with the identity's e-mail → claimed (version bumped, staff-entered names, addresses, groups and orders kept); otherwise a new active profile from the pending names, joining the default group. An e-mail whose profile belongs to another identity is never taken over.
- **Complete**: a verified identity with no pending names (for example a staff member shopping) gives a first and last name once.
- Disabled profiles are refused at `/login` and `EnsureCustomerAccount` ends their open sessions on the next account request. Staff disable customers; only the customer deletes their own account (a soft delete).

Not yet built: customer self-service editing (profile, addresses, password change), an admin indicator or invitation for accounts, the storefront account area, and review submission through the signed-in customer.

## Self-service, invitations and reviews (A100)

- **Account area** (`/account`, `/account/orders`, `/account/addresses`, `/account/reviews`, `/account/password`): the **Account layout** (`account` page type, edited under Layouts like the others), whose protected *Main content* block shows the open section beside a menu: details (name and phone; e-mail is read-only) with account deletion, order history, address book (add, edit, delete with confirmation, default billing/shipping), reviews and password change (current password required; other sessions end through the password fingerprint). `AccountPageController` renders the page and `Storefront\Infrastructure\AccountPage` prepares each section's data; mutations stay on the `CustomerSelfService` routes. `EnsureCustomerAccount` guards every page; `CustomerSelfService` resolves the profile from the session, takes the customer lock 2142514, checks `lock_version` and refuses disabled profiles. Countries are limited to shipping countries (all countries while none is configured). Foreign address IDs are 404. The pages are private (`Cache-Control: no-store`, never indexed).
- **Order history**: a table of the customer's own orders (`orders.customer_id`), newest first, 10 per page: number, date, status (in the current language), units and total formatted in the order's own currency (`OrderMoney`). There is no page for a single order yet.
- **Addresses**: a postal code is required (the rule is shared with staff and order addresses; the database check is `NOT VALID`, so older rows without one stay readable and the rule applies when they are saved). Saved addresses also hold delivery details: `floor` (20), `bell_name` (100) and `notes` (500). They are personal data (not audited) and are not copied into order addresses yet; there is no storefront checkout.
- **Account deletion**: `DELETE /account` is a **soft delete**. The customer types their e-mail address; the profile gets `deleted_at` and `status = disabled` in one write (a CHECK keeps a deleted profile disabled) and the session ends. Nothing is removed: orders, addresses, reviews, lists and the sign-in identity stay as they were, and the e-mail stays reserved. Every active-customer check (sign-in, `EnsureCustomerAccount`, storefront customer lookup) already refuses it; sign-in says the account was deleted. Staff see *Deleted* in the customer list and detail (the deletion is audited) and bring the account back by activating the profile, which clears `deleted_at`. Erasing personal data for a legal request is a separate decision.
- **Address rules** live in `CustomerAddressBook`, shared with `EloquentCustomerAdministration`: first address becomes both defaults, deleting a default falls back to the oldest remaining address.
- **Staff visibility**: the customer page has an *Online account* card (linked since …; invited/registered awaiting verification; verified identity that attaches at its next sign-in; none) and the list has an *Online account* filter and badge.
- **Invitations** (`customers.update`): `CustomerInvitations::send` creates an unverified identity with an unusable password for an active, unlinked customer without a verified identity, and e-mails `CustomerInvitation` with `/account/invitation/{user}/{hash}`, signed for 7 days; the hash is an HMAC of e-mail and password hash, so the link works once. Accepting sets the password (8–128), verifies the e-mail, claims the profile and signs in. Re-sending reuses the same identity. `customer.invitation_sent` is audited.
- **Reviews**: `/account/reviews` lists the customer's reviews as published / awaiting approval / not published (moderation reasons stay internal) and the reviewable products from their paid, non-void orders (`ReviewPurchases::purchasedProducts`). Forms post to `POST /products/{product}/reviews` (6 per minute), which calls `ProductReviews::submit` with the session's customer; every rule of [product reviews](product-reviews.md) applies.

## Wishlists (A106)

Signed-in customers keep private wishlists (`wishlists`, `wishlist_items`; migration `2026_11_01_000100_create_wishlists`). Each customer has one **general list** ("All favourites", `is_default`, unique per customer by a partial index, no name of its own) and up to 50 **named lists** (case-insensitive unique names, 1–60 characters, no control characters or angle brackets). The general list holds every saved product: saving to a named list also saves to the general list, and removing from the general list removes the product from every list; removing from a named list only leaves that list. Deleting a named list keeps its products in the general list. A list holds at most 500 products. Guests have no wishlist: a heart sends them to sign in.

Rules live in `Customer\Infrastructure\Wishlists`: every operation names the owning customer and finds lists through it (another customer's list id is a 404), and only products the customer may see (`PublicCatalog::products()`) can be saved. Saved products that later stop being visible stay saved but leave the page and its counts, and return when visible again. `WishlistController` serves JSON for the "add to list" window (`GET /wishlists/product/{product}`, `POST /wishlists`, `PATCH`/`DELETE /wishlists/{list}`, `PUT`/`DELETE /wishlists/{list}/products/{product}`), behind the same verified-customer middleware as the account pages and a rate limit. Wishlists are customer-owned personal data and are not in the staff audit log; they are removed with the customer.

The merchant can switch wishlists off for the whole store (General settings → Catalog → *Enable wishlists*, `general_settings.wishlist_enabled`, on by default; migration `2026_11_01_000400_add_wishlist_enabled_setting`). While off, `/wishlist` and every `/wishlists` endpoint answer 404 (`EnsureWishlistsEnabled`), cards carry no saved state, and the theme hides every heart and the header link; saved lists are kept for when it is switched on again.
