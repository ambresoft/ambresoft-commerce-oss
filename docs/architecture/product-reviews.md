# Product reviews and ratings (A98)

Implemented scope: review persistence, the customer submission operation, verified-purchase determination, admin moderation (Catalog → Reviews), live rating summaries and a public approved-review projection for the future storefront. **The storefront is explicitly deferred**: no public route, page, widget or form exists, and no customer sign-in exists to protect one (A71).

## Model (migration `2026_10_27_000100_create_product_reviews`)

`product_reviews` — one row per customer and product:

| Column | Integrity |
| --- | --- |
| `product_id` | FK `products`, `ON DELETE CASCADE` (only permanent deletion; trashed products keep their reviews) |
| `customer_id` | FK `customers`, `ON DELETE CASCADE` (customers are never deleted today; a future erasure removes their content) |
| `rating` | `smallint`, CHECK 1–5 |
| `title` | optional, ≤150, non-blank when present |
| `body` | required, non-blank, ≤5000 |
| `status` | `pending` (default) / `approved` / `rejected` / `spam`; CHECK; a non-pending row must have `moderated_at` |
| `verified_purchase` | server-calculated boolean |
| `moderation_note` | internal, ≤2000, never in a public projection |
| `moderated_by`/`moderated_at` | last status change (`users`, `SET NULL`) |
| `edited_by`/`edited_at` | last staff correction of rating/title/text |
| `lock_version`, timestamps | optimistic concurrency |

Indexes: `UNIQUE (product_id, customer_id)`; `customer_id`, `moderated_by`, `edited_by`; `(created_at, id)` and `(status, created_at, id)` for the admin list; partial `product_reviews_approved (product_id, created_at DESC, id DESC) INCLUDE (rating) WHERE status = 'approved'` for summaries and public lists. No product names, prices or customer details are copied onto reviews.

The central audit trigger captures product, customer, rating, status, verification, moderator and editor fields. The customer's title and text and the internal note are deliberately excluded (`AuditTables::reviews()`). Bulk moderation also writes `review.bulk_completed` with success and failure counts.

## Ownership

| Layer | Types |
| --- | --- |
| Domain | `Catalog\Domain\Model\ReviewStatus` (transitions), `Catalog\Domain\Service\ReviewRules` (text rules, summary arithmetic, public author label) |
| Infrastructure | `ProductReviews` (the only write path), `ReviewPurchases` (verification), `ProductRatings` (public summaries and approved list), `ProductReviewQueries` (admin projections) |
| Admin | `ProductReviewController`, `Reviews/Index`, `Reviews/Edit` |

Reviews belong to Catalog because they describe a product record; Catalog → Reviews sits next to Categories.

## Submission (`ProductReviews::submit($customerId, $productId, $input)`)

- The caller passes a **verified customer identity**. Since A99/A100 the HTTP entry point is `POST /products/{product}/reviews` for signed-in customers with an active linked profile (session + CSRF, 6 per minute); the customer comes from the session, never from the request. The account's *Reviews* page uses it, and the storefront product page will too.
- Only `rating`, `title` and `body` are read. Status, verification, moderator, customer and product fields in the payload are ignored, so mass assignment is impossible.
- Rating: whole number 1–5 (ints or digit strings; `4.5`, `"3.0"`, booleans and blanks are refused). Text: trimmed, CRLF normalised, must contain a letter or digit, no control characters (the body keeps line breaks; the title is one line). Text is stored as typed and must always be rendered as text (React escaping); no rich text is accepted.
- Eligible product: exists, not trashed, status Active or Hidden (Hidden products remain purchasable by direct link). Disabled customers cannot submit. Both rows are share-locked for the transaction.
- New reviews are always Pending.
- **One review per customer and product.** An application check returns a clear `reviews.duplicate` error. The unique key decides concurrent submissions, and a losing insert (`23505`) is translated into the same error (`ProductReviewConcurrencyTest`).
- A later customer edit operation can reuse `content()` validation and the version check; it is not built.

## Verified purchase

`ReviewPurchases` reuses Reporting's single **sale** definition (A94, `ReportOrders::paid`): captured payments cover a positive order total and the current status is not void (final + restore stock: cancelled, failed or any merchant status configured that way). The order must belong to the customer (`orders.customer_id`) and contain the **exact** product in its lines. Any currency counts. Simply placing an order, an uncollected cash-on-delivery order, a partly paid order, a cancelled order and a guest order with the same e-mail do not qualify.

The flag is computed at submission and **re-computed on approval**, so the public badge reflects the orders when the review is published. `verifiedPairs()` answers many pairs in one query.

Limitations of the current data: the CMS stores no refunds (A94), so refunds cannot remove verification. A paid order that is later cancelled stops qualifying through its void status. A change to an order after approval is not pushed back to already-approved reviews; moving the review through Rejected → Pending → Approved refreshes it. Orders of zero total (fully discounted) are not sales and do not verify.

## Moderation

| From → to | Allowed |
| --- | --- |
| Pending → Approved / Rejected / Spam | yes |
| Approved → Rejected / Spam | yes |
| Rejected → Spam / Pending | yes |
| Spam → Pending | yes |
| anything else (including the same status) | refused with a translated error |

Each change records `moderated_by`/`moderated_at`, bumps `lock_version` and requires the current version. Staff may correct rating/title/text (records `edited_by`/`edited_at`, keeps status) and keep an internal note. Deletion is permanent, needs the current version, is confirmed with `ConfirmDeleteDialog` and is audited. Bulk Approve/Reject/Spam handles at most 100 selected current-page reviews; each one runs in its own transaction with its own authorization and transition check, and the list shows how many changed plus each refusal.

Capabilities (registered by `cms:permissions-sync`, no default role grants): `reviews.view` (list/detail), `reviews.moderate` (status changes, bulk, internal note), `reviews.edit` (rating/title/text corrections), `reviews.delete`. Customer e-mail is shown and searchable only with `customers.view`. Controllers and the service both authorize, so hidden buttons are not the protection.

## Rating summaries

`ProductRatings::summaries($productIds)` returns, per product, `count`, `average` (string with one decimal, exact integer half-up rounding, `null` when there are no approved reviews) and `distribution` (1–5 star counts). One grouped query serves any number of products; the review count does not change the query count. `summary($id)` is the single-product form.

Only Approved rows count. Summaries are **live aggregates**, not stored totals, so approval, rejection, spam, rating edits, deletion and permanent product deletion are reflected on the next read with nothing to invalidate. A denormalised counter or cache can be added later behind the same methods if volume requires it.

`ProductRatings::approved($productId, $page, $perPage ≤ 50)` returns approved reviews newest first. Each has only `id`, `rating`, `title`, `body`, `author` ("Maria E.", never the full name or e-mail), `verified_purchase` and `created_at`. Callers apply catalog visibility (trash, Disabled, Hidden) before showing a product, as for all product data.

## Products and variations

Variations are complete products (A66). A review belongs to the exact product record that was reviewed, verification requires that exact product on an order line, and summaries are per product record. Nothing is attributed to siblings or rolled up to a variation group. A group-level aggregate can be built later from `summaries()` of the members if the storefront wants one.

## Verification and deferred work

Tests: `ProductReviewTest` (submission, validation, forged fields, duplicates, product/customer eligibility, verified purchases against paid/unpaid/partial/cancelled/guest orders, re-verification on approval, transitions, permissions, list filters/search/sort/pagination, e-mail privacy, staff corrections, bulk outcomes, deletion and audit, constraints, summaries after every change, single-query batch summaries, public projection), `ProductReviewConcurrencyTest` (unique-key wait and lost-race translation), `ReviewRulesTest`, `Reviews.test.tsx`.

Deferred to the storefront phase: customer sign-in and the A21 link, public routes/controllers with CSRF and throttling, product-page widgets, review forms and lists, customer editing of their own review, notifications, images, voting, merchant replies and anti-spam integrations.
