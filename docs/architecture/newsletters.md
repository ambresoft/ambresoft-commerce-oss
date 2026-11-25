# Newsletters — Marketing → Newsletters

Modelled on the CS-Cart newsletter add-on and owned by the Marketing context (decision A91). Subscribers will later
come from the storefront; administration, delivery and the public confirm/unsubscribe pages exist now.

## Menu

Marketing → **Newsletters** (nested group) → Newsletters, Templates, Autoresponders, Campaigns, Mailing lists,
Subscribers. `AdminNavigation::addGroup(id, label, parent)` supports one nested level; the sidebar renders it.

## Concepts

| Entity | Notes |
| --- | --- |
| Newsletter | Locale-linked name, subject, optional random subjects (one per line; each delivery picks one), HTML body. Campaign, audience (active mailing lists + selected registered customers), enabled flag, state `draft` / `sending` / `sent` |
| Template | Name and HTML body per language; "Load" copies a template's body into a newsletter or autoresponder (a copy, never a live link) |
| Autoresponder | Same texts; assigned to a mailing list as its **confirmation e-mail**, sent when someone joins the list unconfirmed |
| Campaign | Name + status; groups newsletters and shows how many messages were sent. Deleting keeps the newsletters |
| Mailing list | Locale-linked name/description, status, optional from name/e-mail and reply-to, confirmation autoresponder, "show at checkout" / "show on registration" (read by the storefront later) |
| Subscriber | One row per e-mail address (case-insensitive unique), language, status `active`/`unsubscribed`, source `admin`/`import`/`storefront`; memberships per list with a confirmed flag |

Bodies are edited with the same rich text editor as content pages and sanitized to e-mail-safe HTML on save.
Variables use `{{name}}` like the order e-mail templates; unknown variables are refused when saving:
`{{unsubscribe_link}}` (list recipients only), `{{activation_link}}` (confirmation e-mails), `{{subscriber_email}}`,
`{{company_name}}`, `{{company_address}}`, `{{company_phone}}`. Values are HTML-escaped before insertion.

## Sending

- **Send** creates one delivery per recipient address (unique per newsletter; autoresponders per list too) with
  `INSERT … ON CONFLICT DO NOTHING`. Recipients are confirmed subscribers of active lists in the audience plus the
  selected customers; unsubscribed addresses are skipped. Sending again only reaches addresses not addressed before.
- Deliveries go through the Redis queue (`SendNewsletterDelivery`); the scheduler command `cms:newsletters-dispatch`
  (every minute) re-dispatches waiting, interrupted (10 min) and failed deliveries (up to 3 attempts, 5 minutes apart)
  and marks newsletters `sent` when nothing is left. A delivery is claimed atomically, so two workers never send one
  message twice.
- Sender: the list the recipient was reached through (from name/e-mail/reply-to), else the store's order e-mail sender
  (Settings → General → Emails). Test messages go to one address and are not recorded.
- Messages use the recipient's language, falling back to the default language when a translation is empty.

## Subscription flow

`NewsletterSubscribers::subscribe(email, listIds, source, confirmed, locale)` is the single entry point (administration
now, storefront later). Admin additions are confirmed by default and send nothing; unconfirmed memberships send the
list's confirmation autoresponder once. Signed public links (`/newsletter/confirm/{subscriber}/{list}`,
`/newsletter/unsubscribe/{subscriber}?list=`) show a page and change data only on POST, so link scanners cannot
confirm or unsubscribe anyone. Unsubscribing from a list removes that membership; a subscriber with no list left becomes
`unsubscribed`.

Confirmation autoresponders use the same responsive branded mail layout as staff invitations: company name,
email logo (store-logo fallback), store colors, a centered card and readable typography. `{{activation_link}}`
renders a localized confirmation button; `{{unsubscribe_link}}` becomes a short localized link. Merchant-authored
content remains editable and is not rewritten in storage. Plain-text messages retain the original signed URLs,
and GET links still only show the confirmation page. Admin previews submit the content type so autoresponders
use the same layout and buttons as real/test deliveries. Ordinary campaign/template layouts are unchanged.
Already delivered messages keep their original appearance. Rebuild the frontend for the updated preview and
restart long-lived queue workers on deployment; no database migration is required.

## Administration

Subscribers: search/filter, add one, add several (paste addresses; up to 5,000), edit memberships and status,
delete, CSV export (spreadsheet formulas neutralized). Capabilities: `newsletters.view`, `newsletters.manage`,
`newsletters.send`, `subscribers.view`, `subscribers.manage` (store admin gets all). Audit capture excludes e-mail
addresses, message bodies and delivery records. `cms:newsletters-demo {actor}` creates sample data in local/testing.

## Not built

Abandoned cart/wishlist audiences (no cart yet), scheduled sending, open/click tracking, bounce handling, one-click
`List-Unsubscribe` headers (the mail transport has no custom headers yet), public subscription forms (storefront phase).
