---
title: Shipping & Taxes
parent: Administration guide
nav_order: 10
has_children: true
permalink: /admin/shipping-taxes/
---

# Shipping & Taxes

| Screen | Use it to |
| --- | --- |
| [Shipping methods](shipping-methods/) | Delivery options and their prices |
| [Taxes and rate areas](taxes/) | Taxes (for example VAT), their rates and the geographic areas they apply to |
| [Countries and regions](countries-regions/) | Where you ship, and the regions (for example Greek *nomoi*) customers choose |
| [Carriers](carriers/) | Carrier add-ons: settings, accounts, vouchers and pickup lists |

## Recommended setup order

1. **Countries**: tick the countries you ship to. For Greece, the operator can load all 51 regions with
   `php artisan cms:geography-greece <staff-user-id>`.
2. **Rate areas**: create the areas you need (for example *Greece*, *Greek islands*, *European Union*). Rate areas are
   used by both taxes and shipping rates.
3. **Taxes**: create your taxes (for example *VAT 24%*) with a rate per area, then assign them to products (or use
   **Assign to all products**).
4. **Tax calculation settings** in **Settings → General settings** (tax calculation tab): calculation method, whether
   prices are displayed with tax, and the default country used to estimate taxes before an address is known.
5. **Shipping methods**: create methods with rates per area and weight, or carrier methods.

{: .note }
> The platform does not ship any legal tax rates. Enter the rates that apply to your business; ask your accountant if
> unsure.
