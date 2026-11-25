---
title: Stripe Checkout
parent: Bundled add-ons
nav_order: 1
---

# Stripe Checkout

Customers pay on **Stripe's hosted payment page** with a card or another method Stripe offers them. Card data never
touches your store.

## Setting it up

1. **Add-ons**: install and enable **Stripe Checkout**.
2. **Payment methods → Stripe** (*Card payment (Stripe)*): choose the **Mode** (*Test* or *Live*) and paste your
   **Secret API key** from the Stripe Dashboard → Developers → API keys (`sk_test_…`, `sk_live_…` or a restricted
   `rk_…` key). The key must match the mode.
3. Create a **webhook** so Stripe tells the store about payments:
   - **Live store**: Stripe Dashboard → Developers → Webhooks → add the endpoint
     `https://your-store/payments/webhooks/stripe_checkout` with the events `checkout.session.completed`,
     `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed` and
     `checkout.session.expired`;
   - **Local testing**: with the Stripe CLI run
     `stripe listen --forward-to http://localhost:8000/payments/webhooks/stripe_checkout`.
4. Paste the endpoint's **Webhook signing secret** (`whsec_…`).
5. Click **Verify credentials**, then **Enable** the method.

Keys and secrets are stored encrypted and never shown again.

## How a payment works

1. The customer chooses Stripe at checkout (or opens a payment link you shared) and is sent to Stripe.
2. Stripe charges the **outstanding amount** of the order in the order's currency, shown as one line
   "Order {number} at {store}". Discounts, taxes and shipping are already included by the store.
3. When the customer returns, and when Stripe's webhook arrives, the store checks the payment with Stripe and records it
   **once**. The order becomes *Paid*, and the method's *Order status after payment* is applied.

## Going live

1. Activate your Stripe account and switch the method to **Live** with your live secret key.
2. Create the live webhook endpoint (HTTPS) and paste its live signing secret.
3. Verify the credentials again.

## Troubleshooting

| Problem | What to do |
| --- | --- |
| The customer paid but the order is unpaid | The webhook was not delivered. Open the order → Payment → check status (re-reads the payment at Stripe) |
| Stripe is not offered for an order | The method is disabled or not verified, or the order's currency or amount is not supported by Stripe |

## Not included

Subscriptions, saved cards, Stripe Elements and refunds from the store (make refunds in the Stripe Dashboard).
