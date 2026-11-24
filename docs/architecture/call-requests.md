# Call requests

The authorized admin slice adds **Orders → Call requests** at `/admin/call-requests`. The Customer context owns this pre-order contact workflow; its placement under Orders is navigation, not order ownership. This is an independent implementation using the CMS's existing table, form, filter, selection, pagination and translation components.

## Workflow

Staff can create a request manually, open its details, edit contact information and notes, assign a responsible staff member, and change its status. Existing request editors show the permitted Delete trash icon immediately left of Save, with the shared confirmation dialog; there is no separate bottom delete action. The four statuses are New, In progress, Completed and No answer. Status and responsible staff can also be changed directly in the list. Name, preferred call time, notes, responsible staff and related order/products are optional; phone is required and follows the existing General settings phone policy.

The list searches by ID, name or normalized phone, filters by status, responsible staff and requested dates, and supports database pagination and deterministic sorting. Dates use the store timezone. Each request occupies one table row. Notes are fetched as a maximum 160-character preview, visually truncated and linked to the detail screen; that screen receives the full plain-text notes (up to 10,000 characters).

Optional product context is captured at creation from active, undeleted catalog products. Product names and SKUs come from the server and remain historical snapshots, with quantity and ordering. Product context is read-only after capture and survives product deletion. The optional order number resolves to an existing order on the server, retains a number snapshot and shows the current order status. Neither link changes order contents, stock, sale quotas, pricing, payment state or sends an email.

## Integrity and authorization

`call-requests.view`, `call-requests.manage` and `call-requests.delete` are separate persisted capabilities, checked on the server. View-only staff can read the full request without editing it. New assignment requires active, verified staff with admin access and view/manage capabilities. An existing unavailable assignment remains visible and can be cleared or replaced.

Updates and single/bulk deletion require the saved optimistic version. Stale edits are refused. Bulk deletion validates and locks every selected request in ID order before deleting anything; a stale member rolls back the entire batch. Selection targets only visible rows on the current page. Deletion is permanent and removes captured product context in the same transaction.

PostgreSQL constrains statuses, versions, phone digits, quantities, positions and foreign keys. Related order/product/staff deletion clears live references while retaining relevant historical text. The migration seeds no sample contacts.

Audit captures status, assignment and order/product identifiers and quantities. It deliberately excludes caller name, phone, preferred time, notes, and captured product names/SKUs, including automatic entity labels. An optional `private` trigger argument suppresses those labels while retaining the central transactional audit behavior for other tables.

Run migrations and ordinary `cms:permissions-sync`, then build frontend assets. Ordinary permission sync registers capabilities without changing existing role grants. Newly created reference Store Admin and Order Manager roles include these capabilities; existing roles can receive them explicitly through Roles or the existing opt-in commerce-default grants.

## Storefront callback (A112)

The identifier block has **Show call request icon**, off by default for both existing and new layouts. When enabled, a phone icon immediately to the right of the account icon opens the same native dialog used for adding account addresses. The form has an optional name, a required phone, an optional customer-facing order number and an optional same-day preferred time range, initially 09:00–20:00. The public operation validates both times as HH:mm with end after start, then stores the range in the existing `convenient_time` field. Clearing both times leaves it unset. Phone normalization and the General settings phone policy are shared with staff creation.

`POST /call-requests` accepts only these contact fields and the saved identifier block ID. The server requires an open store and a saved, enabled call-request option on a referenced identifier block, including per-page content overrides. The web CSRF middleware remains in force. A hidden honeypot returns the ordinary acknowledgement without storing a request; the route limits visitors to six attempts per minute through the existing Laravel per-client limiter. Responses disclose no request ID or other caller's data. The modal preserves input and shows field errors, throttling and network failures, prevents duplicate submits while pending, and acknowledges successful submission.

The Customer context owns `CallRequests::submit`, sharing contact validation with staff administration while forcing New, unassigned requests. The public boundary cannot assign staff, set status, link products or write staff notes/actor IDs. An optional positive order number is retained as a caller-supplied reference; the server links an existing order without disclosing whether it exists, and retains unrecognized numbers for staff to verify. This does not establish order ownership or change the order. Staff see and manage the resulting request through the existing permissions and optimistic workflow. Existing private transactional audit rules continue to exclude caller details. No schema migration or permission synchronization is required; rebuild frontend assets. Automatic calls and notifications remain outside this feature.
