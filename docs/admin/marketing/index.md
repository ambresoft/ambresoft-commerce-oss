---
title: Marketing
parent: Administration guide
nav_order: 7
has_children: true
permalink: /admin/marketing/
---

# Marketing

| Screen | Use it to |
| --- | --- |
| [Coupons](coupons/) | Discount codes customers enter in the cart |
| [Promotions](promotions/) | Automatic discounts and free shipping when an order meets conditions |
| [Banners](banners/) | Design banners for the storefront, with separate desktop, tablet and phone layouts |
| [Gift cards](gift-cards/) | Prepaid cards sent by email and accepted as payment |
| [Reels](reels/) | Short shoppable videos with linked products |
| [Stickers](stickers/) | Badges on product pictures, such as *New*, *-20%* or *Free shipping* |
| [Newsletters](newsletters/) | Mailing lists, subscribers, templates, campaigns and newsletters |
| [Blog](blog/) | Blogs and their posts |

**Prices and discounts at a glance**

| Tool | Customer action | Where to set it |
| --- | --- | --- |
| Sale price on a product | None, automatic | Product editor → **Sale** tab |
| Promotion | None, automatic | Marketing → Promotions |
| Coupon | Enters a code | Marketing → Coupons |
| Gift card | Enters a card code; it **pays** for the order instead of discounting it | Marketing → Gift cards |

## AI writing assistants

Banners, blog posts, newsletters and FAQs can be written with an AI assistant in every store language, as a chat inside
the editor: describe what you want, ask for changes, then review and save. Nothing goes live on its own. The assistant
needs an Anthropic API key in **Settings → General settings** (AI) and the matching permission (*Generate banners with
AI*, *Write blog posts with AI*, *Write newsletters with AI*, *Generate FAQ drafts with AI*). Every generation is listed
in **Logs → AI log**.

### What the assistant can and cannot do

- It only writes the draft of the screen you are on. It has no access to your server, files, code, database, settings
  or other records, and it cannot run commands or change anything: requests such as running scripts, deleting data or
  showing configuration are declined.
- It receives only what it needs: your messages, the editor's current content, your store's languages and name, and
  for FAQs the public contact details and the description you chose. API keys and server settings are never sent.
- Everything it writes is checked and cleaned by the server before it reaches the editor, and nothing is saved, sent or
  published until you save it yourself.
- Usage is limited per staff member (per hour) and for the whole store (per day); both limits are set in **Settings →
  General settings → AI settings**.
