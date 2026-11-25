---
title: Abandoned cart reminders
parent: Bundled add-ons
nav_order: 8
---

# Abandoned cart reminders

Emails **signed-up customers** who left products in their cart, after a delay and as many times as you choose. The email
lists the products in their cart and has a **Continue shopping** button.

## Setting it up

1. **Add-ons**: install and enable **Abandoned cart reminders**, then open its **Settings**:

   | Setting | Notes |
   | --- | --- |
   | **Send the first reminder after (hours)** | Hours since the customer last changed their cart |
   | **Reminders per abandoned cart** | The maximum number of reminders; a cart that changes starts again |
   | **Time between reminders (hours)** | Used only when more than one reminder is sent |

2. Restart the scheduler, which checks for abandoned carts every 15 minutes: `docker compose restart worker scheduler`.

## Who receives reminders

Only active customers with an account whose cart holds products and has not changed for the chosen time. Guests are not
emailed (the store does not know their address before checkout). Emails are sent in the customer's language, in the
store's email layout, from the sender set in **Settings → General settings → Emails**.

You can see current carts in **Catalog → Carts**.
