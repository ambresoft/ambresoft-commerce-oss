---
title: Payment methods
parent: Administration guide
nav_order: 9
---

# Payment methods
{: .no_toc }

**Payment methods** (`/admin/payment-methods`)

1. TOC
{:toc}

---

## The methods

| Method | Kind | Provided by |
| --- | --- | --- |
| **Cash on delivery** | Offline: staff record the payment when the money is collected | Core. Enabled on a new store |
| **Bank deposit** | Offline: staff record the payment when the deposit arrives | Core. Disabled until you add your bank details |
| **Stripe Checkout** | Online: the customer pays on Stripe's page | The [Stripe Checkout add-on](../../bundled-addons/stripe-checkout/) |

Add-ons can add more methods. The list shows each method's provider, whether it is **Ready** or **Needs attention**,
and its status. Drag the grip (or use the arrow keys) to set the order in which checkout offers them.

## Configuring a method

Click **Configure**:

| Field | Notes |
| --- | --- |
| **Enabled** | Offered for new orders. A method can be enabled only when it is completely configured |
| **Name** and **Description shown to customers** | Per language. For bank deposit, write your bank name and IBAN in the description |
| **Image** | Optional logo (JPEG, PNG or WebP) |
| **Order status after payment** | Applied once, when an order becomes fully paid with this method (*Keep the current status* by default) |
| **Fee** | Optional amount added to orders paid with this method (for example +2 for cash on delivery). Its VAT follows the order's products. Coupons and promotions do not reduce it |
| **Maximum order subtotal** | Checkout does not offer the method above this subtotal (for example 500 for cash on delivery) |
| **Restrictions** | **Exclude physical products**, **Exclude digital products** (not both), and **Excluded categories** (a category includes all its subcategories). Checkout offers the method only when no product in the cart is excluded |
| **Provider settings** | Credentials of online methods. Secret values are stored encrypted and never shown again; leave a field empty to keep it |

Online methods also have **Verify credentials**: the method is offered only after the provider accepted the current
credentials. Changing a credential requires verifying again.

Disabling a method affects new orders only; existing orders keep their method. Once payments were recorded on an order,
its method can no longer be changed.

{: .note }
> Staff choosing a method on an order in the administration are not limited by the restrictions above.

Per-buyer restrictions (for example no cash on delivery for a specific email or phone) are set in the
[Blacklist](../customers/blacklist/).

## How payments are recorded

- **Offline methods** never mark an order paid by themselves. When the money arrives, open the order and **Record
  payment**.
- **Online methods** redirect the customer to the provider; the payment is verified on the server when they return
  (and through the provider's webhook), then recorded once. The order's payment status becomes *Paid* when the money
  received covers the total.
- A **customer payment link** from the order page lets a customer pay an outstanding amount online later.
- Refunds are made in the provider's own dashboard; they are not recorded in the store.

## Permission

*Manage payment methods* (`payment-methods.manage`). Recording payments on orders needs *Record payments and share
customer payment links*.
