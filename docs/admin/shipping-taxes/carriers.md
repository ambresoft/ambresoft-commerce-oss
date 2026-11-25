---
title: Carriers
parent: Shipping & Taxes
grand_parent: Administration guide
nav_order: 4
---

# Carriers

**Shipping & Taxes → Carriers** (`/admin/shipping-carriers`)

Carriers (courier companies) are connected through **carrier add-ons**. A carrier add-on can price orders in real time,
create shipments (vouchers), print labels, track parcels and issue pickup lists. **No carrier is bundled with the
platform**: install a carrier add-on in **Add-ons** first. Without one, this screen has nothing to configure.

## Setting up a carrier

1. Install and enable the carrier's add-on in **Add-ons**.
2. Open **Shipping & Taxes → Carriers** and the carrier.
3. Fill in the carrier's **settings** (for example which order statuses to apply when a shipment is created, delivered
   or returned).
4. Add one or more **accounts** with your credentials from the carrier. Secret values are encrypted and never shown
   again. Use **Verify** to check an account with the carrier.
5. Create a shipping method with **Real-time carrier rates** (see [Shipping methods](../shipping-methods/)), or use the
   carrier only for vouchers.

## Shipments and pickup lists

Shipments are created from the order page (see [All orders](../../orders/orders/#shipments-carrier-add-ons)).
The carrier's **pickup lists** (manifests) are issued and printed from this screen. Vouchers that are on an issued
pickup list can no longer be deleted from the store.

Tracking is refreshed by staff from the order; the store never assumes a delivery the carrier has not reported.

## Permissions

*Manage carrier settings and credentials* (Super Admins by default). Staff with *Create and manage carrier shipments
and pickup lists* can also open this screen to work with pickup lists.
