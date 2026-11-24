# Gift cards

Marketing → Gift cards issues prepaid cards that staff send to a recipient by email and later accept as payment on an order. The list, the editor (General and History tabs, status at the start of the action row, trash icon button, `ConfirmDeleteDialog`) and the permissions follow the coupon screens. The CS-Cart gift certificate screens and the [gift certificates documentation](https://docs.cs-cart.com/latest/user_guide/addons/gift_certificates/index.html) are a behavioural reference, not a source of schema or code.

A gift card is **a way of paying, not a discount**. A coupon changes an order's prices and taxes; a gift card leaves them alone and records a captured payment of its own kind (`order_payments.kind = 'gift_card'`, `gift_card_id`) while lowering the card's balance in the same transaction. Payment status, the method's "status after payment", reports and sale dating therefore work exactly as for any other money received. Selling a gift card as a product, "free products" bundled with a card and postal delivery are not part of this phase; delivery is by email only.

## Lifecycle

| Status | Meaning | Usable |
| --- | --- | --- |
| Pending | Issued, not yet released. | No |
| Active | Released; the code works. | Yes |
| Cancelled | Withdrawn by staff (any balance stays recorded). | No |
| Used | Nothing left. Set by the system when the balance reaches zero, never chosen by staff. | No |

Staff move a card freely between pending, active and cancelled. A used card is final. `gift_cards` enforces the rules in the database: `balance` between 0 and `amount`, and `status = 'used'` exactly when the balance is zero. Codes are `GC-XXXX-XXXX-XXXX` from an alphabet without 0/O/1/I, generated with `random_int`, unique, retried on collision, and never reused (deleted cards keep their code reserved).

A card's face value and balance are in the primary currency at issue and are **never converted**. A card cannot pay an order in another currency. The amount can be edited only while untouched; afterwards the ledger would stop adding up. The balance changes only through redemption. Edits use optimistic versions; deleting archives the card (it can no longer be used, its payments and history stay readable).

## Emails

Delivery is by email only, through the same transport, sender and shared header/footer as order emails, in the language chosen on the card (the four fallback locales translate the customer-facing text). The editor's **Notify customer** option sends the card, with its code, when it is created active or becomes active. Cancelling with the option sends a notice that shows only the last group of the code. **Send email** resends an active card. Each request is a `gift_card_emails` row written with the change and delivered after it commits; a mail problem never undoes a card, is visible on the History tab and is retried by sending again. Text typed by people is HTML-escaped and its braces neutralised so it cannot become a template placeholder.

## Using a card on an order

On the order page, staff with `orders.payments` **and** `giftcards.view` enter the code. The server locks the order, then the card, takes `min(balance, amount due)`, records the payment and the ledger entry and flips the card to used at zero. The order is always locked before the card, so two staff members using one card cannot spend it twice, and the CHECK constraints refuse a negative balance outright. A cancelled (void) order, a pending, cancelled, used or deleted card, a card in another currency and an order with nothing due are refused with specific messages. The order page shows only the last four characters of the code. The redemption route is throttled.

No refund or reversal exists in the payment model yet (captured payments are final), so cancelling an order that a card paid does not restore the balance. That belongs with refunds.

## Privacy

The code is a bearer credential. It is not written to the audit trail: the column is named `number` because the generic audit trigger labels rows by `name`, `label`, `sku` or `code`. Names, email and message are personal data and are also excluded from audit fields. The audit entries record amount, balance, status, currency, language and email state. Do not add a `name`, `label`, `sku` or `code` column to these tables without handling that.

## Permissions

`giftcards.view` (list, editor, History), `giftcards.manage` (issue, edit, change status, send), `giftcards.delete` (archive, single or bulk). Normal permission sync grants them to the store-admin reference role without overwriting existing grants. Applying a card to an order additionally needs `orders.payments`.

## Verification

`GiftCardTest` (HTTP, validation, capabilities, lifecycle, database constraints, emails and their failure, redemption partial/full/sequential, currency, void orders, "status after payment", archiving, audit privacy), `GiftCardRulesTest` and `GiftCards.test.tsx` cover the feature. Run them with the order, payment, audit and localization suites.

## Not included

Minimum and maximum card amounts (a storefront purchase concern), expiry dates (CS-Cart cards never expire), free products, postal delivery, selling cards as products, refunds, and customer-facing redemption at a storefront checkout. The storefront must call the same server operations with verified customer context.
