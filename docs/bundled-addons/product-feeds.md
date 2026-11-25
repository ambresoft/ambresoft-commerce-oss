---
title: Facebook/Google XML feeds
parent: Bundled add-ons
nav_order: 4
---

# Facebook/Google XML product feeds

Creates product feeds for **Google Merchant Center** and **Meta (Facebook, Instagram) catalogs**, so your products can
appear in Google Shopping and in Facebook and Instagram shops and ads.

## Setting it up

1. **Add-ons**: install and enable **Facebook/Google XML**.
2. Grant *View Facebook/Google XML feeds* and *Manage Facebook/Google XML feeds* to the right roles.
3. Open the add-on's screen from its link on the **Add-ons** page and click **Add feed**.

## Feed settings

| Setting | Notes |
| --- | --- |
| **Name**, **Status** | Your own name; active or disabled |
| **Type** | *Google Merchant Center* or *Meta (Facebook) catalog*. Both use the same XML format; the type applies that channel's availability values and length limits |
| **Products** | *All products* or *Only selected categories* (subcategories included). Only products visitors can find on the storefront are included |
| **Use as ID** | Product ID or SKU. **Keep it unchanged after the first upload**: a new ID creates new products in Google and Meta |
| **Description** | Full or short description, a length limit (Google allows 5,000 characters, Meta 9,999) and what to use when a product has none |
| **Language** | The language of the texts |
| **Prices** | **Apply promotions** sends the guest sale price as `sale_price`; **Show list price** sends the list price as `price` and the selling price as `sale_price` |
| **Attributes** | How product data maps to the channel's fields |
| **Cron password** | Protects the generation address used by a scheduled task (at least 8 characters) |

## Generating and submitting

- Click **Generate now** (generation runs in the background; refresh after a moment).
- Each feed has a public address of the form `/storefront/product-feeds/{feed}.xml`: give it to Google Merchant Center or
  Meta Commerce Manager as a scheduled feed.
- The generation address (`/storefront/product-feeds/{feed}/generate`) can be called by an external scheduler,
  protected by the cron password.

Deleting a feed deletes its file; Google and Meta can no longer fetch it.
